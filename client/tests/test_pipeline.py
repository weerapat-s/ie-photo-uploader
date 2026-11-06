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
