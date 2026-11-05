# IE-PHOTO Uploader: ออกแบบ (Design Spec)

วันที่: 2026-11-06
สถานะ: รออนุมัติ spec

## 1. เป้าหมาย

ย้ายงานใส่ลายน้ำ (ส่วนที่กิน CPU มากที่สุด) จาก NAS ไปทำบนเครื่องช่างภาพ
เซิร์ฟเวอร์เหลือแค่ตั้งชื่อเลขรันกับวางไฟล์ ซึ่งเบามาก

**วัดผลสำเร็จ:**
- รูปจากโปรแกรมมีลายน้ำตำแหน่ง ขนาด สี และคุณภาพเหมือนรูปที่เซิร์ฟเวอร์ทำเอง
- ชื่อไฟล์ยังเป็นเลขรัน `IEPHOTO@KMTIL_xxxx` ต่อเนื่องเลขเดียวกับระบบเดิม ไม่ชนกัน
- ส่ง 1,000 รูปได้โดยไม่ติดเพดานจำนวนครั้ง และไม่มีรูปหายหรือซ้ำ แม้เน็ตหลุดหรือปิดโปรแกรมกลางคัน
- ติดตั้งได้บน Windows 10/11 และ macOS 12 ขึ้นไป

**ไม่อยู่ในขอบเขต:** แก้รูป (ปรับแสง/ครอป), อ่านไฟล์ RAW, แอปมือถือ, ลงลายเซ็นดิจิทัลให้ไฟล์ติดตั้ง

## 2. ภาพรวม

```
เครื่องช่างภาพ                                    NAS (Nextcloud + iephoto_watermark)
┌──────────────────────────────┐   HTTPS / Tailscale   ┌──────────────────────────────┐
│ app.py (หน้าจอ)                │ ─ GET /desktop/config ─▶ │ DesktopController             │
│ pipeline.py (คิวงาน)            │                       │  - ตรวจรหัสงาน                 │
│  ├ watermark.py × N คอร์       │ ─ POST /desktop/upload ▶ │  - กันไฟล์ซ้ำ (SHA-256)          │
│  └ uploader.py × 3 การเชื่อมต่อ  │                       │  - allocateName() เดิม          │
│ state.json (จำรูปที่ส่งแล้ว)      │                       │  - recordHistory() เดิม         │
└──────────────────────────────┘                       └──────────────────────────────┘
```

## 3. โปรแกรมในเครื่อง (client/)

Python 3.11, CustomTkinter, Pillow, requests. แพ็กด้วย PyInstaller

### 3.1 `watermark.py` ฟังก์ชันล้วน ไม่ยุ่งกับเน็ตหรือหน้าจอ

`apply(src_path, logo_png_bytes, position, opacity, colour) -> bytes`

ต้องตรงกับ `UploadService::watermark()` บนเซิร์ฟเวอร์ทุกค่า:

| ค่า | ค่าที่ใช้ |
|---|---|
| หมุนรูป | `ImageOps.exif_transpose` (ตรงกับ `autoOrient`) |
| โลโก้ | ตัดขอบโปร่งใสออก (`trimImage`) แล้วย่อให้กว้าง `round(กว้างรูป × 0.07)` แบบ LANCZOS |
| สี | สร้างภาพสีเดียว (`black` หรือ `white`) แล้วใช้ alpha ของโลโก้เดิม |
| ความเข้ม | คูณ alpha ด้วย `clamp(opacity/100, 0.10, 1.0)` |
| ระยะขอบ | `round(min(กว้าง, สูง) × 0.02)` |
| ตำแหน่ง | 9 ค่า: `top-left`, `top-center`, `top-right`, `middle-left`, `center`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right` แนวกลางใช้ `round((รูป − โลโก้)/2)` และไม่ให้ติดลบ |
| ไฟล์ที่ได้ | JPEG quality 96, `subsampling=0` (4:4:4), ใส่ ICC profile เดิมกลับ, ไม่ใส่ EXIF อื่น (ลบ GPS/serial) |
| นามสกุลที่รับ | `.jpg`, `.jpeg`, `.png`, `.webp`, `.heic` (HEIC ผ่าน `pillow-heif`) ส่วน PNG/WEBP ส่งเป็นนามสกุลเดิม |

### 3.2 `uploader.py`

- `upload(file_bytes, meta) -> UploadResult` ใช้ `requests.Session` ใช้การเชื่อมต่อซ้ำ (keep-alive)
- ส่งพร้อมกัน 3 รูป (ThreadPoolExecutor)
- ลองใหม่เมื่อเจอข้อผิดพลาดเน็ต, HTTP 5xx, 429 รอ 5, 15, 45 วินาที ครบ 3 ครั้งแล้วพักทั้งคิวและลองต่อทุก 60 วินาที
- เจอ HTTP 401/403 (รหัสผิดหรือหมดอายุ): หยุดทั้งคิว ไม่ลองใหม่
- เจอ HTTP 415/422 (ไฟล์เสีย): บันทึกเป็น "ส่งไม่สำเร็จ" แล้วไปรูปถัดไป
- เลือกที่อยู่เซิร์ฟเวอร์: ถ้า `GET http://100.116.118.109:7580/status.php` ตอบภายใน 1.5 วินาที ใช้ช่องทาง Tailscale ถ้าไม่ตอบใช้ `https://nextcloud.ienas.site`

