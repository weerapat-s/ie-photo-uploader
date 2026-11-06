"""Which source files (by sha256) were already uploaded, per desktop code."""
from __future__ import annotations

import hashlib
import json
import os
import re
import threading
import time
from pathlib import Path


def normalize_code(code: str) -> str:
    return re.sub(r"[^A-Za-z0-9]", "", code).upper()


def state_path(data_dir: Path, code: str) -> Path:
    digest = hashlib.sha256(normalize_code(code).encode()).hexdigest()[:12]
    return Path(data_dir) / f"state-{digest}.json"


class State:
    def __init__(self, path: Path):
        self.path = Path(path)
        self._lock = threading.Lock()
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            self._map = {k: v for k, v in data.items() if isinstance(k, str) and isinstance(v, str)}
        except (OSError, ValueError, AttributeError):
            self._map = {}

    def done(self, sha256: str) -> str | None:
        return self._map.get(sha256)

    def done_set(self) -> frozenset[str]:
        with self._lock:
            return frozenset(self._map)

    def mark(self, sha256: str, name: str) -> None:
        with self._lock:
            self._map[sha256] = name
            tmp = self.path.with_suffix(".json.tmp")
            for attempt in range(5):
                try:
                    self.path.parent.mkdir(parents=True, exist_ok=True)
                    tmp.write_text(json.dumps(self._map, ensure_ascii=False), encoding="utf-8")
                    os.replace(tmp, self.path)
                    return
                except OSError:               # AV / sync tool holding the file; PermissionError is an OSError
                    if attempt < 4:
                        time.sleep(0.2)
            # Still failing: keep the entry in memory. The server duplicate guard makes a lost record harmless.
