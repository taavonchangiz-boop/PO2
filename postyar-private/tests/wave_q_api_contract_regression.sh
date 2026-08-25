#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
python3 - "$ROOT" <<'PY'
import json, pathlib, re, sys
root=pathlib.Path(sys.argv[1])
manifest=json.loads((root/'docs/rebuild/API-CONTRACT-v1.json').read_text())
api=(root/'app/Api/Routes/api.php').read_text()
assert "MobileApiRouter::get('/health'" in api
assert "MobileApiRouter::get('/ready'" in api
assert manifest['base_path']=='/api/v1'
PY
echo "PASS: Wave Q API contract regression"
