"""IE-PHOTO Uploader window."""
from __future__ import annotations

import hashlib
import queue
import sys
import threading
import webbrowser
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox

import customtkinter as ctk
from tkinterdnd2 import DND_FILES, TkinterDnD
from PIL import Image, ImageOps

from . import __version__, updater, watermark
from .pipeline import Pipeline, RunSettings, Summary, scan
from .settings import Settings
from .state import State, state_path
from .uploader import AuthError, Client, TransientError, parse_credential, pick_base_url

PREVIEW_SOURCE_PX = 1600      # longest side of the cached preview source
PREVIEW_DEBOUNCE_MS = 250
LOGO_MISMATCH = "โลโก้จากเซิร์ฟเวอร์ไม่ตรงกัน ลองเชื่อมต่อใหม่"

# --- look & feel: same palette as the IE-PHOTO web gallery ---
BG = "#fcf8f5"
CARD = "#ffffff"
LINE = "#eadfd9"
SOFT = "#f4ece8"
INK = "#2d2937"
MUTED = "#6f6880"
PINK = "#c93a62"
PINK_HOVER = "#b32f55"
PINK_PALE = "#fff0f4"
PINK_OFF = "#e6c3cd"
PEACH = "#ffcfa8"
MINT = "#c9ecdc"
MINT_INK = "#1f6b4a"
ERR_BG = "#fde7ec"
CONN_STYLES = {
    "idle": (SOFT, MUTED), "busy": (SOFT, INK), "ok": (MINT, MINT_INK), "error": (ERR_BG, PINK),
}


def thai_font_family(root) -> str:
    """First installed family that has good Thai glyphs; falls back to Tk's default."""
    import sys
    from tkinter import font as tkfont
    wanted = {
        "win32": ("Leelawadee UI", "Tahoma", "Segoe UI"),
        "darwin": ("Thonburi", "Ayuthaya", "Helvetica Neue"),
    }.get(sys.platform, ("Noto Sans Thai", "Loma", "DejaVu Sans"))
    have = set(tkfont.families(root))
    return next((f for f in wanted if f in have), "TkDefaultFont")


def short_path(path, limit: int = 44) -> str:
    text = str(path)
    return text if len(text) <= limit else "…" + text[-(limit - 1):]


POS_LABELS = {
    "top-left": "ซ้ายบน", "top-center": "กลางบน", "top-right": "ขวาบน",
    "middle-left": "ซ้ายกลาง", "center": "กลาง", "middle-right": "ขวากลาง",
    "bottom-left": "ซ้ายล่าง", "bottom-center": "กลางล่าง", "bottom-right": "ขวาล่าง",
}


