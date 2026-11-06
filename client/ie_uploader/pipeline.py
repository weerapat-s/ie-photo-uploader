"""Watermark in a process pool, upload in threads, never more than max_pending in memory."""
from __future__ import annotations

import hashlib
import os
import tempfile
import threading
import time
from concurrent.futures import ProcessPoolExecutor, ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path

from . import watermark
from .state import State
from .uploader import AuthError, TransientError


@dataclass
class RunSettings:
    uploader: str
    sport: str
    position: str
    opacity: int
    colour: str
    logo: bytes


@dataclass
class Event:
    kind: str                 # progress | failed | paused | auth_error | finished
    done: int = 0
    total: int = 0
    message: str = ""
    path: str = ""


@dataclass
class Summary:
    total: int = 0
    uploaded: int = 0
    skipped: int = 0
    failed: list = field(default_factory=list)   # [(path, error)]
    auth_error: bool = False
    stopped: bool = False
    seconds: float = 0.0


def scan(folder) -> list[Path]:
    root = Path(folder)
    files = [p for p in root.rglob("*") if p.is_file() and p.suffix.lower() in watermark.FORMATS]
    return sorted(files, key=lambda p: (len(p.relative_to(root).parts), str(p.relative_to(root)).lower()))


def sha256_file(path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _work(path: str, done: frozenset, logo: bytes, position: str, opacity: int, colour: str):
    """Runs in a worker process. Returns (sha, temp_file_or_None)."""
    sha = sha256_file(path)
    if sha in done:
        return sha, None
    data, ext = watermark.apply(path, logo, position, opacity, colour)
    fd, tmp = tempfile.mkstemp(prefix="iephoto-", suffix=ext)
    with os.fdopen(fd, "wb") as f:
        f.write(data)
    return sha, tmp


class Pipeline:
    def __init__(self, client, state: State, settings: RunSettings, workers: int | None = None,
                 upload_threads: int = 3, max_pending: int = 12, on_event=lambda e: None,
                 pool_factory=ProcessPoolExecutor, pause_seconds: float = 60):
        self.client = client
        self.state = state
        self.s = settings
        self.workers = workers or max(1, (os.cpu_count() or 2) - 1)
        self.upload_threads = upload_threads
        self.max_pending = max_pending
        self.emit = on_event
        self.pool_factory = pool_factory
        self.pause_seconds = pause_seconds

    def run(self, files: list[Path], stop: threading.Event) -> Summary:
        summary = Summary(total=len(files))
        started = time.monotonic()
        lock = threading.Lock()
        slots = threading.Semaphore(self.max_pending)
        abort = threading.Event()
        done = self.state.done_set()

        def progress():
            n = summary.uploaded + summary.skipped + len(summary.failed)
            self.emit(Event("progress", done=n, total=summary.total))

        def upload_one(path: Path, sha: str, tmp: str):
            try:
                with open(tmp, "rb") as f:
                    data = f.read()
                while not (stop.is_set() or abort.is_set()):
                    try:
                        res = self.client.upload(data, path.name, sha, self.s.uploader, self.s.sport, self.s.position)
                        break
                    except TransientError as exc:
                        self.emit(Event("paused", message=f"เชื่อมต่อไม่ได้ ({exc}) จะลองใหม่อัตโนมัติ"))
                        stop.wait(self.pause_seconds)
                    except AuthError as exc:
                        abort.set()
                        with lock:
                            summary.auth_error = True
                        self.emit(Event("auth_error", message=str(exc)))
                        return
                else:
                    return
                with lock:
                    if res.ok:
                        self.state.mark(sha, res.name or "")
                        summary.uploaded += 1
                    else:
                        summary.failed.append((str(path), res.error or "ไม่ทราบสาเหตุ"))
                        self.emit(Event("failed", path=str(path), message=res.error or ""))
                progress()
            finally:
                try:
                    os.unlink(tmp)
                except OSError:
                    pass
                slots.release()

        with self.pool_factory(max_workers=self.workers) as pool, \
                ThreadPoolExecutor(max_workers=self.upload_threads) as uploads:
            pending_uploads = []

            def on_processed(path: Path, fut):
                try:
                    sha, tmp = fut.result()
                except Exception as exc:          # unreadable / unsupported image
                    with lock:
                        summary.failed.append((str(path), f"เปิดรูปไม่ได้: {exc}"))
                    self.emit(Event("failed", path=str(path), message=str(exc)))
                    slots.release()
                    progress()
                    return
                if tmp is None:
                    with lock:
                        summary.skipped += 1
                    slots.release()
                    progress()
                    return
                pending_uploads.append(uploads.submit(upload_one, path, sha, tmp))

            for path in files:
                while not slots.acquire(timeout=0.2):
                    if stop.is_set() or abort.is_set():
                        break
                else:
                    if stop.is_set() or abort.is_set():
                        slots.release()
                        break
                    fut = pool.submit(_work, str(path), done, self.s.logo, self.s.position, self.s.opacity, self.s.colour)
                    fut.add_done_callback(lambda f, p=path: on_processed(p, f))
                    continue
                break
            pool.shutdown(wait=True)
            for f in list(pending_uploads):
                f.result()

        summary.stopped = stop.is_set()
        summary.seconds = time.monotonic() - started
        self.emit(Event("finished", done=summary.uploaded, total=summary.total))
        return summary
