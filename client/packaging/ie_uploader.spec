# PyInstaller spec. Build: pyinstaller packaging/ie_uploader.spec  (cwd = client/)
import os
import sys
from PyInstaller.utils.hooks import collect_data_files

datas = collect_data_files("customtkinter") + collect_data_files("tkinterdnd2")
a = Analysis(["entry.py"], pathex=[os.path.abspath(os.path.join(SPECPATH, ".."))], datas=datas,
             hiddenimports=["keyring.backends.Windows", "keyring.backends.macOS"])
pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, [], exclude_binaries=True, name="IE-PHOTO Uploader",
          console=False, icon=None)
coll = COLLECT(exe, a.binaries, a.datas, name="IE-PHOTO Uploader")
if sys.platform == "darwin":
    app = BUNDLE(coll, name="IE-PHOTO Uploader.app", bundle_identifier="site.ienas.uploader")