### 3.3 `pipeline.py`

- คิวงาน: สแกนโฟลเดอร์ (รวมโฟลเดอร์ย่อย) เรียงตามชื่อไฟล์
- ใส่ลายน้ำด้วย `ProcessPoolExecutor(max_workers=max(1, cpu_count-1))` เสร็จรูปไหนส่งรูปนั้นทันที
- คุมไม่ให้รูปที่ใส่ลายน้ำแล้วแต่ยังไม่ได้ส่ง ค้างเกิน 12 รูป (กันแรมเต็ม)
- `state.json` เก็บต่อรหัสงาน: `{ sha256 ของไฟล์ต้นฉบับ: ชื่อที่ได้จากเซิร์ฟเวอร์ }` อยู่ในโฟลเดอร์ข้อมูลผู้ใช้ (`%APPDATA%\IE-PHOTO Uploader`, `~/Library/Application Support/IE-PHOTO Uploader`)
- เริ่มงานใหม่แล้วข้ามรูปที่ sha256 อยู่ใน state แล้ว

### 3.4 `app.py` (หน้าจอ)

- ตั้งค่าครั้งแรก: รหัสงาน และชื่อช่างภาพ เก็บใน `settings.json` รหัสงานเก็บผ่าน `keyring` ของระบบ ไม่เก็บเป็นข้อความธรรมดา
- หน้าหลัก: เลือกกีฬา (จาก config) แสดงสีลายน้ำที่เซิร์ฟเวอร์กำหนดให้, เลือกตำแหน่ง, ปรับความเข้ม, พื้นที่ลากโฟลเดอร์มาวาง (`tkinterdnd2`), ตัวอย่างรูปแรก, ปุ่มเริ่ม/หยุด/ส่งต่อ
- ระหว่างทำงาน: แถบความคืบหน้า, ส่งแล้ว X/Y, รูปต่อวินาที, เวลาที่เหลือโดยประมาณ, รายการส่งไม่สำเร็จพร้อมเหตุผลและปุ่ม "ลองใหม่"
- ข้อความภาษาไทยทั้งหมด

## 4. ฝั่งเซิร์ฟเวอร์ (server/, แอป iephoto_watermark)

### 4.1 รหัสงาน (desktop code)

- แอดมินสร้างในหน้า `/admin` ส่วนใหม่ "รหัสสำหรับโปรแกรม" ระบุชื่องาน, วันหมดอายุ (ค่าเริ่มต้น 7 วัน), โฟลเดอร์ปลายทาง (ต้องอยู่ใน scan scope เดิม)
- รหัสสุ่ม 20 ตัวอักษร base32 แสดงให้แอดมินเห็นครั้งเดียว
- เก็บใน app config `desktop_codes` เป็น JSON: `[{id, label, hash: sha256(code), folder, expiresAt, createdAt, revoked}]`
- แอดมินยกเลิกรหัสได้

### 4.2 `GET /desktop/config` (PublicPage, header `X-IEPhoto-Code`)

คืนค่า:
```json
{ "label": "ChalkGames 2569", "expiresAt": 1794000000,
  "positions": ["top-left", "..."], "defaultPosition": "top-left",
  "sports": [{"name": "ฟุตบอล", "colour": "black"}, {"name": "บาส", "colour": "white"}],
  "logoUrl": "/custom_apps/iephoto_watermark/img/watermark.png", "logoSha256": "…",
  "widthRatio": 0.07, "marginRatio": 0.02, "jpegQuality": 96, "maxUploadMb": 95 }
```
รหัสผิด/หมดอายุ: HTTP 401 `{ "error": "invalid_code" }`

### 4.3 `POST /desktop/upload` (PublicPage, AnonRateLimit 3000 ต่อ 5 นาที)

