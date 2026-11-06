"""Watermarking that mirrors UploadService::watermark() on the server (Imagick)."""
from __future__ import annotations

import math

POSITIONS = (
    "top-left", "top-center", "top-right",
    "middle-left", "center", "middle-right",
    "bottom-left", "bottom-center", "bottom-right",
)
WIDTH_RATIO = 0.07
MARGIN_RATIO = 0.02
JPEG_QUALITY = 96
FORMATS = {".jpg": "JPEG", ".jpeg": "JPEG", ".png": "PNG", ".webp": "WEBP"}


def php_round(value: float) -> int:
    """PHP round(): halves go away from zero (Python's round() is banker's rounding)."""
    if value >= 0:
        return int(math.floor(value + 0.5))
    return -int(math.floor(-value + 0.5))


def logo_box(img_w: int, img_h: int, logo_w: int, logo_h: int, position: str) -> tuple[int, int]:
    if position not in POSITIONS:
        raise ValueError(f"unknown watermark position: {position}")
    margin = php_round(min(img_w, img_h) * MARGIN_RATIO)
    if position.endswith("-left"):
        x = margin
    elif position.endswith("-right"):
        x = max(0, img_w - logo_w - margin)
    else:
        x = max(0, php_round((img_w - logo_w) / 2))
    if position.startswith("top-"):
        y = margin
    elif position.startswith("bottom-"):
        y = max(0, img_h - logo_h - margin)
    else:
        y = max(0, php_round((img_h - logo_h) / 2))
    return x, y
