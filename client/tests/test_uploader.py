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


@responses.activate
def test_chunked_encoding_error_is_retried():
    responses.post(UPL, body=requests.exceptions.ChunkedEncodingError("cut"))
    responses.post(UPL, json={"name": "n.jpg"})
    slept = []
    r = up.Client(BASE, "c", sleep=slept.append).upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")
    assert r.ok and slept == [5]


@responses.activate
def test_upload_200_with_html_body_is_failure():
    responses.post(UPL, body="<html>proxy page</html>", status=200, content_type="text/html")
    r = client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")
    assert not r.ok and r.error == "ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง"


@responses.activate
def test_upload_200_without_name_is_failure():
    responses.post(UPL, json={"duplicate": False})
    assert not client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left").ok


@responses.activate
def test_upload_403_is_per_file_failure_not_auth_error():
    responses.post(UPL, status=403, body="<html>WAF</html>")
    r = client().upload(b"x", "a.jpg", "f" * 64, "a", "s", "top-left")
    assert not r.ok and r.error == "HTTP 403"


@responses.activate
def test_config_403_raises_http_error_not_auth_error():
    responses.get(CFG, status=403)
    with pytest.raises(requests.HTTPError):
        client().config()


def test_parse_credential_link_and_code():
    tok = "0123456789abcdef01234567"
    link = f"https://nextcloud.ienas.site/apps/iephoto_watermark/?album={tok}"
    assert up.parse_credential(link) == f"link:{tok}"
    assert up.parse_credential(f"  {link}  ") == f"link:{tok}"
    assert up.parse_credential(f"{link}&x=1") == f"link:{tok}"
    assert up.parse_credential(f"?album={tok}") == f"link:{tok}"
    # a bad token in a link is left alone so the server rejects it (401), never mis-parsed
    assert up.parse_credential("https://x/?album=../etc") == "https://x/?album=../etc"
    assert up.parse_credential("AAAAA-BBBBB-CCCCC-DDDDD") == "AAAAA-BBBBB-CCCCC-DDDDD"
    assert up.parse_credential("") == ""


def test_explain_bad_link():
    tok = "0123456789abcdef01234567"
    assert up.explain_bad_link("") is None
    assert up.explain_bad_link("AAAAA-BBBBB-CCCCC-DDDDD") is None                       # a code goes to the server
    assert up.explain_bad_link(f"https://nextcloud.ienas.site/apps/iephoto_watermark/?album={tok}") is None
    share = up.explain_bad_link("https://nextcloud.ienas.site/s/9H8WTJ2kXnGdb2G")
    assert share and "ลิงก์แชร์" in share and "?album=" in share
    other = up.explain_bad_link("https://example.com/foo")
    assert other and "ไม่ใช่ลิงก์อัปโหลด" in other
