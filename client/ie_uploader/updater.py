"""Checks GitHub for a newer release. Never installs anything: it only tells the user and opens the download."""
from __future__ import annotations

import platform
import re
import sys
from dataclasses import dataclass

import requests

LATEST_API = "https://api.github.com/repos/weerapat-s/ie-photo-uploader/releases/latest"


@dataclass
class Update:
    version: str
    page: str
    download: str   # direct installer link for this machine, or the release page when none matches


def _parts(version: str) -> tuple[int, ...] | None:
    m = re.fullmatch(r"v?(\d+(?:\.\d+){0,3})", version.strip())
    return tuple(int(p) for p in m.group(1).split(".")) if m else None


def is_newer(latest: str, current: str) -> bool:
    a, b = _parts(latest), _parts(current)
    return a is not None and b is not None and a > b


def installer_name(plat: str | None = None, machine: str | None = None) -> str:
    plat = plat or sys.platform
    machine = (machine or platform.machine()).lower()
    if plat == "win32":
        return "IE-PHOTO-Uploader-Setup.exe"
    if plat == "darwin":
        return "IE-PHOTO-Uploader-arm64.dmg" if machine in ("arm64", "aarch64") else "IE-PHOTO-Uploader-intel.dmg"
    return ""


def check(current: str, get=requests.get, plat: str | None = None, machine: str | None = None) -> Update | None:
    """The newer release, or None when up to date or anything goes wrong (an update check must never get in the way)."""
    try:
        resp = get(LATEST_API, timeout=5, headers={"Accept": "application/vnd.github+json"})
        if resp.status_code != 200:
            return None
        data = resp.json()
        tag = str(data.get("tag_name") or "")
        if not is_newer(tag, current):
            return None
        page = str(data.get("html_url") or "")
        want = installer_name(plat, machine)
        download = next((a.get("browser_download_url") for a in data.get("assets") or []
                         if a.get("name") == want and a.get("browser_download_url")), page)
        if not page.startswith("https://github.com/"):
            return None
        return Update(tag.lstrip("v"), page, str(download))
    except (requests.RequestException, ValueError, AttributeError, TypeError):
        return None
