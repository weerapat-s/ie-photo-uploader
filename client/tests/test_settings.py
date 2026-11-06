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


def test_corrupt_values_fall_back_to_defaults(tmp_path):
    keyring.set_keyring(MemKeyring())
    (tmp_path / "settings.json").write_text('{"opacity": "abc", "position": 5, "uploader": 7}', encoding="utf-8")
    s = Settings(tmp_path)
    assert (s.uploader, s.position, s.opacity) == ("", "top-left", 100)


def test_opacity_clamped_and_position_validated(tmp_path):
    keyring.set_keyring(MemKeyring())
    (tmp_path / "settings.json").write_text('{"opacity": 3, "position": "nowhere"}', encoding="utf-8")
    s = Settings(tmp_path)
    assert (s.position, s.opacity) == ("top-left", 10)
    (tmp_path / "settings.json").write_text('{"opacity": 500}', encoding="utf-8")
    assert Settings(tmp_path).opacity == 100
    (tmp_path / "settings.json").write_text('[1, 2]', encoding="utf-8")
    assert Settings(tmp_path).opacity == 100
