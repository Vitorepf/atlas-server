#!/bin/bash
# Atlas Loop — auto report recorder. Regenerates the FACTS log of every soak merge from git history
# every 2min, so nothing is ever missed between supervision checks. The qualitative assessment lives in
# docs/loop-soak-evaluation-report.md (hand-graded on review); this is the complete, always-current facts.
# Stop: touch storage/atlas-loop/REPORT_STOP
set -u
cd /Users/vitorepf/develop/Atlas/atlas-server || exit 1
LOG="docs/loop-soak-merge-log.md"
BASE_FILE="/tmp/loop-soak-base-commit.txt"
STOP="storage/atlas-loop/REPORT_STOP"
BASE="$(cat "$BASE_FILE" 2>/dev/null)"
[ -z "$BASE" ] && BASE="$(git rev-parse 5887a6124^ 2>/dev/null)"

while true; do
  [ -f "$STOP" ] && { rm -f "$STOP"; break; }
  {
    echo "# Log de merges do soak — auto-registrado (todos os \`atlas loop auto-merge\` desde o início)"
    echo ""
    echo "| # | commit | alvo | Δlinhas | arquivos |"
    echo "|---|---|---|---|---|"
    n=0
    for c in $(git log --reverse --pretty='%H' "${BASE}..HEAD" 2>/dev/null); do
      msg="$(git log -1 --pretty='%s' "$c")"
      case "$msg" in
        *"atlas loop auto-merge"*)
          n=$((n+1))
          short="$(git rev-parse --short "$c")"
          target="$(printf '%s' "$msg" | sed 's/.*auto-merge: //; s/ \[.*//')"
          stat="$(git show --stat --format= "$c" 2>/dev/null | tail -1 | sed 's/^ *//')"
          files="$(printf '%s' "$stat" | grep -oE '^[0-9]+ file[s]?' )"
          delta="$(printf '%s' "$stat" | grep -oE '[0-9]+ insertion[^,]*|[0-9]+ deletion[^,]*' | tr '\n' ' ')"
          echo "| $n | \`$short\` | $target | ${delta:-—} | ${files:-—} |"
          ;;
      esac
    done
    echo ""
    echo "_total: $n merges · regenerado automaticamente a cada 2min · $(date '+%Y-%m-%d %H:%M')_"
  } > "$LOG"
  sleep 120
done
