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
