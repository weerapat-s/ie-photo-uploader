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
