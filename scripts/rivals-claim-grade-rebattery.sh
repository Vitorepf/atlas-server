#!/usr/bin/env bash
# Claim-grade re-battery playbook for Rivals trust contract.
# Does NOT invent scores. Stops on env/abort. Never uses --fast for claim-grade.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

KIND="${1:-bare}" # bare | uplift
DRY="${DRY_RUN:-0}"
REQUIRE_CLEAN="${REQUIRE_CLEAN:-1}"

CMD=(php artisan atlas:rivals battery --mode=execute --kind="$KIND" --approve-provider-spend)
if [[ "$REQUIRE_CLEAN" == "1" ]]; then
  CMD+=(--require-clean-worktree)
fi
if [[ "$DRY" == "1" ]]; then
  CMD+=(--dry-run)
fi
CMD+=(--json)

echo "== claim-grade: doctor =="
php artisan atlas:rivals doctor --json | tee /tmp/rivals-claim-doctor.json

echo "== claim-grade: ledger verify (semantic) =="
php artisan atlas:rivals ledger --verify --semantic --json | tee /tmp/rivals-claim-ledger.json
python3 - <<'PY'
import json, sys
d=json.load(open("/tmp/rivals-claim-ledger.json"))
if not d.get("verified"):
    print("rivals_claim_grade_ledger_not_verified", file=sys.stderr)
    sys.exit(1)
PY

echo "== claim-grade: benchmarks smoke gate =="
php artisan atlas:rivals benchmarks --strict --json | tee /tmp/rivals-claim-benchmarks.json

if [[ "$DRY" == "1" ]]; then
  echo "== claim-grade: battery execute DRY ($KIND) =="
else
  echo "== claim-grade: battery execute SOLID ($KIND) =="
fi
ATLAS_RIVALS_LAUNCHER_ENGINE="${ATLAS_RIVALS_LAUNCHER_ENGINE:-cli}" \
  "${CMD[@]}" | tee "/tmp/rivals-claim-battery-${KIND}.json"

if [[ "$DRY" == "1" ]]; then
  echo "Dry-run complete — no spend. Re-run without DRY_RUN=1 on clean worktree for claim-grade."
  exit 0
fi

echo "== claim-grade: enterprise + closure =="
php artisan atlas:rivals report-enterprise --json | tee /tmp/rivals-claim-enterprise.json
php artisan atlas:rivals closure --verify --json | tee /tmp/rivals-claim-closure.json

echo "Done. Inspect /tmp/rivals-claim-*.json. authorized=true OR only external blockers named."
