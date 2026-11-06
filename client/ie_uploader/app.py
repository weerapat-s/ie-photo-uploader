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
        # CTkLabel is a frame wrapping an inner tk.Label/canvas that covers it, so register
        # both the wrapper and its inner widgets or drops on the text would be ignored.
        for w in (self.drop, getattr(self.drop, "_label", None), getattr(self.drop, "_canvas", None)):
            if w is not None:
                w.drop_target_register(DND_FILES)
                w.dnd_bind("<<Drop>>", self._on_drop)

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
