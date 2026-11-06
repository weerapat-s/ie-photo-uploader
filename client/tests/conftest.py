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
