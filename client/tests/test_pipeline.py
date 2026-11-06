import threading
from concurrent.futures import ThreadPoolExecutor
from ie_uploader import pipeline as pl
from ie_uploader.state import State
from ie_uploader.uploader import AuthError, TransientError, UploadResult
from tests.conftest import make_jpeg


class FakeClient:
    def __init__(self, script=None):
        self.calls = []
        self.script = list(script or [])
        self.lock = threading.Lock()

    def upload(self, data, name, sha, uploader, sport, position):
        with self.lock:
            self.calls.append(name)
            if self.script:
                step = self.script.pop(0)
                if isinstance(step, Exception):
                    raise step
                return step
        return UploadResult(ok=True, name=f"IEPHOTO@KMTIL_{len(self.calls):04d}.jpg")


def run(tmp_path, client, logo_bytes, n=5, stop=None, events=None):
    folder = tmp_path / "in"
    folder.mkdir(exist_ok=True)
    for i in range(n):
        if not (folder / f"{i}.jpg").exists():
            make_jpeg(folder / f"{i}.jpg", colour=(i * 40 % 255, 10, 10))
    state = State(tmp_path / "state.json")
    settings = pl.RunSettings(uploader="เอ", sport="ฟุตบอล", position="top-left", opacity=100, colour="black", logo=logo_bytes)
    p = pl.Pipeline(client, state, settings, workers=2, pool_factory=ThreadPoolExecutor,
                    on_event=(events.append if events is not None else (lambda e: None)), pause_seconds=0)
    return p.run(pl.scan(folder), stop or threading.Event()), state


def test_scan_finds_images_recursively_sorted(tmp_path):
    (tmp_path / "b").mkdir()
    for name in ["b/2.JPG", "1.jpg", "x.txt", "3.png"]:
        (tmp_path / name).write_bytes(b"x")
    assert [p.name for p in pl.scan(tmp_path)] == ["1.jpg", "3.png", "2.JPG"]


def test_all_files_uploaded_once(tmp_path, logo_bytes):
    c = FakeClient()
    summary, state = run(tmp_path, c, logo_bytes)
    assert summary.uploaded == 5 and summary.failed == [] and sorted(c.calls) == [f"{i}.jpg" for i in range(5)]
    assert len(state.done_set()) == 5


def test_resume_skips_already_uploaded(tmp_path, logo_bytes):
    run(tmp_path, FakeClient(), logo_bytes)
    c2 = FakeClient()
    summary, _ = run(tmp_path, c2, logo_bytes)
    assert c2.calls == [] and summary.skipped == 5


def test_per_file_failure_is_reported(tmp_path, logo_bytes):
    c = FakeClient([UploadResult(ok=False, error="ไฟล์เสีย")])
    summary, _ = run(tmp_path, c, logo_bytes)
    assert summary.uploaded == 4 and len(summary.failed) == 1 and summary.failed[0][1] == "ไฟล์เสีย"


def test_auth_error_stops_everything(tmp_path, logo_bytes):
    events = []
    c = FakeClient([AuthError("invalid_code")] * 10)
    summary, _ = run(tmp_path, c, logo_bytes, events=events)
    assert summary.auth_error and summary.uploaded == 0
    assert any(e.kind == "auth_error" for e in events)


def test_transient_error_pauses_then_continues(tmp_path, logo_bytes):
    events = []
    c = FakeClient([TransientError("down")])
    summary, _ = run(tmp_path, c, logo_bytes, events=events)
    assert summary.uploaded == 5
    assert any(e.kind == "paused" for e in events)


def test_stop_event_halts_early(tmp_path, logo_bytes):
    stop = threading.Event()
    stop.set()
    summary, _ = run(tmp_path, FakeClient(), logo_bytes, stop=stop)
    assert summary.uploaded == 0 and summary.stopped


def test_never_more_than_max_pending_processed_images(tmp_path, logo_bytes, monkeypatch):
    import tempfile
    import time
    tmpdir = tmp_path / "tmpfiles"
    tmpdir.mkdir()
    monkeypatch.setattr(tempfile, "tempdir", str(tmpdir))

    release = threading.Event()
    started = []

    class BlockingClient:
        def upload(self, data, name, sha, uploader, sport, position):
            started.append(name)
            assert release.wait(30)
            return UploadResult(ok=True, name="x.jpg")

    folder = tmp_path / "in"
    folder.mkdir()
    for i in range(6):
        make_jpeg(folder / f"{i}.jpg", colour=(i * 40, 10, 10))
    state = State(tmp_path / "state.json")
    settings = pl.RunSettings(uploader="a", sport="b", position="top-left", opacity=100, colour="black", logo=logo_bytes)
    p = pl.Pipeline(BlockingClient(), state, settings, workers=3, upload_threads=3, max_pending=2,
                    pool_factory=ThreadPoolExecutor, pause_seconds=0)
    result = {}
    t = threading.Thread(target=lambda: result.setdefault("s", p.run(pl.scan(folder), threading.Event())))
    t.start()
    peak = 0
    deadline = time.monotonic() + 20
    while len(started) < 2 and time.monotonic() < deadline:
        time.sleep(0.01)
    assert len(started) == 2
    for _ in range(50):                      # give the pipeline every chance to over-produce
        peak = max(peak, len(list(tmpdir.iterdir())))
        time.sleep(0.01)
    assert peak == 2
    release.set()
    while t.is_alive():
        peak = max(peak, len(list(tmpdir.iterdir())))
        time.sleep(0.005)
    t.join()
    assert peak <= 2
    assert result["s"].uploaded == 6
    assert list(tmpdir.iterdir()) == []


