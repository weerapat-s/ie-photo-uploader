#!/usr/bin/env bash
# Usage: CODE=XXXXX-... SPORT=<registered sport> server/tests/smoke.sh
set -euo pipefail
BASE=${BASE:-https://nextcloud.ienas.site}/apps/iephoto_watermark
: "${CODE:?set CODE}"; : "${SPORT:?set SPORT}"
TMP=$(mktemp -d); TMPW=$(cygpath -m "$TMP" 2>/dev/null || echo "$TMP"); trap 'rm -rf "$TMP"' EXIT
python - "$TMPW/p.jpg" <<'PY'
import sys
from PIL import Image
Image.new("RGB", (800, 600), (90, 140, 200)).save(sys.argv[1], "JPEG", quality=95)
PY
SHA=$(sha256sum "$TMP/p.jpg" | cut -d' ' -f1)
echo "1 bad code -> 401";  test "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-IEPhoto-Code: AAAAA-AAAAA-AAAAA-AAAAA' "$BASE/desktop/config")" = 401
echo "2 config -> 200";    curl -sf -H "X-IEPhoto-Code: $CODE" "$BASE/desktop/config" | python -c "import sys,json; d=json.load(sys.stdin); assert d['sports'], d; print(' ', d['label'], len(d['sports']), 'sports')"
# Thai text goes through UTF-8 files: Windows curl mangles non-ASCII command-line arguments.
printf %s "ทดสอบโปรแกรม" > "$TMP/uploader.txt"; printf %s "$SPORT" > "$TMP/sport.txt"
up() { curl -s -H "X-IEPhoto-Code: $CODE" -F "photo=@$TMPW/p.jpg" -F "sha256=$SHA" -F "uploader=<$TMPW/uploader.txt" -F "sport=<$TMPW/sport.txt" -F position=top-left -F originalName=p.jpg "$BASE/desktop/upload"; }
echo "3 upload";           R1=$(up); echo "  $R1"; echo "$R1" | grep -q '"duplicate":false'
echo "4 re-upload same";   R2=$(up); echo "  $R2"; echo "$R2" | grep -q '"duplicate":true'
N1=$(echo "$R1" | python -c "import sys,json; print(json.load(sys.stdin)['name'])"); N2=$(echo "$R2" | python -c "import sys,json; print(json.load(sys.stdin)['name'])")
test "$N1" = "$N2" && echo "  same name $N1"
echo "5 old /upload still public"; test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sports")" = 200
echo "SMOKE OK"