- multipart: `photo`, `sha256` (ของไฟล์ต้นฉบับ), `uploader`, `sport`, `position`, `originalName` + header `X-IEPhoto-Code`
- ขั้นตอน: ตรวจรหัส, ตรวจว่าเป็นรูปจริง (`getimagesize`), ตรวจขนาดไม่เกิน 95MB, ดูว่า `sha256` + รหัสนี้เคยรับแล้วหรือไม่ (ตาราง/ไฟล์ `desktop_seen.json` ล็อกด้วย flock) ถ้าเคยรับ คืนชื่อเดิม (HTTP 200, `duplicate: true`)
- ถ้ายังไม่เคยรับ: `allocateName()` เดิม แล้วหาโฟลเดอร์ย่อยด้วยโค้ดเดียวกับ `/upload` (แยกตามกีฬา/ชื่อช่างภาพเหมือนเดิมทุกอย่าง) ต่างกันแค่โฟลเดอร์รากเปลี่ยนเป็น `folder` ของรหัส, **ไม่เรียก `watermark()`**, `recordHistory()` พร้อม `source: "desktop"`
- คืน `{ "name": "IEPHOTO@KMTIL_1234.jpg", "duplicate": false }`
- `/upload` เดิมไม่เปลี่ยนพฤติกรรม

### 4.4 ความปลอดภัย

- การข้ามลายน้ำมีเฉพาะที่ `/desktop/upload` ที่ต้องมีรหัสถูกต้องเท่านั้น
- รหัสเก็บเป็นแฮช, มีวันหมดอายุ, ยกเลิกได้
- เขียนไฟล์ได้เฉพาะโฟลเดอร์ที่กำหนดในรหัส และต้องอยู่ใน scan scope

## 5. Repo และการปล่อยเวอร์ชัน

- GitHub `weerapat-s/ie-photo-uploader` **private**
- โครงสร้าง: `client/`, `server/iephoto_watermark/` (สำเนาแอปจาก NAS เวอร์ชัน 1.54.2 เป็นจุดเริ่ม), `docs/`, `.github/workflows/`
- `.gitignore` กันไฟล์ build, `state.json`, `settings.json` ไม่ให้มีรหัสหรือข้อมูลส่วนตัวใน repo
- GitHub Actions: tag `v*` สร้าง `IE-PHOTO-Uploader-Setup.exe` (windows-latest, PyInstaller + Inno Setup) และ `IE-PHOTO-Uploader.dmg` (macos-latest, universal2) แนบไว้ในหน้า Release
- deploy ฝั่งเซิร์ฟเวอร์ยังทำมือแบบเดิม (scp + `occ upgrade`) พร้อมสำรองก่อน

## 6. ทดสอบ

| ส่วน | ทดสอบอย่างไร |
|---|---|
| `watermark.py` | pytest: รูปตัวอย่าง 6 ใบ (แนวตั้ง/แนวนอน/มี EXIF หมุน/มี ICC/PNG) × 9 ตำแหน่ง เทียบกับผลจาก Imagick บนเซิร์ฟเวอร์: กรอบโลโก้ตรงกันทุกพิกเซล, ค่าสีต่างเฉลี่ย < 2/255 |
| `uploader.py` | pytest + เซิร์ฟเวอร์จำลอง: ลองใหม่, หยุดเมื่อ 401, ข้ามเมื่อ 422, ส่งซ้ำได้ชื่อเดิม |
| `pipeline.py` | pytest: ปิดกลางคันแล้วส่งต่อ ไม่ส่งซ้ำ, คิวค้างไม่เกิน 12 |
| เซิร์ฟเวอร์ | ทดสอบจริงด้วยรหัสทดสอบ ส่งเข้าโฟลเดอร์ทดสอบ: ชื่อเลขรันต่อเนื่อง, ส่งซ้ำไม่สร้างไฟล์ใหม่, รหัสหมดอายุถูกปฏิเสธ, `/upload` เดิมยังใช้ได้ |
| ใช้งานจริง | Windows เครื่องนี้ส่ง 200 รูป, Mac ทดสอบไฟล์ .dmg ที่ได้จาก Actions (ถ้ามีเครื่อง Mac) |

## 7. ข้อจำกัดที่ยอมรับ

- ไฟล์ติดตั้งไม่มีลายเซ็นดิจิทัล: Windows เตือน SmartScreen, Mac ต้องคลิกขวาแล้วเลือก Open
- คุณภาพการคำนวณสีของ Pillow กับ Imagick ต่างกันเล็กน้อยที่ระดับบีบอัด JPEG มองด้วยตาไม่ออก
- ถ้าเครื่องช่างภาพช้ามาก การใส่ลายน้ำอาจช้ากว่าเซิร์ฟเวอร์ แต่ไม่ทำให้เซิร์ฟเวอร์ช้าลงสำหรับผู้ชมรูป