def test_real_process_pool(tmp_path, logo_bytes):
    folder = tmp_path / "in"
    folder.mkdir()
    for i in range(3):
        make_jpeg(folder / f"{i}.jpg", size=(200, 150), colour=(i * 60, 10, 10))
    c = FakeClient()
    state = State(tmp_path / "state.json")
    settings = pl.RunSettings(uploader="a", sport="b", position="center", opacity=80, colour="white", logo=logo_bytes)
    p = pl.Pipeline(c, state, settings, workers=2, pause_seconds=0)
    summary = p.run(pl.scan(folder), threading.Event())
    assert summary.uploaded == 3 and summary.failed == []
    assert sorted(c.calls) == ["0.jpg", "1.jpg", "2.jpg"]


def _identical_folder(tmp_path, dups=3, others=2):
    folder = tmp_path / "in"
    folder.mkdir(exist_ok=True)
    for i in range(dups):
        make_jpeg(folder / f"dup{i}.jpg", colour=(5, 5, 5))
    for i in range(others):
        make_jpeg(folder / f"other{i}.jpg", colour=(100 + i * 50, 20, 20))
    return folder


def _pipe(tmp_path, client, logo_bytes, **kw):
    state = State(tmp_path / "state.json")
    settings = pl.RunSettings(uploader="a", sport="b", position="top-left", opacity=100, colour="black", logo=logo_bytes)
    return pl.Pipeline(client, state, settings, workers=2, pool_factory=ThreadPoolExecutor, pause_seconds=0, **kw)


def test_in_run_duplicates_uploaded_once(tmp_path, logo_bytes):
    folder = _identical_folder(tmp_path)
    c = FakeClient()
    summary = _pipe(tmp_path, c, logo_bytes).run(pl.scan(folder), threading.Event())
    assert len(c.calls) == 3 and summary.uploaded == 3 and summary.skipped == 2


def test_worker_exception_is_recorded_and_run_finishes(tmp_path, logo_bytes):
    events = []
    c = FakeClient([RuntimeError("boom")])
    folder = _identical_folder(tmp_path, dups=0, others=4)
    summary = _pipe(tmp_path, c, logo_bytes, on_event=events.append).run(pl.scan(folder), threading.Event())
    assert summary.uploaded == 3 and len(summary.failed) == 1 and "boom" in summary.failed[0][1]
    assert events[-1].kind == "finished"


def test_repeated_transient_errors_give_up_per_file(tmp_path, logo_bytes):
    class Down:
        def upload(self, *a):
            raise TransientError("503")
    folder = _identical_folder(tmp_path, dups=0, others=3)
    events = []
    summary = _pipe(tmp_path, Down(), logo_bytes, on_event=events.append).run(pl.scan(folder), threading.Event())
    assert summary.uploaded == 0 and len(summary.failed) == 3
    assert all(m == "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ลองใหม่ภายหลัง" for _, m in summary.failed)
    assert sum(e.kind == "paused" for e in events) == 3 * pl.MAX_PAUSES


def test_pause_is_interrupted_by_stop(tmp_path, logo_bytes):
    class Down:
        def upload(self, *a):
            raise TransientError("503")
    folder = _identical_folder(tmp_path, dups=0, others=1)
    stop = threading.Event()
    p = _pipe(tmp_path, Down(), logo_bytes, on_event=lambda e: stop.set() if e.kind == "paused" else None)
    p.pause_seconds = 30
    import time
    t0 = time.monotonic()
    summary = p.run(pl.scan(folder), stop)
    assert time.monotonic() - t0 < 5 and summary.stopped


def test_broken_pool_fails_remaining_files_and_finishes(tmp_path, logo_bytes):
    from concurrent.futures.process import BrokenProcessPool

    class DeadPool:
        def __init__(self, max_workers):
            pass
        def __enter__(self):
            return self
        def __exit__(self, *a):
            return False
        def submit(self, *a, **k):
            raise BrokenProcessPool("dead")
        def shutdown(self, wait=True):
            pass
    folder = _identical_folder(tmp_path, dups=0, others=3)
    events = []
    p = _pipe(tmp_path, FakeClient(), logo_bytes, on_event=events.append)
    p.pool_factory = DeadPool
    summary = p.run(pl.scan(folder), threading.Event())
    assert len(summary.failed) == 3 and summary.uploaded == 0
    assert "หน่วยความจำ" in summary.failed[0][1]
    assert events[-1].kind == "finished"
