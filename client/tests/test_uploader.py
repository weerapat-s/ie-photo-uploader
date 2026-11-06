import pytest
import requests
import responses
from responses import matchers
from ie_uploader import uploader as up

BASE = "https://example.test"
CFG = BASE + "/apps/iephoto_watermark/desktop/config"
UPL = BASE + "/apps/iephoto_watermark/desktop/upload"


def client(**kw):
    return up.Client(BASE, "AAAAA-BBBBB-CCCCC-DDDDD", sleep=lambda s: None, **kw)


@responses.activate
def test_config_sends_code_header():
    responses.get(CFG, json={"label": "x"}, match=[matchers.header_matcher({"X-IEPhoto-Code": "AAAAA-BBBBB-CCCCC-DDDDD"})])
    assert client().config() == {"label": "x"}


@responses.activate
def test_config_401_raises_auth_error():
    responses.get(CFG, status=401, json={"error": "invalid_code"})
    with pytest.raises(up.AuthError):
        client().config()


@responses.activate
def test_upload_ok():
    responses.post(UPL, json={"name": "IEPHOTO@KMTIL_0007.jpg", "duplicate": False})
    r = client().upload(b"jpg", "a.jpg", "f" * 64, "เอ", "ฟุตบอล", "top-left")
    assert r.ok and r.name == "IEPHOTO@KMTIL_0007.jpg" and not r.duplicate


@responses.activate
def test_upload_retries_then_succeeds():
    responses.post(UPL, status=503)
    responses.post(UPL, body=requests.ConnectionError("boom"))
    responses.post(UPL, json={"name": "n.jpg", "duplicate": True})
    slept = []
    c = up.Client(BASE, "c", sleep=slept.append)
    r = c.upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")
    assert r.ok and r.duplicate and slept == [5, 15]


@responses.activate
def test_upload_gives_up_with_transient_error():
    for _ in range(4):
        responses.post(UPL, status=530)
    with pytest.raises(up.TransientError):
        client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")


@responses.activate
def test_upload_401_is_auth_error():
    responses.post(UPL, status=401, json={"error": "invalid_code"})
    with pytest.raises(up.AuthError):
        client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")


@responses.activate
def test_upload_422_is_per_file_failure():
    responses.post(UPL, status=422, json={"error": "ไฟล์เสีย"})
    r = client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")
    assert not r.ok and r.error == "ไฟล์เสีย"


def test_pick_base_url_prefers_fast_when_reachable():
    class R:
        status_code = 200
    assert up.pick_base_url(probe=lambda url, timeout: R()) == up.FAST_URL
    def boom(url, timeout):
        raise requests.ConnectionError()
    assert up.pick_base_url(probe=boom) == up.PUBLIC_URL
