import shlex
import requests

from ie_uploader import updater as u


class R:
    def __init__(self, data, status=200):
        self._d, self.status_code = data, status

    def json(self):
        return self._d


REL = {"tag_name": "v0.2.0", "html_url": "https://github.com/weerapat-s/ie-photo-uploader/releases/tag/v0.2.0",
       "assets": [{"name": "IE-PHOTO-Uploader-Setup.exe", "browser_download_url": "https://github.com/x/Setup.exe"},
                  {"name": "IE-PHOTO-Uploader-arm64.dmg", "browser_download_url": "https://github.com/x/arm.dmg"}]}


def test_is_newer():
    assert u.is_newer("v0.1.10", "0.1.9")
    assert u.is_newer("0.2.0", "0.1.4")
    assert not u.is_newer("0.1.4", "0.1.4")
    assert not u.is_newer("0.1.3", "0.1.4")
    assert not u.is_newer("nightly", "0.1.4")


def test_check_picks_installer_for_platform():
    got = u.check("0.1.4", get=lambda *a, **k: R(REL), plat="win32")
    assert got.version == "0.2.0" and got.download.endswith("Setup.exe")
    mac = u.check("0.1.4", get=lambda *a, **k: R(REL), plat="darwin", machine="arm64")
    assert mac.download.endswith("arm.dmg")
    intel = u.check("0.1.4", get=lambda *a, **k: R(REL), plat="darwin", machine="x86_64")
    assert intel.download == REL["html_url"]    # no intel asset in this fake release: falls back to the page


def test_check_quiet_on_uptodate_and_errors():
    assert u.check("0.2.0", get=lambda *a, **k: R(REL)) is None
    assert u.check("0.1.4", get=lambda *a, **k: R({}, 404)) is None
    assert u.check("0.1.4", get=lambda *a, **k: R({"tag_name": "v9", "html_url": "https://evil.example/x"})) is None

    def boom(*a, **k):
        raise requests.ConnectionError("offline")
    assert u.check("0.1.4", get=boom) is None


import hashlib
from pathlib import Path


class Stream:
    def __init__(self, body, status=200):
        self.body, self.status_code, self.headers = body, status, {"Content-Length": str(len(body))}

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def iter_content(self, n):
        for i in range(0, len(self.body), n):
            yield self.body[i:i + n]


def _upd(body, good=True):
    sha = hashlib.sha256(body).hexdigest() if good else "0" * 64
    return u.Update("0.2.0", "https://github.com/x/r", "https://github.com/x/r/releases/download/v0.2.0/Setup.exe", sha)


def test_download_verifies_sha(tmp_path):
    body = b"x" * 200_000
    seen = []
    p = u.download(_upd(body), lambda d, t: seen.append((d, t)), get=lambda *a, **k: Stream(body), dest_dir=tmp_path)
    assert p.read_bytes() == body and seen[-1] == (len(body), len(body))


def test_download_refuses_bad_hash_missing_hash_and_foreign_host(tmp_path):
    body = b"payload"
    with __import__("pytest").raises(u.UpdateError):
        u.download(_upd(body, good=False), get=lambda *a, **k: Stream(body), dest_dir=tmp_path)
    assert not list(tmp_path.iterdir())                      # the bad file is removed
    nohash = u.Update("0.2.0", "https://github.com/x", "https://github.com/x/Setup.exe", "")
    with __import__("pytest").raises(u.UpdateError):
        u.download(nohash, get=lambda *a, **k: Stream(body), dest_dir=tmp_path)
    evil = u.Update("0.2.0", "https://github.com/x", "https://evil.example/Setup.exe", hashlib.sha256(body).hexdigest())
    with __import__("pytest").raises(u.UpdateError):
        u.download(evil, get=lambda *a, **k: Stream(body), dest_dir=tmp_path)


def test_install_scripts(tmp_path):
    inst = tmp_path / "IE-PHOTO-Uploader-Setup.exe"
    inst.write_bytes(b"x")
    exe = Path(r"C:\Users\me\AppData\Local\Programs\IE-PHOTO Uploader\IE-PHOTO Uploader.exe")
    s = u.write_install_script(inst, exe, "win32").read_bytes().decode()
    assert "/VERYSILENT" in s and "/CLOSEAPPLICATIONS" in s and f'start "" "{exe}"' in s and "\r\n" in s
    dmg = tmp_path / "x.dmg"
    sh = u.write_install_script(dmg, exe, "darwin", Path("/Applications/IE-PHOTO Uploader.app")).read_text()
    assert sh.startswith("#!/bin/sh") and "hdiutil attach" in sh and shlex.quote(str(Path('/Applications/IE-PHOTO Uploader.app'))) in sh


def test_check_carries_digest():
    rel = dict(REL, assets=[{"name": "IE-PHOTO-Uploader-Setup.exe", "browser_download_url": "https://github.com/x/Setup.exe",
                             "digest": "sha256:ABCDEF"}])
    assert u.check("0.1.4", get=lambda *a, **k: R(rel), plat="win32").sha256 == "abcdef"
