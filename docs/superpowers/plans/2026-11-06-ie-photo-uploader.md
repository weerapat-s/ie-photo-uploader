# IE-PHOTO Uploader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A desktop app (Windows + macOS) that watermarks photos on the photographer's machine and uploads them to the IE-PHOTO Nextcloud, where the server only assigns the running `IEPHOTO@KMTIL_####` name and files them.

**Architecture:** Python client split into pure units (`watermark`, `state`, `uploader`, `pipeline`, `settings`) plus a CustomTkinter UI. Server side adds a per-event desktop code store, a seen-file log, a `DesktopController` (`/desktop/config`, `/desktop/upload`), an `occ` command and an admin section to the existing `iephoto_watermark` Nextcloud app. Releases are built by GitHub Actions on tag.

**Tech Stack:** Python 3.11, Pillow, requests, CustomTkinter, tkinterdnd2, keyring, pytest, responses, PyInstaller, Inno Setup; PHP 8.4 / Nextcloud 33 AppFramework, Imagick.

**Hard rule for the whole plan:** the NAS (`ssh nas@100.116.118.109`) must never be shut down or rebooted. Server deploys only copy files and run `occ upgrade`, always after a backup.

**Paths used below**
- Repo: `C:\Users\weerapat\ie-photo-uploader` (bash: `/c/Users/weerapat/ie-photo-uploader`)
- Live app on NAS: `/mnt/Storage1_sdb1/AppData/nextcloud/html/custom_apps/iephoto_watermark` (called `$APP` below)
- occ: `ssh nas@100.116.118.109 'docker exec -u www-data nextcloud php occ …'`

---

## File Structure

```
ie-photo-uploader/
├── README.md                              # Thai user guide + dev notes
├── .github/workflows/release.yml          # build exe/dmg on tag v*
├── client/
│   ├── pyproject.toml                     # deps, pytest config
│   ├── ie_uploader/
│   │   ├── __init__.py                    # __version__
│   │   ├── __main__.py                    # python -m ie_uploader
│   │   ├── watermark.py                   # pure image ops, mirrors server Imagick logic
│   │   ├── state.py                       # sha256 -> uploaded name, per code, atomic JSON
│   │   ├── uploader.py                    # HTTP client: config, upload, retries, base URL pick
│   │   ├── pipeline.py                    # scan, process pool, bounded queue, upload threads
│   │   ├── settings.py                    # name/position in JSON, code in OS keyring
│   │   └── app.py                         # CustomTkinter UI
│   ├── packaging/
│   │   ├── ie_uploader.spec               # PyInstaller
│   │   └── installer.iss                  # Inno Setup (Windows)
│   └── tests/
│       ├── conftest.py                    # sample image fixtures
│       ├── test_watermark.py
│       ├── test_state.py
│       ├── test_uploader.py
│       ├── test_pipeline.py
│       └── test_settings.py
├── server/
│   ├── iephoto_watermark/                 # copy of the live app (source of truth from now on)
│   │   ├── appinfo/info.xml               # version 1.55.0, <commands>
│   │   ├── appinfo/routes.php             # + desktop + admin desktop-code routes
│   │   ├── lib/AppInfo/Application.php    # + service registrations
│   │   ├── lib/Service/DesktopCodeStore.php   # NEW pure
│   │   ├── lib/Service/DesktopSeenLog.php     # NEW pure
│   │   ├── lib/Service/UploadService.php      # + writeWithNewName, storeDesktopUpload, desktopSports
│   │   ├── lib/Controller/DesktopController.php  # NEW
│   │   ├── lib/Controller/AdminController.php    # + 3 endpoints
│   │   ├── lib/Command/DesktopCode.php           # NEW occ command
│   │   ├── templates/admin.php            # + section
│   │   └── js/admin-desktop.js            # NEW
│   └── tests/
│       ├── DesktopCodeStoreTest.php       # plain-PHP assertions
│       ├── DesktopSeenLogTest.php
│       ├── parity/server_watermark.php    # reference watermark via Imagick (runs in container)
│       └── smoke.sh                       # curl checks against live server
└── docs/superpowers/{specs,plans}/
```

---

### Task 0: Spec amendments + scaffold + import server app

