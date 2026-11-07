"""Checks GitHub for a newer release. Never installs anything: it only tells the user and opens the download."""
from __future__ import annotations

import hashlib
import os
import platform
import re
import shlex
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path

import requests

LATEST_API = "https://api.github.com/repos/weerapat-s/ie-photo-uploader/releases/latest"


@dataclass
class Update:
    version: str
    page: str
    download: str   # direct installer link for this machine, or the release page when none matches
    sha256: str = ""   # GitHub's digest of that installer; empty when unknown


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
        asset = next((a for a in data.get("assets") or [] if a.get("name") == want and a.get("browser_download_url")), None)
        if not page.startswith("https://github.com/"):
            return None
        digest = str((asset or {}).get("digest") or "")
        sha = digest.split(":", 1)[1].lower() if digest.startswith("sha256:") else ""
        return Update(tag.lstrip("v"), page, str(asset["browser_download_url"]) if asset else page, sha)
    except (requests.RequestException, ValueError, AttributeError, TypeError):
        return None


class UpdateError(Exception):
    pass


def can_self_update() -> bool:
    """Only the installed (frozen) app on Windows/macOS can replace itself."""
    return bool(getattr(sys, "frozen", False)) and sys.platform in ("win32", "darwin")


def download(upd: Update, progress=lambda done, total: None, get=requests.get, dest_dir: Path | None = None) -> Path:
    """Download the installer and check it against GitHub's published sha256. No digest, no install."""
    if not upd.sha256 or not upd.download.startswith("https://github.com/"):
        raise UpdateError("ตรวจความถูกต้องของไฟล์ไม่ได้")
    dest = Path(dest_dir or tempfile.mkdtemp(prefix="ie-uploader-update-")) / Path(upd.download).name
    h = hashlib.sha256()
    try:
        with get(upd.download, stream=True, timeout=(10, 60)) as resp:
            if resp.status_code != 200:
                raise UpdateError(f"ดาวน์โหลดไม่สำเร็จ (HTTP {resp.status_code})")
            total = int(resp.headers.get("Content-Length") or 0)
            done = 0
            with open(dest, "wb") as fh:
                for chunk in resp.iter_content(1 << 16):
                    fh.write(chunk)
                    h.update(chunk)
                    done += len(chunk)
                    progress(done, total)
    except (requests.RequestException, OSError) as exc:
        raise UpdateError(f"ดาวน์โหลดไม่สำเร็จ: {exc}") from exc
    if h.hexdigest() != upd.sha256:
        dest.unlink(missing_ok=True)
        raise UpdateError("ไฟล์ที่โหลดมาไม่ตรงกับต้นฉบับ ไม่ติดตั้งให้")
    return dest


def _mac_app_bundle() -> Path | None:
    exe = Path(sys.executable).resolve()
    for parent in exe.parents:
        if parent.suffix == ".app":
            return parent
    return None


def write_install_script(installer: Path, exe: Path, plat: str | None = None, app_bundle: Path | None = None) -> Path:
    """The tiny cmd/sh script that waits for this app to exit, installs the update silently, then reopens the app."""
    plat = plat or sys.platform
    folder = installer.parent
    if plat == "win32":
        script = folder / "update.cmd"
        lines = [
            "@echo off",
            "ping -n 3 127.0.0.1 >nul",                       # let the app finish closing
            f'"{installer}" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /CLOSEAPPLICATIONS',
            f'start "" "{exe}"',
        ]
        script.write_bytes(("\r\n".join(lines) + "\r\n").encode("utf-8"))
        return script
    if app_bundle is None:
        raise UpdateError("หาตำแหน่งแอปไม่เจอ")
    script = folder / "update.sh"
    q = shlex.quote
    lines = [
        "#!/bin/sh",
        "sleep 2",
        "MNT=$(mktemp -d /tmp/ie-uploader-mnt.XXXXXX)",
        f'hdiutil attach {q(str(installer))} -nobrowse -quiet -mountpoint "$MNT" || exit 1',
        'SRC=$(ls -d "$MNT"/*.app | head -n 1)',
        f'ditto "$SRC" {q(str(app_bundle))}',
        f"xattr -dr com.apple.quarantine {q(str(app_bundle))} 2>/dev/null",
        'hdiutil detach "$MNT" -quiet',
        f"open {q(str(app_bundle))}",
    ]
    script.write_bytes(("\n".join(lines) + "\n").encode("utf-8"))
    return script


def launch_install(installer: Path) -> None:
    """Start the update script detached. The caller must exit the app right after."""
    exe = Path(sys.executable)
    if sys.platform == "win32":
        script = write_install_script(installer, exe)
        flags = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP | subprocess.CREATE_NO_WINDOW
        subprocess.Popen(["cmd.exe", "/c", str(script)], creationflags=flags, close_fds=True,
                         stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return
    bundle = _mac_app_bundle()
    if bundle is None or not os.access(bundle.parent, os.W_OK):
        raise UpdateError("ไม่มีสิทธิ์เขียนทับแอป ติดตั้งเองจากไฟล์ที่โหลดมา")
    script = write_install_script(installer, exe, "darwin", bundle)
    subprocess.Popen(["/bin/sh", str(script)], start_new_session=True, stdin=subprocess.DEVNULL,
                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
