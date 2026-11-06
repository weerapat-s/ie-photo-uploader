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

import io
from pathlib import Path

from PIL import Image, ImageOps

_COLOURS = {"black": (0, 0, 0), "white": (255, 255, 255)}


def prepare_logo(logo_png: bytes, target_width: int, colour: str, opacity: int) -> Image.Image:
    """Trim transparent edges, scale to target width (LANCZOS), repaint, scale alpha."""
    logo = Image.open(io.BytesIO(logo_png)).convert("RGBA")
    bbox = logo.getchannel("A").getbbox()
    if bbox:
        logo = logo.crop(bbox)
    target_width = max(1, target_width)
    target_height = max(1, php_round(logo.height * (target_width / logo.width)))
    logo = logo.resize((target_width, target_height), Image.LANCZOS)
    alpha = logo.getchannel("A")
    fraction = max(0.10, min(1.0, opacity / 100))
    if fraction < 1.0:
        alpha = alpha.point(lambda a: php_round(a * fraction))
    rgb = _COLOURS["white" if colour == "white" else "black"]
    painted = Image.new("RGBA", logo.size, rgb + (0,))
    painted.putalpha(alpha)
    return painted


def apply_image(img: Image.Image, logo_png: bytes, position: str, opacity: int, colour: str,
                keep_alpha: bool | None = None, inplace: bool = False) -> Image.Image:
    """In-memory core of apply(). img must already be EXIF-oriented.

    Without alpha the image stays RGB and only the logo-sized region goes through RGBA, so a
    24 MP photo is never copied as a full RGBA buffer. Images that keep alpha (PNG/WEBP) use the
    full RGBA path. keep_alpha=None means "keep it if the image has any alpha-capable mode".
    """
    if keep_alpha is None:
        keep_alpha = img.mode in ("RGBA", "LA", "P")
    if keep_alpha:
        base = img.convert("RGBA")
        logo = prepare_logo(logo_png, php_round(base.width * WIDTH_RATIO), colour, opacity)
        base.alpha_composite(logo, logo_box(base.width, base.height, logo.width, logo.height, position))
        return base
    if img.mode == "RGB":
        base = img if inplace else img.copy()
    else:
        base = img.convert("RGB")
    logo = prepare_logo(logo_png, php_round(base.width * WIDTH_RATIO), colour, opacity)
    x, y = logo_box(base.width, base.height, logo.width, logo.height, position)
    x1, y1 = min(base.width, x + logo.width), min(base.height, y + logo.height)
    if x1 > x and y1 > y:
        region = base.crop((x, y, x1, y1)).convert("RGBA")
        region.alpha_composite(logo.crop((0, 0, x1 - x, y1 - y)))
        base.paste(region.convert("RGB"), (x, y))
    return base


def apply(src_path, logo_png: bytes, position: str, opacity: int, colour: str) -> tuple[bytes, str]:
    """Watermark one file. Returns (encoded bytes, output extension incl. dot)."""
    ext = Path(src_path).suffix.lower()
    fmt = FORMATS.get(ext)
    if fmt is None:
        raise ValueError(f"unsupported file type: {ext}")
    out_ext = ".jpg" if ext == ".jpeg" else ext
    with Image.open(src_path) as opened:
        icc = opened.info.get("icc_profile")
        img = ImageOps.exif_transpose(opened)
        img.load()
    keep_alpha = fmt != "JPEG" and img.mode in ("RGBA", "LA", "P")
    result = apply_image(img, logo_png, position, opacity, colour, keep_alpha=keep_alpha, inplace=True)
    buf = io.BytesIO()
    params = {}
    if icc:
        params["icc_profile"] = icc
    if fmt == "JPEG":
        params.update(quality=JPEG_QUALITY, subsampling=0, optimize=True)
    elif fmt == "WEBP":
        params.update(quality=JPEG_QUALITY, method=4)
    result.save(buf, fmt, **params)
    return buf.getvalue(), out_ext
