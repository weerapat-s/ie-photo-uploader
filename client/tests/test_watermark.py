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
