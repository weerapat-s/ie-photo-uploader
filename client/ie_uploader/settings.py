"""Per-user settings. The desktop code goes to the OS keyring, never to settings.json."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import keyring

from .watermark import POSITIONS

SERVICE = "IE-PHOTO Uploader"


def data_dir() -> Path:
    if sys.platform == "win32":
        base = Path(os.environ.get("APPDATA", Path.home()))
    elif sys.platform == "darwin":
        base = Path.home() / "Library" / "Application Support"
    else:
        base = Path(os.environ.get("XDG_DATA_HOME", Path.home() / ".local" / "share"))
    path = base / SERVICE
    path.mkdir(parents=True, exist_ok=True)
    return path


class Settings:
    def __init__(self, directory: Path | None = None):
        self.dir = Path(directory) if directory else data_dir()
        self.file = self.dir / "settings.json"
        try:
            raw = json.loads(self.file.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            raw = {}
        if not isinstance(raw, dict):
            raw = {}
        uploader = raw.get("uploader", "")
        self.uploader = uploader if isinstance(uploader, str) else ""
        position = raw.get("position")
        self.position = position if isinstance(position, str) and position in POSITIONS else "top-left"
        try:
            opacity = raw.get("opacity", 100)
            if isinstance(opacity, bool):
                raise ValueError
            self.opacity = max(10, min(100, int(opacity)))
        except (TypeError, ValueError, OverflowError):
            self.opacity = 100
        self.code = keyring.get_password(SERVICE, "desktop-code") or ""

    def save(self, code: str, uploader: str, position: str, opacity: int) -> None:
        self.code, self.uploader, self.position, self.opacity = code.strip(), uploader.strip(), position, int(opacity)
        keyring.set_password(SERVICE, "desktop-code", self.code)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.file.write_text(json.dumps(
            {"uploader": self.uploader, "position": self.position, "opacity": self.opacity},
            ensure_ascii=False), encoding="utf-8")
