"""Talks to /apps/iephoto_watermark/desktop/* on the IE-PHOTO Nextcloud."""
from __future__ import annotations

import re
import time
from dataclasses import dataclass
from urllib.parse import parse_qs, urlsplit

import requests

from . import __version__

PUBLIC_URL = "https://nextcloud.ienas.site"
FAST_URL = "http://100.116.118.109:7580"   # Tailscale "ช่องทางด่วน"
RETRY_DELAYS = (5, 15, 45)
TRANSIENT_STATUS = {429, 500, 502, 503, 504, 520, 521, 522, 523, 524, 530}


class AuthError(Exception):
    """Code wrong, expired or revoked: stop the whole run."""


class TransientError(Exception):
    """Network or server down after all retries: pause and try later."""


@dataclass
class UploadResult:
    ok: bool
    name: str | None = None
    duplicate: bool = False
    error: str | None = None


def parse_credential(text: str) -> str:
    """What the user pasted -> the value sent to the server.

    A pasted album upload link (``https://.../apps/iephoto_watermark/?album=TOKEN``) becomes
    ``link:TOKEN``; a Nextcloud folder share link (``https://.../s/TOKEN``) becomes ``share:TOKEN``;
    anything else is treated as a per-event code and kept as typed."""
    text = (text or "").strip()
    if "album=" in text:
        try:
            query = parse_qs(urlsplit(text if "://" in text else "https://x/?" + text.split("?", 1)[-1]).query)
        except ValueError:
            query = {}
        token = (query.get("album") or [""])[0].strip()
        if re.fullmatch(r"[A-Za-z0-9]{8,64}", token):
            return "link:" + token
    m = re.match(r"(?i)^https?://[^/?#\s]+/(?:index\.php/)?s/([A-Za-z0-9]{8,32})(?:[/?#]|$)", text)
    if m:
        return "share:" + m.group(1)
    return text


# Reasons the server gives (401 body) -> what to tell the user.
AUTH_MESSAGES = {
    "share_password": "ลิงก์แชร์นี้มีรหัสผ่าน โปรแกรมใช้ไม่ได้ ให้แอดมินสร้างลิงก์ที่ไม่มีรหัสผ่าน",
    "share_read_only": "ลิงก์แชร์นี้ดูได้อย่างเดียว ส่งรูปไม่ได้ ให้แอดมินตั้งเป็น \"แก้ไขได้\" หรือ \"อัปโหลดอย่างเดียว\"",
    "share_not_folder": "ลิงก์แชร์นี้ไม่ใช่โฟลเดอร์ ใช้ส่งรูปไม่ได้",
}


def explain_bad_link(text: str) -> str | None:
    """A pasted web link that is not an album upload link: say why, so the user asks for the right thing.

    Returns None for anything that should just be sent to the server (a code, or a good link)."""
    text = (text or "").strip()
    if parse_credential(text).split(":", 1)[0] in ("link", "share") or not re.match(r"(?i)^(https?://|www\.)", text):
        return None
    return "ลิงก์นี้ใช้ไม่ได้ ให้วางลิงก์แชร์โฟลเดอร์ของ Nextcloud (มี /s/ ในลิงก์) ลิงก์อัปโหลดของงาน หรือรหัสงาน"


def pick_base_url(probe=requests.get) -> str:
    try:
        if probe(FAST_URL + "/status.php", timeout=1.5).status_code == 200:
            return FAST_URL
    except requests.RequestException:
        pass
    return PUBLIC_URL


def _error_text(resp) -> str:
    try:
        return str(resp.json().get("error") or resp.status_code)
    except ValueError:
        return f"HTTP {resp.status_code}"


class Client:
    def __init__(self, base_url: str, code: str, session: requests.Session | None = None, sleep=time.sleep):
        self.base = base_url.rstrip("/") + "/apps/iephoto_watermark/desktop"
        self.code = code
        self.session = session or requests.Session()
        self.session.headers.update({"X-IEPhoto-Code": code, "User-Agent": f"IE-PHOTO-Uploader/{__version__}"})
        self.sleep = sleep

    def _send(self, method: str, url: str, **kw):
        last = None
        for attempt in range(len(RETRY_DELAYS) + 1):
            try:
                resp = self.session.request(method, url, timeout=(10, 180), **kw)
            except requests.RequestException as exc:
                last = str(exc)
            else:
                if resp.status_code == 401:
                    raise AuthError(_error_text(resp))
                if resp.status_code not in TRANSIENT_STATUS:
                    return resp
                last = f"HTTP {resp.status_code}"
            if attempt < len(RETRY_DELAYS):
                self.sleep(RETRY_DELAYS[attempt])
            # Rewind file-like bodies before retrying.
            for f in (kw.get("files") or {}).values():
                if hasattr(f[1], "seek"):
                    f[1].seek(0)
        raise TransientError(last or "unreachable")

    def config(self) -> dict:
        resp = self._send("GET", self.base + "/config")
        resp.raise_for_status()
        return resp.json()

    def logo(self, url_path: str) -> bytes:
        root = self.base.split("/apps/")[0]
        resp = self._send("GET", root + url_path)
        resp.raise_for_status()
        return resp.content

    def upload(self, data: bytes, original_name: str, sha256: str, uploader: str, sport: str, position: str) -> UploadResult:
        import io
        resp = self._send(
            "POST",
            self.base + "/upload",
            data={"sha256": sha256, "uploader": uploader, "sport": sport, "position": position, "originalName": original_name},
            files={"photo": (original_name, io.BytesIO(data), "application/octet-stream")},
        )
        if resp.status_code == 200:
            try:
                body = resp.json()
                name = body.get("name")
            except (ValueError, AttributeError):
                name = None
            if not name or not isinstance(name, str):
                return UploadResult(ok=False, error="ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง")
            return UploadResult(ok=True, name=name, duplicate=bool(body.get("duplicate")))
        return UploadResult(ok=False, error=_error_text(resp))
