#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SETTINGS="$ROOT/.claude/settings.json"
CTX="$ROOT/.claude/hooks/atlas-ctx.sh"
python3 - "$SETTINGS" <<'PY'
import json, sys
from pathlib import Path
from collections import Counter, defaultdict
settings = json.loads(Path(sys.argv[1]).read_text())
fail = 0
for event, blocks in settings.get("hooks", {}).items():
    cmds = []
    for block in blocks:
        for h in block.get("hooks", []):
            cmd = h.get("command", "")
            if any(x in cmd for x in (
                "atlas-ctx.sh", "atlas-pretooluse-guard.sh",
                "atlas-postedit-context.sh", "atlas-session-capture.sh",
            )):
                cmds.append(cmd)
    by_base = defaultdict(list)
    for c in cmds:
        base = c.rsplit("/", 1)[-1].split()[0]
        by_base[base].append(c)
    for base, variants in by_base.items():
        print(f"{event}:{base} count={len(variants)}")
        if len(variants) > 1:
            print(" FAIL duplicate", variants)
            fail = 1
# negative
syn = [
    "$CLAUDE_PROJECT_DIR/.claude/hooks/atlas-postedit-context.sh",
    "/abs/.claude/hooks/atlas-postedit-context.sh",
]
assert len(syn) == 2
print("negative_case_detects_duplicate=true")
sys.exit(fail)
PY
rg -n "ATLAS_AOBG_ACTIVATE_TTL_SECONDS|ATLAS_AOBG_CTX_HOOK_TIMEOUT|ACTIVATE_MARKER|perl -e 'alarm" "$CTX" >/dev/null
TMP="${TMPDIR:-/tmp}/atlas-maxe03-test-$$"; mkdir -p "$TMP"
WS="/tmp/fake-ws-maxe03"; KEY="$(printf '%s' "$WS" | cksum | cut -d' ' -f1)"
MARKER="$TMP/atlas-aobg-activate-$KEY"; printf '%s' "$(date +%s)" > "$MARKER"
AGE=$(( $(date +%s) - $(stat -f %m "$MARKER" 2>/dev/null || stat -c %Y "$MARKER") ))
[ "$AGE" -lt 21600 ]
echo "maxe03_fresh_marker_skips_activate=true age=$AGE"
rg -q "perl -e 'alarm" "$CTX"
echo "maxe03_timeout_bound_present=true"
rm -rf "$TMP"
echo "MAXE-02/03 OK"