class PositionPicker(ctk.CTkFrame):
    """3x3 grid that looks like a photo: click where the watermark should go.

    Keeps the get()/set(label) API of the option menu it replaces, so callers stay the same.
    """

    def __init__(self, master, labels: dict, command, font):
        super().__init__(master, fg_color=SOFT, border_width=1, border_color=LINE, corner_radius=12)
        self._command = command
        self._cells = {}
        self._value = next(iter(labels.values()))
        for i, label in enumerate(labels.values()):
            cell = ctk.CTkButton(self, text="", width=48, height=28, corner_radius=8, font=font,
                                 fg_color=CARD, hover_color=PEACH, border_width=1, border_color=LINE,
                                 command=lambda lab=label: self._pick(lab))
            cell.grid(row=i // 3, column=i % 3, padx=3, pady=3)
            self._cells[label] = cell
        self._paint()

    def _paint(self):
        for label, cell in self._cells.items():
            on = label == self._value
            cell.configure(fg_color=PINK if on else CARD, hover_color=PINK_HOVER if on else PEACH,
                           text="●" if on else "", text_color="#ffffff")

    def _pick(self, label):
        self._value = label
        self._paint()
        if self._command:
            self._command(label)

    def get(self) -> str:
        return self._value

    def set(self, label: str) -> None:
        if label in self._cells:
            self._value = label
            self._paint()


class App(ctk.CTk, TkinterDnD.DnDWrapper):
    def __init__(self):
        super().__init__()
        self.TkdndVersion = TkinterDnD._require(self)
        ctk.set_appearance_mode("light")
        self.title(f"IE-PHOTO Uploader {__version__}")
        self.geometry("920x740")
        self.minsize(840, 700)
        self.configure(fg_color=BG)
        self.settings = Settings()
        self.events: queue.Queue = queue.Queue()
        self.stop = threading.Event()
        self.client = None
        self.config_data = None
        self.logo = None
        self.folder: Path | None = None
        self.files: list[Path] = []
        self.running = False
        self._preview_src: Image.Image | None = None
        self._preview_job = None
        self._preview_img = None
        self._build()
        self.protocol("WM_DELETE_WINDOW", self._on_close)
        self.after(100, self._drain)
        threading.Thread(target=lambda: self.events.put(("update", updater.check(__version__))), daemon=True).start()

    # ---------- layout ----------
    def _card(self, parent, step: int, title: str) -> ctk.CTkFrame:
        card = ctk.CTkFrame(parent, fg_color=CARD, border_width=1, border_color=LINE, corner_radius=16)
        head = ctk.CTkFrame(card, fg_color="transparent")
        head.pack(fill="x", padx=16, pady=(12, 2))
        ctk.CTkLabel(head, text=str(step), width=26, height=26, corner_radius=13, fg_color=PINK,
                     text_color="#ffffff", font=self.f_small_bold).pack(side="left")
        ctk.CTkLabel(head, text=title, text_color=INK, font=self.f_h2).pack(side="left", padx=10)
        return card

    def _field(self, parent, text: str):
        ctk.CTkLabel(parent, text=text, text_color=MUTED, font=self.f_small, anchor="w").pack(
            fill="x", padx=16, pady=(6, 2))

    def _entry(self, parent, var, secret: bool = False) -> ctk.CTkEntry:
        entry = ctk.CTkEntry(parent, textvariable=var, height=34, corner_radius=10,
                             fg_color=SOFT, border_color=LINE, text_color=INK,
                             font=self.f_body, show="•" if secret else "")
        entry.pack(fill="x", padx=16)
        self._enable_clipboard(entry)
        return entry

    @staticmethod
    def _enable_clipboard(entry: ctk.CTkEntry) -> None:
        """Ctrl+C/V/X/A that also work with a Thai keyboard layout, plus a right-click menu.

        Tk binds copy/paste to the Latin keysyms, so with the Thai layout active Ctrl+V does
        nothing. The hardware keycode does not change with the layout, so match on that."""
        inner = entry._entry
        actions = {86: "<<Paste>>", 67: "<<Copy>>", 88: "<<Cut>>"}      # V, C, X (Windows/Linux keycodes)

        def ctrl_key(ev):
            if ev.keycode in actions:
                inner.event_generate(actions[ev.keycode])
                return "break"
            if ev.keycode == 65:                                        # A
                inner.select_range(0, "end")
                inner.icursor("end")
                return "break"
            return None

        if sys.platform != "darwin":        # macOS Tk already maps Cmd+C/V/X independent of the layout
            inner.bind("<Control-KeyPress>", ctrl_key)

        menu = tk.Menu(inner, tearoff=0)
        menu.add_command(label="วาง", command=lambda: inner.event_generate("<<Paste>>"))
        menu.add_command(label="คัดลอก", command=lambda: inner.event_generate("<<Copy>>"))
        menu.add_command(label="ตัด", command=lambda: inner.event_generate("<<Cut>>"))
        menu.add_separator()
        menu.add_command(label="เลือกทั้งหมด", command=lambda: (inner.select_range(0, "end"), inner.icursor("end")))

        def popup(ev):
            inner.focus_set()
            menu.tk_popup(ev.x_root, ev.y_root)
            return "break"

        inner.bind("<Button-3>", popup)
        if sys.platform == "darwin":
            inner.bind("<Button-2>", popup)

    def _build(self):
        family = thai_font_family(self)

        def mk(size, weight="normal"):
            return ctk.CTkFont(family=family, size=size, weight=weight)

        self.f_title, self.f_h2 = mk(22, "bold"), mk(15, "bold")
        self.f_body, self.f_small, self.f_small_bold = mk(14), mk(12), mk(12, "bold")
        self.f_big = mk(16, "bold")

        self.grid_columnconfigure(1, weight=1)
        self.grid_rowconfigure(1, weight=1)

        # header
        header = ctk.CTkFrame(self, fg_color="transparent")
        header.grid(row=0, column=0, columnspan=2, sticky="ew", padx=24, pady=(14, 6))
        ctk.CTkLabel(header, text="◆", text_color=PINK, font=mk(26, "bold")).pack(side="left")
        titles = ctk.CTkFrame(header, fg_color="transparent")
        titles.pack(side="left", padx=10)
        ctk.CTkLabel(titles, text="IE-PHOTO Uploader", text_color=INK, font=self.f_title, anchor="w").pack(anchor="w")
        ctk.CTkLabel(titles, text="ใส่ลายน้ำบนเครื่องของคุณ แล้วส่งขึ้นคลังภาพ", text_color=MUTED,
                     font=self.f_small, anchor="w").pack(anchor="w")
        ctk.CTkLabel(header, text=f"v{__version__}", text_color=MUTED, font=self.f_small).pack(side="right")
        self.update_btn = ctk.CTkButton(header, text="", height=30, corner_radius=15, fg_color=PEACH,
                                        hover_color="#ffbf8a", text_color=INK, font=self.f_small_bold,
                                        command=self._open_update)
        self._update = None
        self._updating = False

        # left column: 1 connect, 2 watermark
        left = ctk.CTkFrame(self, fg_color="transparent", width=340)
        left.grid(row=1, column=0, sticky="nsw", padx=(24, 10), pady=(0, 20))
        left.grid_propagate(False)

        c1 = self._card(left, 1, "เชื่อมต่องาน")
        c1.pack(fill="x", pady=(0, 10))
        self._field(c1, "ลิงก์งาน หรือ รหัสงาน (ขอจากแอดมิน วางได้เลย)")
        self.code_var = ctk.StringVar(value=self.settings.code)
        self._entry(c1, self.code_var)
        self._field(c1, "ชื่อช่างภาพ (ชื่อโฟลเดอร์ที่รูปจะไปอยู่ เช่น สมชาย ใจดี)")
        self.name_var = ctk.StringVar(value=self.settings.uploader)
        self._entry(c1, self.name_var)
        self.connect_btn = ctk.CTkButton(c1, text="เชื่อมต่อ", height=36, corner_radius=10, font=self.f_body,
                                         fg_color=CARD, hover_color=PINK_PALE, text_color=PINK,
                                         border_width=2, border_color=PINK, command=self._connect)
        self.connect_btn.pack(fill="x", padx=16, pady=(10, 6))
        self.event_label = ctk.CTkLabel(c1, text="", font=self.f_small, corner_radius=10,
                                        wraplength=270, justify="left", anchor="w")
        self.event_label.pack(fill="x", padx=16, pady=(0, 12))
        self._set_connection("ยังไม่ได้เชื่อมต่อ", "idle")

        c2 = self._card(left, 2, "ตั้งค่าลายน้ำ")
        c2.pack(fill="x")
        self._field(c2, "กีฬา")
        self.sport_menu = ctk.CTkOptionMenu(c2, values=["—"], command=lambda _: self._refresh_preview(),
                                            height=34, corner_radius=10, font=self.f_body, dropdown_font=self.f_body,
                                            fg_color=SOFT, button_color=PEACH, button_hover_color=PINK_OFF,
                                            text_color=INK, dropdown_fg_color=CARD, dropdown_text_color=INK,
                                            dropdown_hover_color=PINK_PALE, anchor="w")
        self.sport_menu.pack(fill="x", padx=16)
        self.colour_label = ctk.CTkLabel(c2, text="", font=self.f_small, text_color=MUTED, anchor="w")
        self.colour_label.pack(fill="x", padx=16, pady=(2, 0))
        self._field(c2, "ตำแหน่งลายน้ำ")
        self.pos_menu = PositionPicker(c2, POS_LABELS, lambda _: self._refresh_preview(), self.f_body)
        self.pos_menu.set(POS_LABELS.get(self.settings.position, "ซ้ายบน"))
        self.pos_menu.pack(padx=16, anchor="w")
        row = ctk.CTkFrame(c2, fg_color="transparent")
        row.pack(fill="x", padx=16, pady=(8, 0))
        ctk.CTkLabel(row, text="ความเข้ม", text_color=MUTED, font=self.f_small).pack(side="left")
        self.opacity_label = ctk.CTkLabel(row, text=f"{self.settings.opacity}%", text_color=INK,
                                          font=self.f_small_bold)
        self.opacity_label.pack(side="right")
        self.opacity = ctk.CTkSlider(c2, from_=10, to=100, number_of_steps=18, height=16,
                                     progress_color=PINK, button_color=PINK, button_hover_color=PINK_HOVER,
                                     fg_color=SOFT, command=self._on_opacity)
        self.opacity.set(self.settings.opacity)
        self.opacity.pack(fill="x", padx=16, pady=(0, 12))

        # right column: 3 photos + send
        right = self._card(self, 3, "รูปของคุณ")
        right.grid(row=1, column=1, sticky="nsew", padx=(10, 24), pady=(0, 20))

        self.drop_zone = ctk.CTkFrame(right, fg_color=PINK_PALE, border_width=2, border_color=PEACH,
                                      corner_radius=14, height=84, cursor="hand2")
        self.drop_zone.pack(fill="x", padx=16, pady=(4, 10))
        self.drop_zone.pack_propagate(False)
        self.drop = ctk.CTkLabel(self.drop_zone, text="ลากโฟลเดอร์รูปมาวางที่นี่", text_color=INK, font=self.f_big)
        self.drop.pack(pady=(16, 0))
        self.drop_sub = ctk.CTkLabel(self.drop_zone, text="หรือคลิกเพื่อเลือกโฟลเดอร์", text_color=MUTED,
                                     font=self.f_small)
        self.drop_sub.pack()
        for w in (self.drop_zone, self.drop, self.drop_sub):
            w.bind("<Button-1>", lambda e: self._choose_folder())
        # CTk widgets wrap inner tk widgets that cover them, so register the wrappers and the
        # inner widgets or drops landing on the text would be ignored.
        for w in (self.drop_zone, self.drop, self.drop_sub):
            for target in (w, getattr(w, "_canvas", None), getattr(w, "_label", None)):
                if target is not None:
                    target.drop_target_register(DND_FILES)
                    target.dnd_bind("<<Drop>>", self._on_drop)

        stage = self.stage = ctk.CTkFrame(right, fg_color=SOFT, corner_radius=14, border_width=1, border_color=LINE)
        self._stage_size = (0, 0)
        self.preview = ctk.CTkLabel(stage, text="ตัวอย่างลายน้ำจะแสดงที่นี่\nหลังเชื่อมต่อและเลือกโฟลเดอร์",
                                    text_color=MUTED, font=self.f_body, justify="center")
        self.preview.pack(fill="both", expand=True, padx=8, pady=8)

        bar = ctk.CTkFrame(right, fg_color="transparent")
        self.start_btn = ctk.CTkButton(bar, text="เริ่มใส่ลายน้ำ + อัปโหลด", height=46, corner_radius=12,
                                       font=self.f_big, fg_color=PINK, hover_color=PINK_HOVER,
                                       text_color="#ffffff", text_color_disabled="#ffffff",
                                       command=self._start, state="disabled")
        self.start_btn.pack(side="left", fill="x", expand=True)
        self.stop_btn = ctk.CTkButton(bar, text="หยุด", width=90, height=46, corner_radius=12, font=self.f_body,
                                      fg_color=CARD, hover_color=SOFT, text_color=MUTED, border_width=1,
                                      border_color=LINE, text_color_disabled=PINK_OFF, command=self.stop.set,
                                      state="disabled")
        self.stop_btn.pack(side="left", padx=(10, 0))
        self._set_start_look(False)

        prog = ctk.CTkFrame(right, fg_color="transparent")
        self.progress = ctk.CTkProgressBar(prog, height=10, corner_radius=5, progress_color=PINK, fg_color=SOFT)
        self.progress.set(0)
        self.progress.pack(fill="x")
        info = ctk.CTkFrame(prog, fg_color="transparent")
        info.pack(fill="x", pady=(4, 6))
        self.status = ctk.CTkLabel(info, text="พร้อมเมื่อเชื่อมต่อและเลือกโฟลเดอร์แล้ว", text_color=MUTED,
                                   font=self.f_small, anchor="w")
        self.status.pack(side="left")
        self.percent_label = ctk.CTkLabel(info, text="0%", text_color=INK, font=self.f_small_bold)
        self.percent_label.pack(side="right")

        failed_label = ctk.CTkLabel(right, text="ส่งไม่สำเร็จ", text_color=MUTED, font=self.f_small, anchor="w")
        self.failed_box = ctk.CTkTextbox(right, height=64, corner_radius=10, fg_color=CARD, border_width=1,
                                         border_color=LINE, text_color=PINK, font=self.f_small)
        self.failed_box.pack(side="bottom", fill="x", padx=16, pady=(2, 16))
        failed_label.pack(side="bottom", fill="x", padx=16)
        prog.pack(side="bottom", fill="x", padx=16)
        bar.pack(side="bottom", fill="x", padx=16, pady=(0, 10))
        stage.pack(fill="both", expand=True, padx=16, pady=(0, 10))
        stage.bind("<Configure>", self._on_stage_resize)

    def _set_connection(self, text: str, kind: str) -> None:
        bg, fg = CONN_STYLES[kind]
        self.event_label.configure(text=f"  {text}  ", fg_color=bg, text_color=fg)

    def _set_start_look(self, enabled: bool) -> None:
        self.start_btn.configure(state="normal" if enabled else "disabled",
                                 fg_color=PINK if enabled else PINK_OFF)

    def _on_opacity(self, value) -> None:
        self.opacity_label.configure(text=f"{int(float(value))}%")
        self._refresh_preview()

    # ---------- actions ----------
    def _position(self) -> str:
        label = self.pos_menu.get()
        return next(k for k, v in POS_LABELS.items() if v == label)

    def _colour(self) -> str:
        for s in (self.config_data or {}).get("sports", []):
            if s["name"] == self.sport_menu.get():
                return s["colour"]
        return "black"

    def _on_close(self):
        if self.running:
            if not messagebox.askyesno("IE-PHOTO", "ยังส่งรูปไม่เสร็จ ปิดโปรแกรมแล้วหยุดการส่งหรือไม่? (เปิดใหม่แล้วส่งต่อได้)"):
                return
            self.stop.set()
        self.destroy()

    def _connect(self):
        if self.running:
            return
        code = parse_credential(self.code_var.get())
        if not code or not self.name_var.get().strip():
            messagebox.showwarning("IE-PHOTO", "ใส่ลิงก์งานหรือรหัสงาน และชื่อช่างภาพก่อน")
            return
        self._set_connection("กำลังเชื่อมต่อ…", "busy")

        def work():
            try:
                client = Client(pick_base_url(), code)
                cfg = client.config()
                logo = client.logo(cfg["logoUrl"])
                expected = str(cfg.get("logoSha256") or "").strip().lower()
                if expected and hashlib.sha256(logo).hexdigest() != expected:
                    self.events.put(("logo_mismatch",))
                    return
                self.events.put(("connected", client, cfg, logo))
            except AuthError:
                self.events.put(("error", "ลิงก์หรือรหัสงานไม่ถูกต้องหรือหมดอายุ ขอใหม่จากแอดมิน"))
            except (TransientError, Exception) as exc:
                self.events.put(("error", f"เชื่อมต่อเซิร์ฟเวอร์ไม่ได้: {exc}"))
        threading.Thread(target=work, daemon=True).start()

    def _choose_folder(self):
        if self.running:
            return
        chosen = filedialog.askdirectory()
        if chosen:
            self._set_folder(Path(chosen))

    def _on_drop(self, event):
        if self.running:
            return
        paths = self.tk.splitlist(event.data)
        if paths:
            p = Path(paths[0])
            self._set_folder(p if p.is_dir() else p.parent)

    def _set_folder(self, folder: Path):
        self.folder = folder
        self.files = scan(folder)
        self._preview_src = self._load_preview_source(self.files[0]) if self.files else None
        count = f"{len(self.files):,} รูป" if self.files else "ไม่พบรูป (รองรับ JPG, PNG, WebP)"
        self.drop.configure(text=f"📁 {folder.name or short_path(folder)}  ·  {count}")
        self.drop_sub.configure(text=f"{short_path(folder.parent if folder.name else folder)}   ·   คลิกเพื่อเปลี่ยน")
        self._refresh_preview()
        self._update_start()

    @staticmethod
    def _load_preview_source(path: Path) -> Image.Image | None:
        """Downscaled, EXIF-oriented RGB copy of the first image, computed once per folder."""
        try:
            with Image.open(path) as im:
                if im.format == "JPEG":
                    im.draft("RGB", (PREVIEW_SOURCE_PX, PREVIEW_SOURCE_PX))
                img = ImageOps.exif_transpose(im)
                img.load()
            img = img.convert("RGB") if img.mode != "RGB" else img
            img.thumbnail((PREVIEW_SOURCE_PX, PREVIEW_SOURCE_PX), Image.LANCZOS)
            return img
        except Exception:
            return None

    def _refresh_preview(self):
        self.colour_label.configure(text=f"สีลายน้ำตามสนาม:  {'○ ขาว' if self._colour() == 'white' else '● ดำ'}")
        if self._preview_job is not None:
            self.after_cancel(self._preview_job)
        self._preview_job = self.after(PREVIEW_DEBOUNCE_MS, self._render_preview)

    def _on_stage_resize(self, event) -> None:
        """Re-fit the preview when the window is resized (ignore tiny changes: no feedback loops)."""
        w, h = event.width, event.height
        if abs(w - self._stage_size[0]) > 6 or abs(h - self._stage_size[1]) > 6:
            self._stage_size = (w, h)
            if self._preview_src is not None:
                self._refresh_preview()

    def _render_preview(self):
        self._preview_job = None
        if not (self._preview_src is not None and self.logo):
            return
        try:
            img = watermark.apply_image(self._preview_src, self.logo, self._position(),
                                        int(self.opacity.get()), self._colour(), keep_alpha=False)
            box_w = max(200, self.stage.winfo_width() - 24)
            box_h = max(120, self.stage.winfo_height() - 24)
            img.thumbnail((box_w, box_h))
        except Exception:
            return
        self._preview_img = ctk.CTkImage(img, size=img.size)
        self.preview.configure(image=self._preview_img, text="")

    def _update_start(self):
        ready = self.client is not None and self.folder is not None and not self.running
        self._set_start_look(ready)

    def _start(self):
        self.settings.save(parse_credential(self.code_var.get()), self.name_var.get(), self._position(), int(self.opacity.get()))
        files = list(self.files) or scan(self.folder)
        state = State(state_path(self.settings.dir, self.settings.code))
        run = RunSettings(uploader=self.name_var.get().strip(), sport=self.sport_menu.get(),
                          position=self._position(), opacity=int(self.opacity.get()),
                          colour=self._colour(), logo=self.logo)
        self.stop.clear()
        self.running = True
        self.failed_box.delete("1.0", "end")
        self._set_start_look(False)
        self.start_btn.configure(text="กำลังส่งรูป…")
        self.connect_btn.configure(state="disabled")
        self.stop_btn.configure(state="normal")
        self.progress.set(0)
        self.percent_label.configure(text="0%")
        pipe = Pipeline(self.client, state, run, on_event=self.events.put)

        def work():
            try:
                summary = pipe.run(files, self.stop)
            except Exception as exc:          # the UI must always get its "done"
                summary = Summary(total=len(files), error=f"{type(exc).__name__}: {exc}")
            self.events.put(("done", summary))
        threading.Thread(target=work, daemon=True).start()

    # ---------- updates ----------
    def _show_update(self, upd) -> None:
        self._update = upd
        self.update_btn.configure(text=f"⬆ มีเวอร์ชันใหม่ {upd.version} · กดเพื่ออัปเดต")
        self.update_btn.pack(side="right", padx=(0, 12))
        if messagebox.askyesno(
                "IE-PHOTO",
                f"มีเวอร์ชันใหม่ {upd.version} (ตอนนี้ใช้ {__version__})\n"
                "ควรอัปเดตเพื่อให้ได้ฟีเจอร์และการแก้ไขล่าสุด\n\n"
                + ("อัปเดตเลยไหม? โปรแกรมจะปิด ติดตั้งให้เอง แล้วเปิดใหม่" if updater.can_self_update()
                   else "เปิดหน้าดาวน์โหลดเลยไหม?")):
            self._open_update()

    def _open_update(self) -> None:
        upd = self._update
        if upd is None or self._updating:
            return
        if not updater.can_self_update():
            webbrowser.open(upd.download)
            self.status.configure(text="ดาวน์โหลดตัวติดตั้งใหม่แล้วเปิดติดตั้งทับของเดิมได้เลย (ข้อมูลและรหัสที่ตั้งไว้ไม่หาย)")
            return
        if self.running and not messagebox.askyesno("IE-PHOTO", "ยังส่งรูปอยู่ อัปเดตตอนนี้จะหยุดการส่ง (เปิดใหม่แล้วส่งต่อได้) ทำต่อไหม?"):
            return
        self._updating = True
        self.stop.set()
        self.update_btn.configure(state="disabled", text="กำลังดาวน์โหลดอัปเดต… 0%")

        def work():
            try:
                def progress(done, total):
                    if total:
                        self.events.put(("update_progress", int(done * 100 / total)))
                path = updater.download(upd, progress)
                self.events.put(("update_ready", path))
            except updater.UpdateError as exc:
                self.events.put(("update_failed", str(exc)))
            except Exception as exc:                      # never leave the button stuck
                self.events.put(("update_failed", f"อัปเดตไม่สำเร็จ: {exc}"))
        threading.Thread(target=work, daemon=True).start()

    def _finish_update(self, path) -> None:
        try:
            updater.launch_install(path)
        except Exception as exc:
            self._update_failed(f"เริ่มติดตั้งไม่สำเร็จ: {exc}")
            return
        self.update_btn.configure(text="กำลังติดตั้ง… โปรแกรมจะเปิดใหม่เอง")
        self.update()
        self.destroy()

    def _update_failed(self, message: str) -> None:
        self._updating = False
        self.stop = threading.Event()
        self.update_btn.configure(state="normal", text=f"⬆ มีเวอร์ชันใหม่ {self._update.version} · ลองอัปเดตอีกครั้ง")
        self.status.configure(text=message)
        if self._update is not None:
            messagebox.showwarning("IE-PHOTO", message + "\nกดปุ่มอัปเดตเพื่อลองใหม่ หรือโหลดเองจากหน้า GitHub Release")

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
                via = "ช่องทางด่วน" if self.client.base.startswith("http://100.") else "อินเทอร์เน็ต"
                self._set_connection(f"เชื่อมต่อแล้ว · งาน: {self.config_data['label']} · ผ่าน{via}", "ok")
                self._refresh_preview()
                self._update_start()
            elif kind == "update":
                if ev[1] is not None:
                    self._show_update(ev[1])
            elif kind == "update_progress":
                self.update_btn.configure(text=f"กำลังดาวน์โหลดอัปเดต… {ev[1]}%")
            elif kind == "update_ready":
                self._finish_update(ev[1])
            elif kind == "update_failed":
                self._update_failed(ev[1])
            elif kind == "error":
                self._set_connection(ev[1], "error")
            elif kind == "logo_mismatch":
                self.client = self.logo = None
                self._set_connection(LOGO_MISMATCH, "error")
                self._update_start()
            elif kind == "done":
                s = ev[1]
                self.running = False
                self.stop_btn.configure(state="disabled")
                self.connect_btn.configure(state="normal")
                self.start_btn.configure(text="เริ่มใส่ลายน้ำ + อัปโหลด")
                self._update_start()
                if s.error:
                    self.status.configure(text=f"เกิดข้อผิดพลาด: {s.error}")
                    return
                rate = s.uploaded / s.seconds if s.seconds else 0
                if not s.failed and not s.stopped:
                    self.progress.set(1)
                    self.percent_label.configure(text="100%")
                self.status.configure(text=f"เสร็จ: ส่ง {s.uploaded} · ข้าม (เคยส่งแล้ว) {s.skipped} · ไม่สำเร็จ {len(s.failed)} · {rate:.1f} รูป/วินาที")
                if s.auth_error:
                    messagebox.showerror("IE-PHOTO", "ลิงก์หรือรหัสงานไม่ถูกต้องหรือหมดอายุ ขอใหม่จากแอดมิน")
            return
        if ev.kind == "progress":
            frac = ev.done / max(1, ev.total)
            self.progress.set(frac)
            self.percent_label.configure(text=f"{int(frac * 100)}%")
            self.status.configure(text=f"ส่งแล้ว {ev.done:,} / {ev.total:,} รูป")
        elif ev.kind == "failed":
            self.failed_box.insert("end", f"{Path(ev.path).name}: {ev.message}\n")
        elif ev.kind == "paused":
            self.status.configure(text=ev.message)


def main():
    App().mainloop()
