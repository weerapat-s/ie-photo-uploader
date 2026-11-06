"""Compare the client watermark with the server's Imagick output (manual check, not a pytest).

Usage (from client/):  python tests/parity_compare.py [logo.png]
Builds textured test photos, sends photos + the PHP reference through ONE tar stream to the NAS,
runs the reference inside the nextcloud container, streams the PNG results back, then compares.
Nothing is written to Nextcloud; scratch files live in /tmp on the NAS and are removed afterwards.
"""
import io
import random
import subprocess
import sys
import tarfile
from pathlib import Path

from PIL import Image, ImageChops, ImageStat

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "client"))
from ie_uploader import watermark as wm  # noqa: E402

SSH = ["ssh", "nas@100.116.118.109"]
LOGO = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "server/iephoto_watermark/img/watermark.png"
PHP = ROOT / "server/tests/parity/server_watermark.php"
POSITIONS = ("top-left", "center", "bottom-right")
COLOURS = ("black", "white")


def make_photos():
    rnd = random.Random(7)
    photos = {}
    for i, (w, h, orient) in enumerate([(2400, 1600, None), (1600, 2400, None), (2000, 2000, None),
                                        (3000, 2000, None), (1800, 1200, 6), (2400, 1350, None)]):
        base = Image.effect_noise((w, h), 40).convert("RGB")
        tint = Image.new("RGB", (w, h), (rnd.randrange(60, 200), rnd.randrange(60, 200), rnd.randrange(60, 200)))
        img = ImageChops.add(ImageChops.multiply(base, tint), Image.linear_gradient("L").resize((w, h)).convert("RGB"), scale=2)
        buf = io.BytesIO()
        kw = {"quality": 95}
        if orient:
            ex = Image.Exif()
            ex[0x0112] = orient
            kw["exif"] = ex.tobytes()
        img.save(buf, "JPEG", **kw)
        photos[f"photo{i}.jpg"] = buf.getvalue()
    return photos


def main():
    photos = make_photos()
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode="w") as tar:
        for name, data in photos.items():
            ti = tarfile.TarInfo(f"parity/{name}")
            ti.size = len(data)
            tar.addfile(ti, io.BytesIO(data))
        for name, path in (("server_watermark.php", PHP), ("logo.png", LOGO)):
            data = path.read_bytes()
            ti = tarfile.TarInfo(f"parity/{name}")
            ti.size = len(data)
            tar.addfile(ti, io.BytesIO(data))
    remote = (
        "rm -rf /tmp/parity-run && mkdir /tmp/parity-run && cd /tmp/parity-run && tar xf - && "
        "docker cp parity nextcloud:/tmp/parity >/dev/null && "
        "docker exec nextcloud sh -c '"
        + " ; ".join(
            f"php /tmp/parity/server_watermark.php /tmp/parity/{n} /tmp/parity/{n}.{p}.{c}.png /tmp/parity/logo.png {p} 100 {c} >/dev/null"
            for n in photos for p in POSITIONS for c in COLOURS
        )
        + "' && docker exec nextcloud sh -c 'cd /tmp/parity && tar cf - *.png' ; "
        "docker exec nextcloud rm -rf /tmp/parity; rm -rf /tmp/parity-run"
    )
    res = subprocess.run(SSH + [remote], input=out.getvalue(), capture_output=True, check=True)
    refs = {}
    with tarfile.open(fileobj=io.BytesIO(res.stdout), mode="r") as tar:
        for member in tar.getmembers():
            refs[member.name] = tar.extractfile(member).read()
    assert refs, "no reference images came back: " + res.stderr.decode(errors="replace")[:300]
    logo = LOGO.read_bytes()
    worst = 0.0
    tmp = ROOT / "client" / ".parity_tmp"
    tmp.mkdir(exist_ok=True)
    for name, data in photos.items():
        src = tmp / name
        src.write_bytes(data)
        for p in POSITIONS:
            for c in COLOURS:
                ours = Image.open(io.BytesIO(wm.apply(src, logo, p, 100, c)[0])).convert("RGB")
                ref = Image.open(io.BytesIO(refs[f"{name}.{p}.{c}.png"])).convert("RGB")
                assert ours.size == ref.size, (name, ours.size, ref.size)
                diff = ImageChops.difference(ours, ref)
                mean = sum(ImageStat.Stat(diff).mean) / 3
                # Logo box: a misplaced or mis-scaled mark shows up as a much higher diff inside it.
                lw = wm.php_round(ours.width * wm.WIDTH_RATIO)
                small = wm.prepare_logo(logo, lw, c, 100)
                x, y = wm.logo_box(ours.width, ours.height, small.width, small.height, p)
                box = (x, y, x + small.width, y + small.height)
                inside = sum(ImageStat.Stat(diff.crop(box)).mean) / 3
                outside_img = diff.copy()
                outside_img.paste((0, 0, 0), box)
                area = ours.width * ours.height
                outside = sum(ImageStat.Stat(outside_img).mean) / 3 * area / (area - small.width * small.height)
                worst = max(worst, mean, inside - outside + 1.0)
                print(f"{name:12} {p:13} {c:5} whole {mean:.2f}  inside-logo {inside:.2f}  outside {outside:.2f}")
        src.unlink()
    tmp.rmdir()
    print("WORST", round(worst, 2), "PASS" if worst < 2.0 else "FAIL")
    sys.exit(0 if worst < 2.0 else 1)


if __name__ == "__main__":
    main()