Code reading during planning found three spec details that must change:
1. Uploads land in `<albums root>/<sport-or-album>/<photographer>/` exactly like `/upload`; a code stores an optional **album name** (must be registered in the venue registry), not an arbitrary folder.
2. Server accepts only `jpg/jpeg/png/webp`, so HEIC is dropped.
3. `/upload` archives the unwatermarked original into appdata `originals/`; desktop uploads never send the original, so that archive is not filled for desktop uploads (originals stay on the photographer's machine).

**Files:**
- Modify: `docs/superpowers/specs/2026-11-06-ie-photo-uploader-design.md`
- Create: `client/pyproject.toml`, `client/ie_uploader/__init__.py`, `client/tests/conftest.py`
- Create: `server/iephoto_watermark/**` (copied from NAS)

- [ ] **Step 1: Amend the spec**

In `docs/superpowers/specs/2026-11-06-ie-photo-uploader-design.md` apply:
- Table row `นามสกุลที่รับ` → `.jpg`, `.jpeg`, `.png`, `.webp` (ตรงกับที่เซิร์ฟเวอร์รับ) ส่งออกนามสกุลเดิม
- Remove `HEIC ผ่าน pillow-heif`
- §4.1 replace "โฟลเดอร์ปลายทาง (ต้องอยู่ใน scan scope เดิม)" with "อัลบั้ม (ไม่บังคับ): ชื่ออัลบั้ม/กีฬาที่ลงทะเบียนแล้ว ถ้าว่าง ช่างภาพเลือกกีฬาเองจากรายการ"
- In the JSON record replace `folder` with `album`
- §4.3 folder rule → "โฟลเดอร์ = `<albums root>/<album ของรหัส หรือ sport ที่ส่งมา>/<ชื่อช่างภาพ>/` (กติกาเดียวกับ /upload แบบเลือกกีฬา) ชื่อต้องอยู่ใน venue registry"
- §4.4 replace scan-scope bullet with "เขียนได้เฉพาะโฟลเดอร์อัลบั้มที่ลงทะเบียนแล้ว"
- §7 add bullet "อัปโหลดผ่านโปรแกรมไม่ส่งไฟล์ต้นฉบับ (ไม่มีลายน้ำ) ขึ้นเซิร์ฟเวอร์ คลังต้นฉบับใน appdata จึงไม่มีรูปจากโปรแกรม ต้นฉบับอยู่ในเครื่องช่างภาพ"
- §4.2 add `"album": ""` to the config JSON and note sports list has one entry when the code is bound to an album

- [ ] **Step 2: Client scaffold**

`client/pyproject.toml`:
```toml
[project]
name = "ie-uploader"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = [
  "Pillow>=10.4",
  "requests>=2.32",
  "customtkinter>=5.2",
  "tkinterdnd2>=0.4",
  "keyring>=25",
]

[project.optional-dependencies]
dev = ["pytest>=8", "responses>=0.25", "pyinstaller>=6.10"]

[tool.pytest.ini_options]
testpaths = ["tests"]
pythonpath = ["."]
```

`client/ie_uploader/__init__.py`:
```python
"""IE-PHOTO Uploader: watermark on the photographer's machine, upload to IE-PHOTO."""
__version__ = "0.1.0"
```

`client/tests/conftest.py`:
```python
import io
import pytest
from PIL import Image


def make_jpeg(path, size=(600, 400), colour=(30, 120, 200), exif_orientation=None, icc=None):
    img = Image.new("RGB", size, colour)
    kwargs = {"quality": 95}
    if exif_orientation is not None:
        exif = Image.Exif()
        exif[0x0112] = exif_orientation
        kwargs["exif"] = exif.tobytes()
    if icc is not None:
        kwargs["icc_profile"] = icc
    img.save(path, "JPEG", **kwargs)
    return path


@pytest.fixture
def logo_bytes():
    # 200x100 white mark with a transparent 10px border, like the real near-white line art.
    logo = Image.new("RGBA", (220, 120), (0, 0, 0, 0))
    logo.paste(Image.new("RGBA", (200, 100), (255, 255, 255, 255)), (10, 10))
    buf = io.BytesIO()
    logo.save(buf, "PNG")
    return buf.getvalue()


@pytest.fixture
def jpeg(tmp_path):
    return make_jpeg(tmp_path / "a.jpg")
```

- [ ] **Step 3: Create venv and install**

Run (bash):
```bash
cd /c/Users/weerapat/ie-photo-uploader/client && python -m venv .venv && .venv/Scripts/python -m pip install -q -e ".[dev]" && .venv/Scripts/python -m pytest -q
```
Expected: `no tests ran` (exit code 5 is fine at this point).

- [ ] **Step 4: Import the live server app**

```bash
cd /c/Users/weerapat/ie-photo-uploader && mkdir -p server && ssh nas@100.116.118.109 'cd /mnt/Storage1_sdb1/AppData/nextcloud/html/custom_apps && tar czf - iephoto_watermark' | tar xzf - -C server && grep "<version>" server/iephoto_watermark/appinfo/info.xml
```
Expected: `<version>1.54.2</version>`

- [ ] **Step 5: Commit**

```bash
cd /c/Users/weerapat/ie-photo-uploader && git add -A && git commit -m "chore: scaffold client, import iephoto_watermark 1.54.2, amend spec"
```

---

### Task 1: watermark geometry (PHP-compatible rounding + logo box)

**Files:**
- Create: `client/ie_uploader/watermark.py`
- Test: `client/tests/test_watermark.py`

- [ ] **Step 1: Write the failing tests**

`client/tests/test_watermark.py`:
```python
import pytest
from ie_uploader import watermark as wm


def test_php_round_is_half_away_from_zero():
    assert wm.php_round(2.5) == 3
    assert wm.php_round(3.5) == 4
    assert wm.php_round(-2.5) == -3
    assert wm.php_round(2.49) == 2


@pytest.mark.parametrize("position,expected", [
    ("top-left", (8, 8)),
    ("top-center", (250, 8)),
    ("top-right", (492, 8)),
    ("middle-left", (8, 175)),
    ("center", (250, 175)),
    ("middle-right", (492, 175)),
    ("bottom-left", (8, 342)),
    ("bottom-center", (250, 342)),
    ("bottom-right", (492, 342)),
])
def test_logo_box_matches_server_formula(position, expected):
    # image 600x400 -> margin round(400*0.02)=8 ; logo 100x50
    assert wm.logo_box(600, 400, 100, 50, position) == expected


def test_logo_box_never_negative():
    assert wm.logo_box(50, 50, 100, 100, "bottom-right") == (0, 0)
    assert wm.logo_box(50, 50, 100, 100, "center") == (0, 0)


def test_unknown_position_rejected():
    with pytest.raises(ValueError):
        wm.logo_box(600, 400, 100, 50, "somewhere")
```

- [ ] **Step 2: Run to verify failure**

Run: `cd client && .venv/Scripts/python -m pytest tests/test_watermark.py -q`
Expected: FAIL `ModuleNotFoundError: No module named 'ie_uploader.watermark'`

- [ ] **Step 3: Implement**

`client/ie_uploader/watermark.py`:
```python
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
```

- [ ] **Step 4: Run to verify pass**

Run: `cd client && .venv/Scripts/python -m pytest tests/test_watermark.py -q`
Expected: `12 passed`

- [ ] **Step 5: Commit**

```bash
git add client/ie_uploader/watermark.py client/tests/test_watermark.py && git commit -m "feat(client): watermark geometry matching server rounding"
```

---

### Task 2: watermark apply (logo prep, composite, encode)

**Files:**
- Modify: `client/ie_uploader/watermark.py` (append)
- Test: `client/tests/test_watermark.py` (append)

- [ ] **Step 1: Write the failing tests** (append)

```python
import io
from PIL import Image, ImageCms
from tests.conftest import make_jpeg


def _open(data):
    return Image.open(io.BytesIO(data))


def test_prepare_logo_trims_resizes_and_tints(logo_bytes):
    logo = wm.prepare_logo(logo_bytes, target_width=100, colour="black", opacity=100)
    assert logo.size == (100, 50)                     # 200x100 after trim, scaled to width 100
    r, g, b, a = logo.getpixel((50, 25))
    assert (r, g, b) == (0, 0, 0) and a == 255


def test_prepare_logo_opacity_scales_alpha(logo_bytes):
    logo = wm.prepare_logo(logo_bytes, target_width=100, colour="white", opacity=50)
    r, g, b, a = logo.getpixel((50, 25))
    assert (r, g, b) == (255, 255, 255) and a == 128  # php_round(255*0.5)


def test_prepare_logo_opacity_floor_is_ten_percent(logo_bytes):
    logo = wm.prepare_logo(logo_bytes, target_width=100, colour="black", opacity=1)
    assert logo.getpixel((50, 25))[3] == 26           # php_round(255*0.10)


def test_apply_places_logo_top_left(tmp_path, logo_bytes):
    src = make_jpeg(tmp_path / "p.jpg", size=(1000, 600), colour=(200, 200, 200))
    data, ext = wm.apply(src, logo_bytes, "top-left", 100, "black")
    assert ext == ".jpg"
    out = _open(data)
    assert out.size == (1000, 600)
    # logo width 70, height 35, margin 12 -> dark at (12+35, 12+17), untouched far away
    assert sum(out.getpixel((47, 29))) < 60
    assert sum(out.getpixel((900, 500))) > 500


def test_apply_honours_exif_orientation(tmp_path, logo_bytes):
    src = make_jpeg(tmp_path / "r.jpg", size=(600, 400), exif_orientation=6)
    out = _open(wm.apply(src, logo_bytes, "top-left", 100, "black")[0])
    assert out.size == (400, 600)
    assert out.getexif().get(0x0112) is None


def test_apply_keeps_icc_and_drops_other_exif(tmp_path, logo_bytes):
    icc = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
    src = make_jpeg(tmp_path / "c.jpg", icc=icc, exif_orientation=1)
    out = _open(wm.apply(src, logo_bytes, "center", 100, "white")[0])
    assert out.info.get("icc_profile") == icc
    assert len(out.getexif()) == 0


def test_apply_jpeg_is_444(tmp_path, logo_bytes):
    from PIL import JpegImagePlugin
    src = make_jpeg(tmp_path / "s.jpg")
    out = _open(wm.apply(src, logo_bytes, "center", 100, "white")[0])
    assert JpegImagePlugin.get_sampling(out) == 0


def test_apply_png_keeps_extension(tmp_path, logo_bytes):
    p = tmp_path / "x.png"
    Image.new("RGBA", (300, 200), (10, 10, 10, 255)).save(p)
    data, ext = wm.apply(p, logo_bytes, "center", 100, "white")
    assert ext == ".png" and _open(data).format == "PNG"


def test_apply_rejects_unsupported_extension(tmp_path, logo_bytes):
    p = tmp_path / "x.gif"
    Image.new("RGB", (10, 10)).save(p)
    with pytest.raises(ValueError):
        wm.apply(p, logo_bytes, "center", 100, "white")
```

- [ ] **Step 2: Run to verify failure**

Run: `cd client && .venv/Scripts/python -m pytest tests/test_watermark.py -q`
Expected: FAIL `AttributeError: module 'ie_uploader.watermark' has no attribute 'prepare_logo'`

- [ ] **Step 3: Implement** (append to `watermark.py`)

```python
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
    base = img.convert("RGBA")
    logo = prepare_logo(logo_png, php_round(base.width * WIDTH_RATIO), colour, opacity)
    base.alpha_composite(logo, logo_box(base.width, base.height, logo.width, logo.height, position))
    result = base if keep_alpha else base.convert("RGB")
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
```

Note: the server keeps the uploaded extension (`jpeg` stays `jpeg`), so the uploader sends `originalName` with its original extension; the returned extension is only used for the temp file name.

- [ ] **Step 4: Run to verify pass**

Run: `cd client && .venv/Scripts/python -m pytest tests/test_watermark.py -q`
Expected: `21 passed`

- [ ] **Step 5: Commit**

```bash
git add client && git commit -m "feat(client): watermark apply with ICC, 4:4:4 and EXIF orientation"
```

---

### Task 3: state (resume without re-upload)

**Files:**
- Create: `client/ie_uploader/state.py`
- Test: `client/tests/test_state.py`

- [ ] **Step 1: Failing tests**

```python
import json
from ie_uploader.state import State, state_path


def test_mark_and_lookup_persist(tmp_path):
    s = State(tmp_path / "s.json")
    assert s.done("a" * 64) is None
    s.mark("a" * 64, "IEPHOTO@KMTIL_0001.jpg")
    assert State(tmp_path / "s.json").done("a" * 64) == "IEPHOTO@KMTIL_0001.jpg"


def test_corrupt_file_starts_empty(tmp_path):
    p = tmp_path / "s.json"
    p.write_text("{not json", encoding="utf-8")
    assert State(p).done("a" * 64) is None


def test_write_is_atomic_json(tmp_path):
    p = tmp_path / "s.json"
    State(p).mark("b" * 64, "x.jpg")
    assert json.loads(p.read_text(encoding="utf-8")) == {"b" * 64: "x.jpg"}
    assert not list(tmp_path.glob("*.tmp"))


def test_state_path_is_per_code(tmp_path):
    a = state_path(tmp_path, "AAAAA-BBBBB-CCCCC-DDDDD")
    b = state_path(tmp_path, "aaaaabbbbbcccccddddd")
    c = state_path(tmp_path, "ZZZZZ-BBBBB-CCCCC-DDDDD")
    assert a == b and a != c and a.parent == tmp_path
```

- [ ] **Step 2: Run** `pytest tests/test_state.py -q` → FAIL `ModuleNotFoundError`

- [ ] **Step 3: Implement** `client/ie_uploader/state.py`

```python
"""Which source files (by sha256) were already uploaded, per desktop code."""
from __future__ import annotations

import hashlib
import json
import os
import re
import threading
from pathlib import Path


def normalize_code(code: str) -> str:
    return re.sub(r"[^A-Za-z0-9]", "", code).upper()


def state_path(data_dir: Path, code: str) -> Path:
    digest = hashlib.sha256(normalize_code(code).encode()).hexdigest()[:12]
    return Path(data_dir) / f"state-{digest}.json"


class State:
    def __init__(self, path: Path):
        self.path = Path(path)
        self._lock = threading.Lock()
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            self._map = {k: v for k, v in data.items() if isinstance(k, str) and isinstance(v, str)}
        except (OSError, ValueError, AttributeError):
            self._map = {}

    def done(self, sha256: str) -> str | None:
        return self._map.get(sha256)

    def done_set(self) -> frozenset[str]:
        with self._lock:
            return frozenset(self._map)

    def mark(self, sha256: str, name: str) -> None:
        with self._lock:
            self._map[sha256] = name
            self.path.parent.mkdir(parents=True, exist_ok=True)
            tmp = self.path.with_suffix(".json.tmp")
            tmp.write_text(json.dumps(self._map, ensure_ascii=False), encoding="utf-8")
            os.replace(tmp, self.path)
```

- [ ] **Step 4: Run** `pytest tests/test_state.py -q` → `4 passed`

- [ ] **Step 5: Commit** `git add client && git commit -m "feat(client): resumable upload state"`

---

### Task 4: uploader (HTTP, retries, base URL)

**Files:**
- Create: `client/ie_uploader/uploader.py`
- Test: `client/tests/test_uploader.py`

- [ ] **Step 1: Failing tests**

```python
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
```

- [ ] **Step 2: Run** `pytest tests/test_uploader.py -q` → FAIL `ModuleNotFoundError`

- [ ] **Step 3: Implement** `client/ie_uploader/uploader.py`

```python
"""Talks to /apps/iephoto_watermark/desktop/* on the IE-PHOTO Nextcloud."""
from __future__ import annotations

import time
from dataclasses import dataclass

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
            except (requests.ConnectionError, requests.Timeout) as exc:
                last = str(exc)
            else:
                if resp.status_code in (401, 403):
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
            body = resp.json()
            return UploadResult(ok=True, name=body.get("name"), duplicate=bool(body.get("duplicate")))
        return UploadResult(ok=False, error=_error_text(resp))
```

- [ ] **Step 4: Run** `pytest tests/test_uploader.py -q` → `8 passed`

- [ ] **Step 5: Commit** `git add client && git commit -m "feat(client): uploader with retry, auth stop and fast-channel pick"`

---

### Task 5: pipeline (process pool + bounded queue + upload threads)

**Files:**
- Create: `client/ie_uploader/pipeline.py`
- Test: `client/tests/test_pipeline.py`

- [ ] **Step 1: Failing tests**

```python
import threading
from concurrent.futures import ThreadPoolExecutor
from ie_uploader import pipeline as pl
from ie_uploader.state import State
from ie_uploader.uploader import AuthError, TransientError, UploadResult
from tests.conftest import make_jpeg


class FakeClient:
    def __init__(self, script=None):
        self.calls = []
        self.script = list(script or [])
        self.lock = threading.Lock()

    def upload(self, data, name, sha, uploader, sport, position):
        with self.lock:
            self.calls.append(name)
            if self.script:
                step = self.script.pop(0)
                if isinstance(step, Exception):
                    raise step
                return step
        return UploadResult(ok=True, name=f"IEPHOTO@KMTIL_{len(self.calls):04d}.jpg")


def run(tmp_path, client, logo_bytes, n=5, stop=None, events=None):
    folder = tmp_path / "in"
    folder.mkdir(exist_ok=True)
    for i in range(n):
        if not (folder / f"{i}.jpg").exists():
            make_jpeg(folder / f"{i}.jpg", colour=(i * 40 % 255, 10, 10))
    state = State(tmp_path / "state.json")
    settings = pl.RunSettings(uploader="เอ", sport="ฟุตบอล", position="top-left", opacity=100, colour="black", logo=logo_bytes)
    p = pl.Pipeline(client, state, settings, workers=2, pool_factory=ThreadPoolExecutor,
                    on_event=(events.append if events is not None else (lambda e: None)), pause_seconds=0)
    return p.run(pl.scan(folder), stop or threading.Event()), state


def test_scan_finds_images_recursively_sorted(tmp_path):
    (tmp_path / "b").mkdir()
    for name in ["b/2.JPG", "1.jpg", "x.txt", "3.png"]:
        (tmp_path / name).write_bytes(b"x")
    assert [p.name for p in pl.scan(tmp_path)] == ["1.jpg", "3.png", "2.JPG"]


def test_all_files_uploaded_once(tmp_path, logo_bytes):
    c = FakeClient()
    summary, state = run(tmp_path, c, logo_bytes)
    assert summary.uploaded == 5 and summary.failed == [] and sorted(c.calls) == [f"{i}.jpg" for i in range(5)]
    assert len(state.done_set()) == 5


def test_resume_skips_already_uploaded(tmp_path, logo_bytes):
    run(tmp_path, FakeClient(), logo_bytes)
    c2 = FakeClient()
    summary, _ = run(tmp_path, c2, logo_bytes)
    assert c2.calls == [] and summary.skipped == 5


def test_per_file_failure_is_reported(tmp_path, logo_bytes):
    c = FakeClient([UploadResult(ok=False, error="ไฟล์เสีย")])
    summary, _ = run(tmp_path, c, logo_bytes)
    assert summary.uploaded == 4 and len(summary.failed) == 1 and summary.failed[0][1] == "ไฟล์เสีย"


def test_auth_error_stops_everything(tmp_path, logo_bytes):
    events = []
    c = FakeClient([AuthError("invalid_code")] * 10)
    summary, _ = run(tmp_path, c, logo_bytes, events=events)
    assert summary.auth_error and summary.uploaded == 0
    assert any(e.kind == "auth_error" for e in events)


def test_transient_error_pauses_then_continues(tmp_path, logo_bytes):
    events = []
    c = FakeClient([TransientError("down")])
    summary, _ = run(tmp_path, c, logo_bytes, events=events)
    assert summary.uploaded == 5
    assert any(e.kind == "paused" for e in events)


def test_stop_event_halts_early(tmp_path, logo_bytes):
    stop = threading.Event()
    stop.set()
    summary, _ = run(tmp_path, FakeClient(), logo_bytes, stop=stop)
    assert summary.uploaded == 0 and summary.stopped
```

- [ ] **Step 2: Run** `pytest tests/test_pipeline.py -q` → FAIL `ModuleNotFoundError`

- [ ] **Step 3: Implement** `client/ie_uploader/pipeline.py`

```python
"""Watermark in a process pool, upload in threads, never more than max_pending in memory."""
from __future__ import annotations

import hashlib
import os
import tempfile
import threading
import time
from concurrent.futures import ProcessPoolExecutor, ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path

from . import watermark
from .state import State
from .uploader import AuthError, TransientError


@dataclass
class RunSettings:
    uploader: str
    sport: str
    position: str
    opacity: int
    colour: str
    logo: bytes


@dataclass
class Event:
    kind: str                 # progress | failed | paused | auth_error | finished
    done: int = 0
    total: int = 0
    message: str = ""
    path: str = ""


@dataclass
class Summary:
    total: int = 0
    uploaded: int = 0
    skipped: int = 0
    failed: list = field(default_factory=list)   # [(path, error)]
    auth_error: bool = False
    stopped: bool = False
    seconds: float = 0.0


def scan(folder) -> list[Path]:
    root = Path(folder)
    files = [p for p in root.rglob("*") if p.is_file() and p.suffix.lower() in watermark.FORMATS]
    return sorted(files, key=lambda p: (len(p.relative_to(root).parts), str(p.relative_to(root)).lower()))


def sha256_file(path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _work(path: str, done: frozenset, logo: bytes, position: str, opacity: int, colour: str):
    """Runs in a worker process. Returns (sha, temp_file_or_None)."""
    sha = sha256_file(path)
    if sha in done:
        return sha, None
    data, ext = watermark.apply(path, logo, position, opacity, colour)
    fd, tmp = tempfile.mkstemp(prefix="iephoto-", suffix=ext)
    with os.fdopen(fd, "wb") as f:
        f.write(data)
    return sha, tmp


class Pipeline:
    def __init__(self, client, state: State, settings: RunSettings, workers: int | None = None,
                 upload_threads: int = 3, max_pending: int = 12, on_event=lambda e: None,
                 pool_factory=ProcessPoolExecutor, pause_seconds: float = 60):
        self.client = client
        self.state = state
        self.s = settings
        self.workers = workers or max(1, (os.cpu_count() or 2) - 1)
        self.upload_threads = upload_threads
        self.max_pending = max_pending
        self.emit = on_event
        self.pool_factory = pool_factory
        self.pause_seconds = pause_seconds

    def run(self, files: list[Path], stop: threading.Event) -> Summary:
        summary = Summary(total=len(files))
        started = time.monotonic()
        lock = threading.Lock()
        slots = threading.Semaphore(self.max_pending)
        abort = threading.Event()
        done = self.state.done_set()

        def progress():
            n = summary.uploaded + summary.skipped + len(summary.failed)
            self.emit(Event("progress", done=n, total=summary.total))

        def upload_one(path: Path, sha: str, tmp: str):
            try:
                with open(tmp, "rb") as f:
                    data = f.read()
                while not (stop.is_set() or abort.is_set()):
                    try:
                        res = self.client.upload(data, path.name, sha, self.s.uploader, self.s.sport, self.s.position)
                        break
                    except TransientError as exc:
                        self.emit(Event("paused", message=f"เชื่อมต่อไม่ได้ ({exc}) จะลองใหม่อัตโนมัติ"))
                        stop.wait(self.pause_seconds)
                    except AuthError as exc:
                        abort.set()
                        with lock:
                            summary.auth_error = True
                        self.emit(Event("auth_error", message=str(exc)))
                        return
                else:
                    return
                with lock:
                    if res.ok:
                        self.state.mark(sha, res.name or "")
                        summary.uploaded += 1
                    else:
                        summary.failed.append((str(path), res.error or "ไม่ทราบสาเหตุ"))
                        self.emit(Event("failed", path=str(path), message=res.error or ""))
                progress()
            finally:
                try:
                    os.unlink(tmp)
                except OSError:
                    pass
                slots.release()

        with self.pool_factory(max_workers=self.workers) as pool, \
                ThreadPoolExecutor(max_workers=self.upload_threads) as uploads:
            pending_uploads = []

            def on_processed(path: Path, fut):
                try:
                    sha, tmp = fut.result()
                except Exception as exc:          # unreadable / unsupported image
                    with lock:
                        summary.failed.append((str(path), f"เปิดรูปไม่ได้: {exc}"))
                    self.emit(Event("failed", path=str(path), message=str(exc)))
                    slots.release()
                    progress()
                    return
                if tmp is None:
                    with lock:
                        summary.skipped += 1
                    slots.release()
                    progress()
                    return
                pending_uploads.append(uploads.submit(upload_one, path, sha, tmp))

            for path in files:
                while not slots.acquire(timeout=0.2):
                    if stop.is_set() or abort.is_set():
                        break
                else:
                    if stop.is_set() or abort.is_set():
                        slots.release()
                        break
                    fut = pool.submit(_work, str(path), done, self.s.logo, self.s.position, self.s.opacity, self.s.colour)
                    fut.add_done_callback(lambda f, p=path: on_processed(p, f))
                    continue
                break
            pool.shutdown(wait=True)
            for f in list(pending_uploads):
                f.result()

        summary.stopped = stop.is_set()
        summary.seconds = time.monotonic() - started
        self.emit(Event("finished", done=summary.uploaded, total=summary.total))
        return summary
```

- [ ] **Step 4: Run** `pytest tests/test_pipeline.py -q` → `7 passed`. Then full suite `pytest -q` → all pass.

- [ ] **Step 5: Commit** `git add client && git commit -m "feat(client): pipeline with bounded memory, resume, pause and auth stop"`

---

### Task 6: settings (name/position JSON, code in keyring)

**Files:**
- Create: `client/ie_uploader/settings.py`
- Test: `client/tests/test_settings.py`

- [ ] **Step 1: Failing tests**

```python
import keyring
from keyring.backend import KeyringBackend
from ie_uploader.settings import Settings


class MemKeyring(KeyringBackend):
    priority = 1
    def __init__(self):
        self.d = {}
    def get_password(self, s, u):
        return self.d.get((s, u))
    def set_password(self, s, u, p):
        self.d[(s, u)] = p
    def delete_password(self, s, u):
        self.d.pop((s, u), None)


def test_roundtrip(tmp_path):
    keyring.set_keyring(MemKeyring())
    s = Settings(tmp_path)
    s.save(code="AAAAA-BBBBB-CCCCC-DDDDD", uploader="เอ", position="bottom-right", opacity=80)
    t = Settings(tmp_path)
    assert (t.code, t.uploader, t.position, t.opacity) == ("AAAAA-BBBBB-CCCCC-DDDDD", "เอ", "bottom-right", 80)
    assert "AAAAA" not in (tmp_path / "settings.json").read_text(encoding="utf-8")


def test_defaults(tmp_path):
    keyring.set_keyring(MemKeyring())
    s = Settings(tmp_path)
    assert (s.code, s.uploader, s.position, s.opacity) == ("", "", "top-left", 100)
```

- [ ] **Step 2: Run** `pytest tests/test_settings.py -q` → FAIL `ModuleNotFoundError`

- [ ] **Step 3: Implement** `client/ie_uploader/settings.py`

```python
"""Per-user settings. The desktop code goes to the OS keyring, never to settings.json."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import keyring

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
        self.uploader = str(raw.get("uploader", ""))
        self.position = str(raw.get("position", "top-left"))
        self.opacity = int(raw.get("opacity", 100))
        self.code = keyring.get_password(SERVICE, "desktop-code") or ""

    def save(self, code: str, uploader: str, position: str, opacity: int) -> None:
        self.code, self.uploader, self.position, self.opacity = code.strip(), uploader.strip(), position, int(opacity)
        keyring.set_password(SERVICE, "desktop-code", self.code)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.file.write_text(json.dumps(
            {"uploader": self.uploader, "position": self.position, "opacity": self.opacity},
            ensure_ascii=False), encoding="utf-8")
```

- [ ] **Step 4: Run** `pytest tests/test_settings.py -q` → `2 passed`

- [ ] **Step 5: Commit** `git add client && git commit -m "feat(client): settings with keyring-held code"`

---

### Task 7: UI (CustomTkinter) + entry point

**Files:**
- Create: `client/ie_uploader/app.py`, `client/ie_uploader/__main__.py`

UI is verified manually (Step 3); logic it calls is already unit-tested.

- [ ] **Step 1: Write `client/ie_uploader/__main__.py`**

```python
import multiprocessing

from .app import main

if __name__ == "__main__":
    multiprocessing.freeze_support()   # required for ProcessPoolExecutor in PyInstaller builds
    main()
```

- [ ] **Step 2: Write `client/ie_uploader/app.py`**

```python
"""IE-PHOTO Uploader window."""
from __future__ import annotations

import queue
import threading
from pathlib import Path
from tkinter import filedialog, messagebox

import customtkinter as ctk
from tkinterdnd2 import DND_FILES, TkinterDnD
from PIL import Image

from . import __version__, watermark
from .pipeline import Pipeline, RunSettings, scan
from .settings import Settings
from .state import State, state_path
from .uploader import AuthError, Client, TransientError, pick_base_url

POS_LABELS = {
    "top-left": "ซ้ายบน", "top-center": "กลางบน", "top-right": "ขวาบน",
    "middle-left": "ซ้ายกลาง", "center": "กลาง", "middle-right": "ขวากลาง",
    "bottom-left": "ซ้ายล่าง", "bottom-center": "กลางล่าง", "bottom-right": "ขวาล่าง",
}


class App(ctk.CTk, TkinterDnD.DnDWrapper):
    def __init__(self):
        super().__init__()
        self.TkdndVersion = TkinterDnD._require(self)
        ctk.set_appearance_mode("light")
        self.title(f"IE-PHOTO Uploader {__version__}")
        self.geometry("760x640")
        self.settings = Settings()
        self.events: queue.Queue = queue.Queue()
        self.stop = threading.Event()
        self.client = None
        self.config_data = None
        self.logo = None
        self.folder: Path | None = None
        self._build()
        self.after(100, self._drain)

    # ---------- layout ----------
    def _build(self):
        pad = {"padx": 16, "pady": 6}
        top = ctk.CTkFrame(self)
        top.pack(fill="x", **pad)
        ctk.CTkLabel(top, text="รหัสงาน").grid(row=0, column=0, sticky="w", padx=8, pady=4)
        self.code_var = ctk.StringVar(value=self.settings.code)
        ctk.CTkEntry(top, textvariable=self.code_var, width=260, show="•").grid(row=0, column=1, padx=8)
        ctk.CTkLabel(top, text="ชื่อช่างภาพ").grid(row=1, column=0, sticky="w", padx=8, pady=4)
        self.name_var = ctk.StringVar(value=self.settings.uploader)
        ctk.CTkEntry(top, textvariable=self.name_var, width=260).grid(row=1, column=1, padx=8)
        ctk.CTkButton(top, text="เชื่อมต่อ", command=self._connect).grid(row=0, column=2, rowspan=2, padx=8)
        self.event_label = ctk.CTkLabel(top, text="ยังไม่ได้เชื่อมต่อ", text_color="#6f6880")
        self.event_label.grid(row=2, column=0, columnspan=3, sticky="w", padx=8)

        opts = ctk.CTkFrame(self)
        opts.pack(fill="x", **pad)
        ctk.CTkLabel(opts, text="กีฬา").grid(row=0, column=0, padx=8, pady=4, sticky="w")
        self.sport_menu = ctk.CTkOptionMenu(opts, values=["—"], command=lambda _: self._refresh_preview())
        self.sport_menu.grid(row=0, column=1, padx=8, sticky="w")
        self.colour_label = ctk.CTkLabel(opts, text="")
        self.colour_label.grid(row=0, column=2, padx=8)
        ctk.CTkLabel(opts, text="ตำแหน่งลายน้ำ").grid(row=1, column=0, padx=8, pady=4, sticky="w")
        self.pos_menu = ctk.CTkOptionMenu(opts, values=list(POS_LABELS.values()), command=lambda _: self._refresh_preview())
        self.pos_menu.set(POS_LABELS.get(self.settings.position, "ซ้ายบน"))
        self.pos_menu.grid(row=1, column=1, padx=8, sticky="w")
        ctk.CTkLabel(opts, text="ความเข้ม").grid(row=2, column=0, padx=8, sticky="w")
        self.opacity = ctk.CTkSlider(opts, from_=10, to=100, number_of_steps=18, command=lambda _: self._refresh_preview())
        self.opacity.set(self.settings.opacity)
        self.opacity.grid(row=2, column=1, padx=8, sticky="we")

        self.drop = ctk.CTkLabel(self, text="ลากโฟลเดอร์รูปมาวางที่นี่ หรือกดเพื่อเลือก", height=70,
                                 fg_color="#f4ece8", corner_radius=12)
        self.drop.pack(fill="x", **pad)
        self.drop.bind("<Button-1>", lambda e: self._choose_folder())
        self.drop.drop_target_register(DND_FILES)
        self.drop.dnd_bind("<<Drop>>", self._on_drop)

        self.preview = ctk.CTkLabel(self, text="", height=180)
        self.preview.pack(**pad)

        bar = ctk.CTkFrame(self, fg_color="transparent")
        bar.pack(fill="x", **pad)
        self.start_btn = ctk.CTkButton(bar, text="เริ่มใส่ลายน้ำ + อัปโหลด", fg_color="#c93a62",
                                       hover_color="#b32f55", command=self._start, state="disabled")
        self.start_btn.pack(side="left")
        self.stop_btn = ctk.CTkButton(bar, text="หยุด", command=self.stop.set, state="disabled", fg_color="#6f6880")
        self.stop_btn.pack(side="left", padx=8)
        self.progress = ctk.CTkProgressBar(self)
        self.progress.set(0)
        self.progress.pack(fill="x", **pad)
        self.status = ctk.CTkLabel(self, text="", anchor="w")
        self.status.pack(fill="x", **pad)
        self.failed_box = ctk.CTkTextbox(self, height=90)
        self.failed_box.pack(fill="both", expand=True, **pad)

    # ---------- actions ----------
    def _position(self) -> str:
        label = self.pos_menu.get()
        return next(k for k, v in POS_LABELS.items() if v == label)

    def _colour(self) -> str:
        for s in (self.config_data or {}).get("sports", []):
            if s["name"] == self.sport_menu.get():
                return s["colour"]
        return "black"

    def _connect(self):
        code = self.code_var.get().strip()
        if not code or not self.name_var.get().strip():
            messagebox.showwarning("IE-PHOTO", "ใส่รหัสงานและชื่อช่างภาพก่อน")
            return
        self.event_label.configure(text="กำลังเชื่อมต่อ…")

        def work():
            try:
                client = Client(pick_base_url(), code)
                cfg = client.config()
                logo = client.logo(cfg["logoUrl"])
                self.events.put(("connected", client, cfg, logo))
            except AuthError:
                self.events.put(("error", "รหัสงานไม่ถูกต้องหรือหมดอายุ ขอรหัสใหม่จากแอดมิน"))
            except (TransientError, Exception) as exc:
                self.events.put(("error", f"เชื่อมต่อเซิร์ฟเวอร์ไม่ได้: {exc}"))
        threading.Thread(target=work, daemon=True).start()

    def _choose_folder(self):
        chosen = filedialog.askdirectory()
        if chosen:
            self._set_folder(Path(chosen))

    def _on_drop(self, event):
        paths = self.tk.splitlist(event.data)
        if paths:
            p = Path(paths[0])
            self._set_folder(p if p.is_dir() else p.parent)

    def _set_folder(self, folder: Path):
        self.folder = folder
        files = scan(folder)
        self.drop.configure(text=f"{folder}  ·  {len(files)} รูป")
        self._refresh_preview()
        self._update_start()

    def _refresh_preview(self):
        self.colour_label.configure(text=f"สีลายน้ำ: {'ขาว' if self._colour() == 'white' else 'ดำ'}")
        if not (self.folder and self.logo):
            return
        files = scan(self.folder)
        if not files:
            return
        import io
        data, _ = watermark.apply(files[0], self.logo, self._position(), int(self.opacity.get()), self._colour())
        img = Image.open(io.BytesIO(data))
        img.thumbnail((320, 180))
        self.preview.configure(image=ctk.CTkImage(img, size=img.size), text="")

    def _update_start(self):
        ready = self.client is not None and self.folder is not None
        self.start_btn.configure(state="normal" if ready else "disabled")

    def _start(self):
        self.settings.save(self.code_var.get(), self.name_var.get(), self._position(), int(self.opacity.get()))
        files = scan(self.folder)
        state = State(state_path(self.settings.dir, self.settings.code))
        run = RunSettings(uploader=self.name_var.get().strip(), sport=self.sport_menu.get(),
                          position=self._position(), opacity=int(self.opacity.get()),
                          colour=self._colour(), logo=self.logo)
        self.stop.clear()
        self.failed_box.delete("1.0", "end")
        self.start_btn.configure(state="disabled")
        self.stop_btn.configure(state="normal")
        pipe = Pipeline(self.client, state, run, on_event=self.events.put)
        threading.Thread(target=lambda: self.events.put(("done", pipe.run(files, self.stop))), daemon=True).start()

    # ---------- event pump (UI thread) ----------
    def _drain(self):
        try:
            while True:
                self._handle(self.events.get_nowait())
        except queue.Empty:
            pass
        self.after(100, self._drain)

    def _handle(self, ev):
        if isinstance(ev, tuple):
            kind = ev[0]
            if kind == "connected":
                _, self.client, self.config_data, self.logo = ev
                names = [s["name"] for s in self.config_data["sports"]] or ["—"]
                self.sport_menu.configure(values=names)
                self.sport_menu.set(names[0])
                self.event_label.configure(text=f"งาน: {self.config_data['label']}  ·  เชื่อมต่อผ่าน {self.client.base.split('/apps')[0]}")
                self._refresh_preview()
                self._update_start()
            elif kind == "error":
                self.event_label.configure(text=ev[1])
            elif kind == "done":
                s = ev[1]
                self.stop_btn.configure(state="disabled")
                self._update_start()
                rate = s.uploaded / s.seconds if s.seconds else 0
                self.status.configure(text=f"เสร็จ: ส่ง {s.uploaded} · ข้าม (เคยส่งแล้ว) {s.skipped} · ไม่สำเร็จ {len(s.failed)} · {rate:.1f} รูป/วินาที")
                if s.auth_error:
                    messagebox.showerror("IE-PHOTO", "รหัสงานไม่ถูกต้องหรือหมดอายุ ขอรหัสใหม่จากแอดมิน")
            return
        if ev.kind == "progress":
            self.progress.set(ev.done / max(1, ev.total))
            self.status.configure(text=f"{ev.done}/{ev.total}")
        elif ev.kind == "failed":
            self.failed_box.insert("end", f"{Path(ev.path).name}: {ev.message}\n")
        elif ev.kind == "paused":
            self.status.configure(text=ev.message)


def main():
    App().mainloop()
```

- [ ] **Step 3: Manual smoke run (no server needed for layout)**

Run: `cd client && .venv/Scripts/python -m ie_uploader`
Expected: window opens; "เชื่อมต่อ" with a fake code shows "รหัสงานไม่ถูกต้อง…" or "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้…" (server endpoints arrive in Task 14); dropping a folder shows the photo count. Close the window.

- [ ] **Step 4: Commit** `git add client && git commit -m "feat(client): CustomTkinter UI and entry point"`

---

### Task 8: server DesktopCodeStore (pure, tested with plain PHP)

**Files:**
- Create: `server/iephoto_watermark/lib/Service/DesktopCodeStore.php`
- Test: `server/tests/DesktopCodeStoreTest.php`

- [ ] **Step 1: Failing test** `server/tests/DesktopCodeStoreTest.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../iephoto_watermark/lib/Service/DesktopCodeStore.php';

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "ok - $msg\n"; }

$blob = '[]'; $now = 1_000_000;
// Closures take the variables by reference: PHP arrow functions (fn) would freeze their values.
$store = new DesktopCodeStore(
    function () use (&$blob) { return $blob; },
    function (string $j) use (&$blob) { $blob = $j; },
    function () use (&$now) { return $now; },
);

$made = $store->create('ChalkGames 2569', 'ฟุตบอล', 7);
check((bool)preg_match('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', $made['code']), 'code format XXXXX-XXXXX-XXXXX-XXXXX');
check(!str_contains($blob, $made['code']) && !str_contains($blob, str_replace('-', '', $made['code'])), 'raw code never stored');
check(!isset($made['record']['hash']), 'public record hides hash');

$rec = $store->verify(strtolower(str_replace('-', ' ', $made['code'])));
check($rec !== null && $rec['album'] === 'ฟุตบอล' && $rec['label'] === 'ChalkGames 2569', 'verify tolerates case/spaces');
check($store->verify('AAAAA-AAAAA-AAAAA-AAAAA') === null, 'unknown code rejected');
check($store->verify('') === null, 'empty code rejected');

$now += 7 * 86400 + 1;
check($store->verify($made['code']) === null, 'expired code rejected');
$now -= 10;

check($store->revoke($made['record']['id']) === true, 'revoke returns true');
check($store->verify($made['code']) === null, 'revoked code rejected');
check($store->revoke('nope') === false, 'revoke unknown id false');
check(count($store->list()) === 1 && !isset($store->list()[0]['hash']), 'list hides hash');

$threw = false; try { $store->create('  ', '', 7); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'blank label rejected');
check($store->create('x', '', 999)['record']['expiresAt'] === $now + 90 * 86400, 'days capped at 90');
echo "all passed\n";
```

- [ ] **Step 2: Run** `php server/tests/DesktopCodeStoreTest.php` → FAIL (file not found)

- [ ] **Step 3: Implement** `server/iephoto_watermark/lib/Service/DesktopCodeStore.php`

```php
<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

/**
 * Per-event codes for the desktop uploader. Pure: storage and clock are injected so this can be
 * tested without Nextcloud. Only sha256(code) is stored; the code is shown to the admin once.
 */
final class DesktopCodeStore {
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private \Closure $load,
        private \Closure $save,
        private \Closure $now,
    ) {
    }

    /** @return array{code:string,record:array<string,mixed>} */
    public function create(string $label, string $album, int $days): array {
        $label = trim($label);
        if ($label === '' || mb_strlen($label, 'UTF-8') > 80) {
            throw new \InvalidArgumentException('ชื่องานไม่ถูกต้อง');
        }
        $days = max(1, min(90, $days));
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $now = ($this->now)();
        $record = [
            'id' => bin2hex(random_bytes(6)),
            'label' => $label,
            'album' => trim($album),
            'hash' => hash('sha256', $code),
            'createdAt' => $now,
            'expiresAt' => $now + $days * 86400,
            'revoked' => false,
        ];
        $all = $this->all();
        $all[] = $record;
        $this->write($all);
        return ['code' => implode('-', str_split($code, 5)), 'record' => $this->publicRecord($record)];
    }

    /** @return array<string,mixed>|null public record when the code is valid now */
    public function verify(string $code): ?array {
        $norm = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($norm) !== 20) {
            return null;
        }
        $hash = hash('sha256', $norm);
        $now = ($this->now)();
        foreach ($this->all() as $record) {
            if (hash_equals((string)$record['hash'], $hash) && empty($record['revoked']) && (int)$record['expiresAt'] > $now) {
                return $this->publicRecord($record);
            }
        }
        return null;
    }

    public function revoke(string $id): bool {
        $all = $this->all();
        foreach ($all as $i => $record) {
            if ($record['id'] === $id) {
                $all[$i]['revoked'] = true;
                $this->write($all);
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array {
        return array_map(fn (array $r): array => $this->publicRecord($r), $this->all());
    }

    /** @return list<array<string,mixed>> */
    private function all(): array {
        $decoded = json_decode((string)($this->load)(), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            $decoded,
            static fn ($r): bool => is_array($r) && isset($r['id'], $r['hash'], $r['expiresAt'], $r['label']),
        ));
    }

    /** @param list<array<string,mixed>> $all */
    private function write(array $all): void {
        ($this->save)((string)json_encode(array_values($all), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $record */
    private function publicRecord(array $record): array {
        unset($record['hash']);
        return $record;
    }
}
```

- [ ] **Step 4: Run** `php server/tests/DesktopCodeStoreTest.php` → ends with `all passed`

- [ ] **Step 5: Commit** `git add server && git commit -m "feat(server): desktop code store"`

---

### Task 9: server DesktopSeenLog (duplicate guard)

**Files:**
- Create: `server/iephoto_watermark/lib/Service/DesktopSeenLog.php`
- Test: `server/tests/DesktopSeenLogTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../iephoto_watermark/lib/Service/DesktopSeenLog.php';

use OCA\IEPhotoWatermark\Service\DesktopSeenLog;

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "ok - $msg\n"; }

$dir = sys_get_temp_dir() . '/seen-' . bin2hex(random_bytes(4));
$log = new DesktopSeenLog($dir);
$sha = str_repeat('a', 64);
check($log->find('abc123', $sha) === null, 'unknown sha');
$log->remember('abc123', $sha, 'IEPHOTO@KMTIL_0001.jpg');
check((new DesktopSeenLog($dir))->find('abc123', $sha) === 'IEPHOTO@KMTIL_0001.jpg', 'persisted');
check($log->find('other1', $sha) === null, 'scoped per code');
$threw = false; try { $log->remember('../x', $sha, 'n'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'bad code id rejected');
$threw = false; try { $log->find('abc123', 'zz'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'bad sha rejected');
echo "all passed\n";
```

- [ ] **Step 2: Run** `php server/tests/DesktopSeenLogTest.php` → FAIL

- [ ] **Step 3: Implement** `server/iephoto_watermark/lib/Service/DesktopSeenLog.php`

```php
<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

/**
 * Remembers which source file (sha256) a desktop code already delivered, so a retry after a
 * lost response returns the same name instead of creating a second copy.
 */
final class DesktopSeenLog {
    public function __construct(private string $dir) {
    }

    public function find(string $codeId, string $sha256): ?string {
        $this->validate($codeId, $sha256);
        $map = $this->read($this->file($codeId));
        return $map[$sha256] ?? null;
    }

    public function remember(string $codeId, string $sha256, string $name): void {
        $this->validate($codeId, $sha256);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        $file = $this->file($codeId);
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('cannot open seen log');
        }
        try {
            flock($fp, LOCK_EX);
            $map = json_decode((string)stream_get_contents($fp), true);
            $map = is_array($map) ? $map : [];
            $map[$sha256] = $name;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function file(string $codeId): string {
        return rtrim($this->dir, '/') . '/' . $codeId . '.json';
    }

    /** @return array<string,string> */
    private function read(string $file): array {
        if (!is_file($file)) {
            return [];
        }
        $map = json_decode((string)file_get_contents($file), true);
        return is_array($map) ? $map : [];
    }

    private function validate(string $codeId, string $sha256): void {
        if (preg_match('/^[a-f0-9]{6,32}$/', $codeId) !== 1) {
            throw new \InvalidArgumentException('bad code id');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('bad sha256');
        }
    }
}
```

- [ ] **Step 4: Run** `php server/tests/DesktopSeenLogTest.php` → `all passed`

- [ ] **Step 5: Commit** `git add server && git commit -m "feat(server): desktop seen log"`

---

### Task 10: UploadService — shared writer + desktop store

**Files:**
- Modify: `server/iephoto_watermark/lib/Service/UploadService.php`

- [ ] **Step 1: Extract the write loop.** In `processUpload()`, replace the block that starts at `$written = false;` and ends at the closing `}` of `if (!$written) { … }` (current lines 234–274) with:

```php
            $name = $this->writeWithNewName($target, $outputPath, $extension);
```

and add this private method right after `processUpload()`:

```php
    /**
     * Write $localPath into $target under a freshly allocated IEPHOTO@KMTIL_#### name.
     * Each allocated name is unique (allocateName is atomic); the loop only re-allocates if a
     * name somehow already exists, and tolerates the files_versions hook throwing after the
     * bytes have already landed.
     */
    private function writeWithNewName(Folder $target, string $localPath, string $extension): string {
        $name = $this->allocateName($extension);
        for ($attempt = 0; $attempt < 25; $attempt++) {
            if ($target->nodeExists($name)) {
                $name = $this->allocateName($extension);
                continue;
            }
            $stream = fopen($localPath, 'rb');
            if (!is_resource($stream)) {
                throw new \RuntimeException('อ่านภาพผลลัพธ์ไม่สำเร็จ');
            }
            try {
                $target->newFile($name, $stream);
                return $name;
            } catch (\Throwable $e) {
                if ($target->nodeExists($name)) {
                    return $name;
                }
                throw $e;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        throw new \RuntimeException('บันทึกไฟล์ผลลัพธ์ไม่สำเร็จ');
    }
```

- [ ] **Step 2: Add desktop methods** after `writeWithNewName()`:

```php
    /** @return list<array{name:string,colour:string}> */
    public function desktopSports(): array {
        return array_map(
            fn (array $s): array => ['name' => $s['name'], 'colour' => $this->colourForVenue($s['venue'])],
            $this->publicSports(),
        );
    }

    public function colourForAlbum(string $album): string {
        return $this->colourForVenue($this->venueForSport($album));
    }

    /** @return list<string> */
    public function watermarkPositions(): array {
        return self::WATERMARK_POSITIONS;
    }

    /**
     * Store a photo the desktop app already watermarked: same checks, folder rules and naming as
     * processUpload() with a sport, but no watermark() and no originals archive (the desktop
     * never sends the unwatermarked original).
     *
     * @return array{name:string,folder:string}
     */
    public function storeDesktopUpload(
        string $tmpPath,
        string $originalName,
        string $uploaderName,
        string $album,
        string $watermarkPosition,
    ): array {
        if (!is_file($tmpPath)) {
            throw new \RuntimeException('ไม่พบไฟล์ชั่วคราว');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \RuntimeException('รองรับเฉพาะ JPG, JPEG, PNG และ WebP');
        }
        if (@getimagesize($tmpPath) === false || !$this->isCompleteImage($tmpPath, $extension)) {
            throw new \RuntimeException('ไฟล์ไม่สมบูรณ์ ลองส่งใหม่');
        }
        $uploaderName = $this->normalizeUploaderName($uploaderName);
        if (!in_array($watermarkPosition, self::WATERMARK_POSITIONS, true)) {
            throw new \RuntimeException('ตำแหน่งลายน้ำไม่ถูกต้อง');
        }
        $albumFolder = $this->resolveSportFolder($album);       // registry only: unknown name throws
        $target = $this->uploaderTargetFolder($uploaderName, $albumFolder);
        $name = $this->writeWithNewName($target, $tmpPath, $extension);
        $this->recordHistory($originalName, $name, $uploaderName, $watermarkPosition);
        $this->logMapping([
            'via' => 'desktop',
            'source' => basename($originalName),
            'output' => $name,
            'folder' => $uploaderName,
            'album' => $album,
            'position' => $watermarkPosition,
        ]);
        return ['name' => $name, 'folder' => $uploaderName];
    }
```

- [ ] **Step 3: Lint** `php -l server/iephoto_watermark/lib/Service/UploadService.php` → `No syntax errors detected`

- [ ] **Step 4: Commit** `git add server && git commit -m "refactor(server): shared writer; add desktop store path"`

---

### Task 11: DesktopController + routes + DI

**Files:**
- Create: `server/iephoto_watermark/lib/Controller/DesktopController.php`
- Modify: `server/iephoto_watermark/appinfo/routes.php`, `server/iephoto_watermark/lib/AppInfo/Application.php`

- [ ] **Step 1: Controller**

```php
<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use OCA\IEPhotoWatermark\Service\DesktopSeenLog;
use OCA\IEPhotoWatermark\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Endpoints for the IE-PHOTO Uploader desktop app. Auth = per-event code in X-IEPhoto-Code. */
final class DesktopController extends Controller {
    private const MAX_BYTES = 95 * 1024 * 1024;

    public function __construct(
        string $appName,
        IRequest $request,
        private UploadService $uploads,
        private DesktopCodeStore $codes,
        private DesktopSeenLog $seen,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return array<string,mixed>|null */
    private function codeRecord(): ?array {
        return $this->codes->verify((string)$this->request->getHeader('X-IEPhoto-Code'));
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 300)]
    public function config(): JSONResponse {
        $record = $this->codeRecord();
        if ($record === null) {
            return new JSONResponse(['error' => 'invalid_code'], 401);
        }
        $album = (string)($record['album'] ?? '');
        $sports = $album !== ''
            ? [['name' => $album, 'colour' => $this->uploads->colourForAlbum($album)]]
            : $this->uploads->desktopSports();
        $logo = dirname(__DIR__, 2) . '/img/watermark.png';
        return new JSONResponse([
            'label' => $record['label'],
            'album' => $album,
            'expiresAt' => $record['expiresAt'],
            'positions' => $this->uploads->watermarkPositions(),
            'defaultPosition' => 'top-left',
            'sports' => $sports,
            'logoUrl' => '/custom_apps/iephoto_watermark/img/watermark.png',
            'logoSha256' => is_file($logo) ? hash_file('sha256', $logo) : '',
            'widthRatio' => 0.07,
            'marginRatio' => 0.02,
            'jpegQuality' => 96,
            'maxUploadMb' => 95,
        ]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 3000, period: 300)]
    public function upload(): JSONResponse {
        $record = $this->codeRecord();
        if ($record === null) {
            return new JSONResponse(['error' => 'invalid_code'], 401);
        }
        $file = $this->request->getUploadedFile('photo');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            return new JSONResponse(['error' => 'ไม่พบไฟล์ที่อัปโหลด'], 422);
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return new JSONResponse(['error' => 'ไฟล์ใหญ่เกิน 95MB'], 413);
        }
        $sha = strtolower((string)$this->request->getParam('sha256', ''));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return new JSONResponse(['error' => 'sha256 ไม่ถูกต้อง'], 422);
        }
        $existing = $this->seen->find((string)$record['id'], $sha);
        if ($existing !== null) {
            return new JSONResponse(['name' => $existing, 'duplicate' => true]);
        }
        $album = (string)($record['album'] ?? '') !== '' ? (string)$record['album'] : (string)$this->request->getParam('sport', '');
        try {
            $stored = $this->uploads->storeDesktopUpload(
                $file['tmp_name'],
                (string)$this->request->getParam('originalName', (string)($file['name'] ?? 'photo.jpg')),
                (string)$this->request->getParam('uploader', ''),
                $album,
                (string)$this->request->getParam('position', 'top-left'),
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        }
        $this->seen->remember((string)$record['id'], $sha, $stored['name']);
        return new JSONResponse(['name' => $stored['name'], 'folder' => $stored['folder'], 'duplicate' => false]);
    }
}
```

- [ ] **Step 2: Routes** — in `appinfo/routes.php` add after the `upload#sports` line:

```php
        ['name' => 'desktop#config', 'url' => '/desktop/config', 'verb' => 'GET'],
        ['name' => 'desktop#upload', 'url' => '/desktop/upload', 'verb' => 'POST'],
        ['name' => 'admin#desktopCodes', 'url' => '/admin/desktop-codes', 'verb' => 'GET'],
        ['name' => 'admin#createDesktopCode', 'url' => '/admin/desktop-codes', 'verb' => 'POST'],
        ['name' => 'admin#revokeDesktopCode', 'url' => '/admin/desktop-codes/{id}/revoke', 'verb' => 'POST'],
```

- [ ] **Step 3: DI** — in `lib/AppInfo/Application.php` add imports and registrations:

```php
use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use OCA\IEPhotoWatermark\Service\DesktopSeenLog;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
```

inside `register()` after the listener line:

```php
        $context->registerService(DesktopCodeStore::class, static function (ContainerInterface $c): DesktopCodeStore {
            $config = $c->get(IConfig::class);
            return new DesktopCodeStore(
                static fn (): string => $config->getAppValue(self::APP_ID, 'desktop_codes', '[]'),
                static function (string $json) use ($config): void { $config->setAppValue(self::APP_ID, 'desktop_codes', $json); },
                static fn (): int => time(),
            );
        });
        $context->registerService(DesktopSeenLog::class, static function (ContainerInterface $c): DesktopSeenLog {
            $dir = rtrim((string)$c->get(IConfig::class)->getSystemValue('datadirectory', ''), '/');
            return new DesktopSeenLog($dir . '/iephoto_watermark/desktop_seen');
        });
```

- [ ] **Step 4: Lint all touched PHP**

```bash
for f in lib/Controller/DesktopController.php appinfo/routes.php lib/AppInfo/Application.php; do php -l server/iephoto_watermark/$f; done
```
Expected: three `No syntax errors detected`

- [ ] **Step 5: Commit** `git add server && git commit -m "feat(server): desktop config/upload endpoints"`

---

### Task 12: occ command (lets Claude create test codes without logging in)

**Files:**
- Create: `server/iephoto_watermark/lib/Command/DesktopCode.php`
- Modify: `server/iephoto_watermark/appinfo/info.xml`

- [ ] **Step 1: Command**

```php
<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Command;

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DesktopCode extends Command {
    public function __construct(private DesktopCodeStore $codes) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('iephoto:desktop-code')
            ->setDescription('Create, list or revoke IE-PHOTO Uploader codes')
            ->addArgument('action', InputArgument::REQUIRED, 'create | list | revoke')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'event name')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'bind to one registered album/sport', '')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'valid for N days (1-90)', '7')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'code id to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        switch ((string)$input->getArgument('action')) {
            case 'create':
                $made = $this->codes->create((string)$input->getOption('label'), (string)$input->getOption('album'), (int)$input->getOption('days'));
                $output->writeln('code: ' . $made['code']);
                $output->writeln('id:   ' . $made['record']['id']);
                $output->writeln('expires: ' . date('Y-m-d H:i', (int)$made['record']['expiresAt']));
                return 0;
            case 'list':
                foreach ($this->codes->list() as $r) {
                    $output->writeln(sprintf('%s  %-30s album=%-15s expires=%s%s', $r['id'], $r['label'], $r['album'] ?: '-', date('Y-m-d', (int)$r['expiresAt']), $r['revoked'] ? '  REVOKED' : ''));
                }
                return 0;
            case 'revoke':
                $ok = $this->codes->revoke((string)$input->getOption('id'));
                $output->writeln($ok ? 'revoked' : 'not found');
                return $ok ? 0 : 1;
        }
        $output->writeln('unknown action');
        return 1;
    }
}
```

- [ ] **Step 2: Register** in `appinfo/info.xml` before `</info>`:

```xml
    <commands>
        <command>OCA\IEPhotoWatermark\Command\DesktopCode</command>
    </commands>
```

and set `<version>1.55.0</version>`.

- [ ] **Step 3: Lint** `php -l server/iephoto_watermark/lib/Command/DesktopCode.php` → no errors

- [ ] **Step 4: Commit** `git add server && git commit -m "feat(server): occ iephoto:desktop-code"`

---

### Task 13: admin UI section

**Files:**
- Modify: `server/iephoto_watermark/lib/Controller/AdminController.php`, `server/iephoto_watermark/templates/admin.php`
- Create: `server/iephoto_watermark/js/admin-desktop.js`

- [ ] **Step 1: Controller** — add constructor arg `private \OCA\IEPhotoWatermark\Service\DesktopCodeStore $desktopCodes,` after `$urlGenerator`, add to the `index()` template params:

```php
            'desktopCodesUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.desktopCodes'),
```

and add methods:

```php
    public function desktopCodes(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        return new JSONResponse(['codes' => array_reverse($this->desktopCodes->list()), 'albums' => $this->service->desktopSports()]);
    }

    public function createDesktopCode(string $label = '', string $album = '', int $days = 7): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        try {
            return new JSONResponse($this->desktopCodes->create($label, $album, $days));
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function revokeDesktopCode(string $id = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        return new JSONResponse(['revoked' => $this->desktopCodes->revoke($id)]);
    }
```

- [ ] **Step 2: Template** — in `templates/admin.php` add `script('iephoto_watermark', 'admin-desktop');` after `script('iephoto_watermark', 'admin');`, add `data-desktop-codes-url="<?= $e($_['desktopCodesUrl'] ?? '') ?>"` to `#iephoto-admin`, and insert before `<section class="iep-card iep-requests">`:

```php
    <section class="iep-card" data-desktop-section>
      <h2>รหัสสำหรับโปรแกรม IE-PHOTO Uploader</h2>
      <p class="iep-help">ช่างภาพใส่รหัสนี้ในโปรแกรมบนเครื่องตัวเอง โปรแกรมใส่ลายน้ำเองแล้วส่งขึ้นมา เซิร์ฟเวอร์แค่ตั้งชื่อ · รหัสแสดงครั้งเดียว คัดลอกเก็บไว้</p>
      <div class="iep-row">
        <label class="iep-albums-field"><span>ชื่องาน</span><input type="text" data-dc-label maxlength="80" placeholder="เช่น ChalkGames 2569"></label>
        <label class="iep-albums-field"><span>ล็อกอัลบั้ม (ไม่บังคับ)</span><select data-dc-album><option value="">ให้ช่างภาพเลือกกีฬาเอง</option></select></label>
        <label class="iep-albums-field"><span>ใช้ได้กี่วัน</span><input type="number" data-dc-days min="1" max="90" value="7"></label>
        <button type="button" class="iep-primary" data-dc-create>สร้างรหัส</button>
      </div>
      <div class="iep-status" data-dc-status role="status" aria-live="polite"></div>
      <div class="iep-albums-list" data-dc-list><p class="iep-help">กำลังโหลด…</p></div>
    </section>
```

- [ ] **Step 3: `js/admin-desktop.js`**

```javascript
(function () {
  'use strict';
  const root = document.getElementById('iephoto-admin');
  const section = root && root.querySelector('[data-desktop-section]');
  if (!section) return;
  const url = root.dataset.desktopCodesUrl;
  const csrf = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';
  const $ = (s) => section.querySelector(s);
  const status = $('[data-dc-status]');
  const list = $('[data-dc-list]');
  const albumSel = $('[data-dc-album]');

  async function api(path, opts = {}) {
    opts.headers = Object.assign({ requesttoken: csrf, Accept: 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    const res = await fetch(path, opts);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'เกิดข้อผิดพลาด');
    return data;
  }

  function render(codes) {
    list.replaceChildren();
    if (!codes.length) { list.innerHTML = '<p class="iep-help">ยังไม่มีรหัส</p>'; return; }
    const now = Date.now() / 1000;
    codes.forEach((c) => {
      const row = document.createElement('div');
      row.className = 'iep-link';
      const state = c.revoked ? 'ยกเลิกแล้ว' : (c.expiresAt < now ? 'หมดอายุ' : 'ใช้ได้ถึง ' + new Date(c.expiresAt * 1000).toLocaleDateString('th-TH'));
      const text = document.createElement('span');
      text.textContent = c.label + (c.album ? ' · ' + c.album : '') + ' · ' + state;
      row.appendChild(text);
      if (!c.revoked && c.expiresAt > now) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'iep-secondary'; btn.textContent = 'ยกเลิกรหัส';
        btn.addEventListener('click', async () => {
          if (!confirm('ยกเลิกรหัส "' + c.label + '"? โปรแกรมที่ใช้รหัสนี้จะส่งรูปไม่ได้ทันที')) return;
          await api(url + '/' + encodeURIComponent(c.id) + '/revoke', { method: 'POST' });
          load();
        });
        row.appendChild(btn);
      }
      list.appendChild(row);
    });
  }

  async function load() {
    try {
      const data = await api(url);
      const current = albumSel.value;
      albumSel.querySelectorAll('option:not([value=""])').forEach((o) => o.remove());
      data.albums.forEach((a) => { const o = document.createElement('option'); o.value = a.name; o.textContent = a.name; albumSel.appendChild(o); });
      albumSel.value = current;
      render(data.codes);
    } catch (e) { list.textContent = e.message; }
  }

  $('[data-dc-create]').addEventListener('click', async () => {
    status.textContent = 'กำลังสร้าง…';
    const body = new URLSearchParams({ label: $('[data-dc-label]').value, album: albumSel.value, days: $('[data-dc-days]').value });
    try {
      const made = await api(url, { method: 'POST', body });
      status.textContent = 'รหัส: ' + made.code + '  (แสดงครั้งเดียว คัดลอกเก็บไว้)';
      $('[data-dc-label]').value = '';
      load();
    } catch (e) { status.textContent = e.message; }
  });

  load();
})();
```

- [ ] **Step 4: Lint** `php -l server/iephoto_watermark/lib/Controller/AdminController.php; php -l server/iephoto_watermark/templates/admin.php`; `node --check server/iephoto_watermark/js/admin-desktop.js` → no errors

- [ ] **Step 5: Commit** `git add server && git commit -m "feat(server): admin section for uploader codes"`

---

### Task 14: deploy server 1.55.0 + smoke test (never shut the NAS down)

**Files:**
- Create: `server/tests/smoke.sh`

- [ ] **Step 1: Write `server/tests/smoke.sh`**

```bash
#!/usr/bin/env bash
# Usage: CODE=XXXXX-... SPORT=<registered sport> server/tests/smoke.sh
set -euo pipefail
BASE=${BASE:-https://nextcloud.ienas.site}/apps/iephoto_watermark
: "${CODE:?set CODE}"; : "${SPORT:?set SPORT}"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
python - "$TMP/p.jpg" <<'PY'
import sys
from PIL import Image
Image.new("RGB", (800, 600), (90, 140, 200)).save(sys.argv[1], "JPEG", quality=95)
PY
SHA=$(sha256sum "$TMP/p.jpg" | cut -d' ' -f1)
echo "1 bad code -> 401";  test "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-IEPhoto-Code: AAAAA-AAAAA-AAAAA-AAAAA' "$BASE/desktop/config")" = 401
echo "2 config -> 200";    curl -sf -H "X-IEPhoto-Code: $CODE" "$BASE/desktop/config" | python -c "import sys,json; d=json.load(sys.stdin); assert d['sports'], d; print(' ', d['label'], len(d['sports']), 'sports')"
up() { curl -s -H "X-IEPhoto-Code: $CODE" -F "photo=@$TMP/p.jpg" -F "sha256=$SHA" -F "uploader=ทดสอบโปรแกรม" -F "sport=$SPORT" -F position=top-left -F originalName=p.jpg "$BASE/desktop/upload"; }
echo "3 upload";           R1=$(up); echo "  $R1"; echo "$R1" | grep -q '"duplicate":false'
echo "4 re-upload same";   R2=$(up); echo "  $R2"; echo "$R2" | grep -q '"duplicate":true'
N1=$(echo "$R1" | python -c "import sys,json; print(json.load(sys.stdin)['name'])"); N2=$(echo "$R2" | python -c "import sys,json; print(json.load(sys.stdin)['name'])")
test "$N1" = "$N2" && echo "  same name $N1"
echo "5 old /upload still public"; test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sports")" = 200
echo "SMOKE OK"
```

- [ ] **Step 2: Backup live app (on NAS)**

```bash
ssh nas@100.116.118.109 'sudo cp -a /mnt/Storage1_sdb1/AppData/nextcloud/html/custom_apps/iephoto_watermark /home/nas/backup-iephoto-1.54.2-pre-desktop && echo backed'
```
Expected: `backed`

- [ ] **Step 3: Deploy** (rsync-free: tar over ssh, keep ownership 33)

```bash
cd /c/Users/weerapat/ie-photo-uploader/server && tar czf - iephoto_watermark | ssh nas@100.116.118.109 'cd /tmp && rm -rf iephoto_watermark && tar xzf - && sudo cp -a iephoto_watermark/. /mnt/Storage1_sdb1/AppData/nextcloud/html/custom_apps/iephoto_watermark/ && sudo chown -R 33:33 /mnt/Storage1_sdb1/AppData/nextcloud/html/custom_apps/iephoto_watermark && docker exec -u www-data nextcloud php occ upgrade | tail -1 && docker exec -u www-data nextcloud php occ app:list | grep iephoto_watermark'
```
Expected: `iephoto_watermark: 1.55.0`

- [ ] **Step 4: Create a test album + code via occ**

Pick a test album: create it through the existing service by running (registers venue outdoor, makes folder + shares):
```bash
ssh nas@100.116.118.109 "docker exec -u www-data nextcloud php -r 'require \"/var/www/html/lib/base.php\"; \$s=\\OC::\$server->get(\\OCA\\IEPhotoWatermark\\Service\\UploadService::class); print_r(\$s->createAlbum(\"ทดสอบ Uploader\",\"outdoor\"));'"
ssh nas@100.116.118.109 'docker exec -u www-data nextcloud php occ iephoto:desktop-code create --label "ทดสอบ Uploader" --album "ทดสอบ Uploader" --days 3'
```
Expected: second command prints `code: XXXXX-XXXXX-XXXXX-XXXXX`. Keep it only in the shell session (do not commit it).

- [ ] **Step 5: Smoke test**

```bash
cd /c/Users/weerapat/ie-photo-uploader && CODE=<code from step 4> SPORT="ทดสอบ Uploader" bash server/tests/smoke.sh
```
Expected: ends with `SMOKE OK`. Also open `https://nextcloud.ienas.site/apps/iephoto_watermark/home` and an album page: still 200 (no regression).

- [ ] **Step 6: Commit** `git add server/tests/smoke.sh && git commit -m "test(server): desktop endpoint smoke script"`

---

### Task 15: watermark parity vs server Imagick

**Files:**
- Create: `server/tests/parity/server_watermark.php`, `client/tests/parity_compare.py`

- [ ] **Step 1: `server/tests/parity/server_watermark.php`** (copy of the server algorithm, CLI)

```php
<?php
// php server_watermark.php in.jpg out.jpg logo.png position opacity colour
[$_, $in, $out, $logoPath, $position, $opacity, $colour] = $argv;
$image = new Imagick($in); $image->autoOrient(); $image->setIteratorIndex(0); $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
$logo = new Imagick($logoPath); $logo->setIteratorIndex(0); $logo->trimImage(0);
$tw = max(1, (int)round($image->getImageWidth() * 0.07)); $th = max(1, (int)round($logo->getImageHeight() * ($tw / $logo->getImageWidth())));
$logo->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
$paint = new Imagick(); $paint->newImage($tw, $th, new ImagickPixel($colour === 'white' ? 'white' : 'black')); $paint->setImageFormat('png');
$paint->setImageColorspace(Imagick::COLORSPACE_SRGB); $paint->compositeImage($logo, Imagick::COMPOSITE_COPYOPACITY, 0, 0); $logo = $paint;
$f = max(0.10, min(1.0, ((int)$opacity) / 100)); if ($f < 1.0) { $logo->evaluateImage(Imagick::EVALUATE_MULTIPLY, $f, Imagick::CHANNEL_ALPHA); }
$w = $image->getImageWidth(); $h = $image->getImageHeight(); $m = (int)round(min($w, $h) * 0.02);
$x = str_ends_with($position, '-left') ? $m : (str_ends_with($position, '-right') ? max(0, $w - $tw - $m) : max(0, (int)round(($w - $tw) / 2)));
$y = str_starts_with($position, 'top-') ? $m : (str_starts_with($position, 'bottom-') ? max(0, $h - $th - $m) : max(0, (int)round(($h - $th) / 2)));
$image->compositeImage($logo, Imagick::COMPOSITE_OVER, $x, $y);
$image->setImageFormat('png'); $image->writeImage($out);   // lossless so only the algorithm is compared
echo "$x $y $tw $th\n";
```

- [ ] **Step 2: `client/tests/parity_compare.py`** (run manually, not in pytest)

```python
"""Compare client watermark with server Imagick on real photos.
Usage: python tests/parity_compare.py <folder with a few jpgs> <logo.png>
Runs the PHP reference inside the nextcloud container over ssh."""
import io, subprocess, sys
from pathlib import Path
from PIL import Image, ImageChops, ImageStat
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from ie_uploader import watermark as wm

SSH = ["ssh", "nas@100.116.118.109"]
folder, logo = Path(sys.argv[1]), Path(sys.argv[2])
subprocess.run(SSH + ["mkdir -p /tmp/parity"], check=True)
subprocess.run(["scp", "-q", str(Path(__file__).parents[2] / "server/tests/parity/server_watermark.php"), str(logo), "nas@100.116.118.109:/tmp/parity/"], check=True)
worst = 0.0
for src in sorted(folder.glob("*.jpg"))[:6]:
    for pos in ("top-left", "center", "bottom-right"):
        for colour in ("black", "white"):
            subprocess.run(["scp", "-q", str(src), "nas@100.116.118.109:/tmp/parity/in.jpg"], check=True)
            cmd = "docker cp /tmp/parity nextcloud:/tmp/ && docker exec nextcloud php /tmp/parity/server_watermark.php /tmp/parity/in.jpg /tmp/parity/out.png /tmp/parity/" + logo.name + f" {pos} 100 {colour} && docker cp nextcloud:/tmp/parity/out.png /tmp/parity/out.png"
            subprocess.run(SSH + [cmd], check=True, capture_output=True)
            subprocess.run(["scp", "-q", "nas@100.116.118.109:/tmp/parity/out.png", "ref.png"], check=True)
            ours = Image.open(io.BytesIO(wm.apply(src, logo.read_bytes(), pos, 100, colour)[0])).convert("RGB")
            ref = Image.open("ref.png").convert("RGB")
            assert ours.size == ref.size, (src, ours.size, ref.size)
            diff = ImageStat.Stat(ImageChops.difference(ours, ref)).mean
            mean = sum(diff) / 3
            worst = max(worst, mean)
            print(f"{src.name:30} {pos:13} {colour:5} mean diff {mean:.2f}")
subprocess.run(SSH + ["rm -rf /tmp/parity; docker exec nextcloud rm -rf /tmp/parity"])
print("WORST", round(worst, 2), "PASS" if worst < 2.0 else "FAIL")
```

- [ ] **Step 3: Run with 6 real photos** (copy a few from the NAS test album or any local JPEGs)

```bash
cd client && .venv/Scripts/python tests/parity_compare.py "<folder of jpgs>" ../server/iephoto_watermark/img/watermark.png
```
Expected: last line `WORST <2.0 PASS`. Mean diff comes from JPEG re-encoding of the client output (quality 96); if it fails, fix `watermark.py` before going on.

- [ ] **Step 4: Commit** `git add server/tests/parity client/tests/parity_compare.py && git commit -m "test: watermark parity script against server Imagick"`

---

### Task 16: end-to-end on the real server

- [ ] **Step 1:** Make 200 test JPEGs:

```bash
cd client && .venv/Scripts/python -c "
from PIL import Image; from pathlib import Path
d=Path('e2e'); d.mkdir(exist_ok=True)
for i in range(200): Image.new('RGB',(4000,3000),(i%255,(i*7)%255,(i*13)%255)).save(d/f'e2e_{i:03}.jpg','JPEG',quality=92)"
```

- [ ] **Step 2:** Run the app (`.venv/Scripts/python -m ie_uploader`), enter the test code from Task 14 and name `ทดสอบโปรแกรม`, drop `client/e2e`, start. Expected: 200 sent, 0 failed, rate shown.
- [ ] **Step 3:** Start again on the same folder. Expected: `ข้าม (เคยส่งแล้ว) 200`, nothing uploaded.
- [ ] **Step 4:** Mid-run stop test: delete `%APPDATA%\IE-PHOTO Uploader\state-*.json`, start, press หยุด after ~30, start again. Expected: total uploaded = 200, server returns `duplicate:true` for the ones it already had (no extra `IEPHOTO@KMTIL_` files: check count with
`ssh nas@100.116.118.109 'ls "/mnt/Storage1_sdb1/AppData/nextcloud/data/<owner>/files/<albums root>/ทดสอบ Uploader/ทดสอบโปรแกรม" | wc -l'` → 201 including the smoke-test file).
- [ ] **Step 5:** Revoke the code: `occ iephoto:desktop-code revoke --id <id>`; app on next upload shows "รหัสงานไม่ถูกต้องหรือหมดอายุ".
- [ ] **Step 6:** Report the test album folder to the user and ask before deleting it (deletion is irreversible; do not delete without a yes).
- [ ] **Step 7:** `rm -rf client/e2e` (local test images only).

---

### Task 17: packaging + GitHub Actions release

**Files:**
- Create: `client/packaging/ie_uploader.spec`, `client/packaging/installer.iss`, `.github/workflows/release.yml`

- [ ] **Step 1: `client/packaging/ie_uploader.spec`**

```python
# PyInstaller spec. Build: pyinstaller packaging/ie_uploader.spec  (cwd = client/)
import sys
from PyInstaller.utils.hooks import collect_data_files

datas = collect_data_files("customtkinter") + collect_data_files("tkinterdnd2")
a = Analysis(["../ie_uploader/__main__.py"], pathex=[".."], datas=datas,
             hiddenimports=["keyring.backends.Windows", "keyring.backends.macOS"])
pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, [], exclude_binaries=True, name="IE-PHOTO Uploader",
          console=False, icon=None)
coll = COLLECT(exe, a.binaries, a.datas, name="IE-PHOTO Uploader")
if sys.platform == "darwin":
    app = BUNDLE(coll, name="IE-PHOTO Uploader.app", bundle_identifier="site.ienas.uploader")
```

- [ ] **Step 2: `client/packaging/installer.iss`**

```ini
#define AppVersion GetEnv("APP_VERSION")
[Setup]
AppName=IE-PHOTO Uploader
AppVersion={#AppVersion}
DefaultDirName={autopf}\IE-PHOTO Uploader
DefaultGroupName=IE-PHOTO Uploader
OutputDir=..\dist
OutputBaseFilename=IE-PHOTO-Uploader-Setup
PrivilegesRequired=lowest
Compression=lzma2
SolidCompression=yes
[Files]
Source: "..\dist\IE-PHOTO Uploader\*"; DestDir: "{app}"; Flags: recursesubdirs
[Icons]
Name: "{group}\IE-PHOTO Uploader"; Filename: "{app}\IE-PHOTO Uploader.exe"
Name: "{userdesktop}\IE-PHOTO Uploader"; Filename: "{app}\IE-PHOTO Uploader.exe"
```

- [ ] **Step 3: Local Windows build check**

```bash
cd client && .venv/Scripts/pyinstaller --noconfirm packaging/ie_uploader.spec && ls "dist/IE-PHOTO Uploader/IE-PHOTO Uploader.exe"
```
Expected: the exe exists; launching it opens the window.

- [ ] **Step 4: `.github/workflows/release.yml`**

```yaml
name: release
on:
  push:
    tags: ["v*"]
permissions:
  contents: write
jobs:
  build:
    strategy:
      matrix:
        include:
          - os: windows-latest
            artifact: client/dist/IE-PHOTO-Uploader-Setup.exe
          - os: macos-14          # Apple Silicon
            artifact: client/dist/IE-PHOTO-Uploader-arm64.dmg
          - os: macos-13          # Intel iMacs
            artifact: client/dist/IE-PHOTO-Uploader-intel.dmg
    runs-on: ${{ matrix.os }}
    defaults:
      run:
        working-directory: client
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with:
          python-version: "3.11"
      - run: python -m pip install -e ".[dev]"
      - run: python -m pytest -q
      - run: pyinstaller --noconfirm packaging/ie_uploader.spec
      - name: Windows installer
        if: runner.os == 'Windows'
        shell: pwsh
        env:
          APP_VERSION: ${{ github.ref_name }}
        run: |
          & "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe" packaging\installer.iss
      - name: macOS dmg
        if: runner.os == 'macOS'
        run: |
          ARCH=$([ "$(uname -m)" = arm64 ] && echo arm64 || echo intel)
          hdiutil create -volname "IE-PHOTO Uploader" -srcfolder "dist/IE-PHOTO Uploader.app" -ov -format UDZO "dist/IE-PHOTO-Uploader-$ARCH.dmg"
      - uses: softprops/action-gh-release@v2
        with:
          files: ${{ matrix.artifact }}
```

- [ ] **Step 5: Commit, push, tag**

```bash
cd /c/Users/weerapat/ie-photo-uploader && git add -A && git commit -m "build: PyInstaller, Inno Setup and release workflow" && git push && git tag v0.1.0 && git push origin v0.1.0
```

- [ ] **Step 6: Verify the release**

Run: `gh run watch --repo weerapat-s/ie-photo-uploader --exit-status $(gh run list --repo weerapat-s/ie-photo-uploader --limit 1 --json databaseId -q '.[0].databaseId')` then `gh release view v0.1.0 --repo weerapat-s/ie-photo-uploader`
Expected: three assets: `IE-PHOTO-Uploader-Setup.exe`, `IE-PHOTO-Uploader-arm64.dmg`, `IE-PHOTO-Uploader-intel.dmg`. Download the exe (ask the user first: name, source = the GitHub release, size), install, and launch once.

---

### Task 18: README (Thai user guide)

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write `README.md`**

```markdown
# IE-PHOTO Uploader

โปรแกรมใส่ลายน้ำบนเครื่องช่างภาพ แล้วส่งขึ้น IE-PHOTO เซิร์ฟเวอร์ไม่ต้องใส่ลายน้ำเอง จึงไม่ช้าเวลามีคนดูรูปเยอะ

## ติดตั้ง
- **Windows:** ดาวน์โหลด `IE-PHOTO-Uploader-Setup.exe` จากหน้า Releases แล้วเปิด ถ้าขึ้น "Windows protected your PC" กด **More info → Run anyway** (ไฟล์ยังไม่มีลายเซ็นดิจิทัล)
- **Mac:** เลือก `arm64` (Mac ชิป M) หรือ `intel` (iMac รุ่นเก่า) เปิด .dmg ลากแอปไป Applications ครั้งแรกให้ **คลิกขวาที่แอป → Open → Open**

## ใช้งาน
1. ขอ **รหัสงาน** จากแอดมิน (หน้า Admin → "รหัสสำหรับโปรแกรม")
2. ใส่รหัสงานกับชื่อตัวเอง กด "เชื่อมต่อ"
3. เลือกกีฬาและตำแหน่งลายน้ำ (สีลายน้ำเซิร์ฟเวอร์กำหนดให้ตามสนาม)
4. ลากโฟลเดอร์รูปมาวาง ดูตัวอย่าง แล้วกด "เริ่ม"
- ไฟล์ต้นฉบับในเครื่องไม่ถูกแก้
- ปิดโปรแกรมกลางคัน เปิดใหม่แล้วกดเริ่มกับโฟลเดอร์เดิม จะส่งเฉพาะรูปที่ยังไม่ได้ส่ง
- ถ้าเครื่องต่อ Tailscale ของ IE-PHOTO อยู่ โปรแกรมจะใช้ช่องทางด่วนให้เอง

## สำหรับผู้ดูแล
- สร้าง/ยกเลิกรหัส: หน้า Admin หรือ `occ iephoto:desktop-code create|list|revoke`
- โค้ดเซิร์ฟเวอร์อยู่ `server/iephoto_watermark` (deploy ตาม plan Task 14 สำรองก่อนทุกครั้ง **ห้ามปิด/รีบูต NAS**)
- ทดสอบ: `cd client && python -m pytest`, `php server/tests/*Test.php`, `server/tests/smoke.sh`
- ปล่อยเวอร์ชัน: `git tag vX.Y.Z && git push origin vX.Y.Z`
```

- [ ] **Step 2: Commit + push** `git add README.md && git commit -m "docs: Thai user guide" && git push`

---

## Self-review notes (done while writing)

- Spec coverage: §3.1 → Tasks 1–2; §3.2 → Task 4; §3.3 → Tasks 3, 5; §3.4 → Tasks 6–7; §4.1 → Tasks 8, 12, 13; §4.2/4.3 → Tasks 10–11; §4.4 → Tasks 8, 10 (registry-only folders); §5 → Tasks 0, 17; §6 → Tasks 1–6, 14–16; §7 → Task 0 amendments + README.
- Names used consistently: `Client.upload(data, original_name, sha256, uploader, sport, position)`, `UploadResult(ok, name, duplicate, error)`, `RunSettings(uploader, sport, position, opacity, colour, logo)`, `DesktopCodeStore::create/verify/revoke/list`, `DesktopSeenLog::find/remember`, `UploadService::storeDesktopUpload/desktopSports/colourForAlbum/watermarkPositions`.
- Destructive steps (deleting the test album) require the user's yes; NAS is never rebooted.
