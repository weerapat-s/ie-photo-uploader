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
