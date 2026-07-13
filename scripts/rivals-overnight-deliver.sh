#!/usr/bin/env bash
# Overnight supervisor: keep uplift alive, finish stuck pipelines, deliver
# enterprise report (bare + atlas_dev) when the 5 uplift families have uplift.json.
set -euo pipefail
cd /Users/vitorepf/develop/Atlas/atlas-server
export PATH="$HOME/.local/bin:/opt/homebrew/bin:$PATH"
LOG=storage/atlas/rivals/logs/overnight_deliver.log
mkdir -p storage/atlas/rivals/logs
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) overnight_start" | tee -a "$LOG"

UPLIFT_RUNS=(
  "20260712_195649_c5ec8a98:hal_harness"
  "20260712_195649_f134c20d:swe_bench_live"
  "20260712_195649_dacc30c9:terminal_bench"
  "20260712_195649_89170b86:bfcl"
  "20260712_195649_cf15a760:aider_polyglot"
)

finish_pipeline() {
  local rid="$1" sid="$2"
  php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$runId = $argv[1]; $suiteId = $argv[2];
$ref = new ReflectionClass(App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator::class);
$m = $ref->getMethod("finishRunPipeline");
$m->setAccessible(true);
try {
  $out = $m->invoke(app(App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator::class), $runId, $suiteId);
  echo "finished $suiteId uplift=".(($out["uplift"] ?? null) !== null ? "yes" : "no")."\n";
} catch (Throwable $e) {
  fwrite(STDERR, "finish_err $suiteId: ".$e->getMessage()."\n");
  exit(1);
}
' "$rid" "$sid" >>"$LOG" 2>&1 || true
}

count_uplift_json() {
  local n=0
  for pair in "${UPLIFT_RUNS[@]}"; do
    rid="${pair%%:*}"
    [[ -f "storage/atlas/rivals/runs/$rid/uplift.json" ]] && n=$((n+1))
  done
  echo "$n"
}

ensure_uplift_battery() {
  if pgrep -f "php artisan atlas:rivals battery --mode=execute --kind=uplift" >/dev/null; then
    return 0
  fi
  if pgrep -f "rivals-continue-uplift.php" >/dev/null; then
    return 0
  fi
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) uplift_dead_continuing_remaining" | tee -a "$LOG"
  nohup php scripts/rivals-continue-uplift.php \
    >"storage/atlas/rivals/logs/continue_uplift_$(date +%Y%m%d_%H%M%S).log" 2>&1 &
  echo "continue pid=$!" | tee -a "$LOG"
}

while true; do
  ensure_uplift_battery

  # Auto-finish any family that has all units but no uplift.json
  for pair in "${UPLIFT_RUNS[@]}"; do
    rid="${pair%%:*}"
    sid="${pair##*:}"
    run="storage/atlas/rivals/runs/$rid"
    [[ -d "$run" ]] || continue
    [[ -f "$run/uplift.json" ]] && continue
    [[ -f "$run/native_execution_manifest.json" ]] || continue
    entries=$(php -r 'echo count(json_decode(file_get_contents($argv[1]),true)["entries"]??[]);' "$run/native_execution_manifest.json")
    units=$(/bin/ls "$run/external_results/units" 2>/dev/null | wc -l | tr -d ' ')
    if [[ "$units" -ge "$entries" && "$entries" -gt 0 ]]; then
      echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) auto_finish $sid units=$units/$entries" | tee -a "$LOG"
      finish_pipeline "$rid" "$sid"
    fi
  done

  uj=$(count_uplift_json)
  bat=$(pgrep -f "php artisan atlas:rivals battery --mode=execute --kind=uplift" >/dev/null && echo Y || echo N)
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) uplift_json=$uj/5 bat=$bat" | tee -a "$LOG"

  if [[ "$uj" -ge 5 ]]; then
    echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) ALL_UPLIFT_READY delivering" | tee -a "$LOG"
    # Wait for battery process to exit cleanly if still wrapping up
    for _ in 1 2 3 4 5 6; do
      pgrep -f "php artisan atlas:rivals battery --mode=execute --kind=uplift" >/dev/null || break
      sleep 30
    done
    php artisan atlas:rivals report-enterprise --json >storage/atlas/rivals/logs/report_enterprise_morning.json 2>&1 || true
    php artisan atlas:rivals closure --verify --json >storage/atlas/rivals/logs/closure_morning.json 2>&1 || true
    php artisan atlas:rivals ledger --verify --semantic --json >storage/atlas/rivals/logs/ledger_morning.json 2>&1 || true
    python3 - <<'PY'
import json
from pathlib import Path
d=json.load(open("storage/atlas/rivals/enterprise/report.json"))
ex=d.get("executive_summary") or {}
up=d.get("atlas_uplift") or {}
lines=[
  f"built_at={d.get('built_at')}",
  f"claim_allowed={d.get('claim_allowed')}",
  f"suites_ok={ex.get('suites_ok')} missing_data={ex.get('suites_missing_data')} not_run={ex.get('suites_not_run')}",
  f"uplift_ready={ex.get('uplift_families_ready')}/{ex.get('uplift_families_total')}",
]
for f in (up.get("families") or []):
  lines.append(f"uplift {f.get('family_id') or f.get('suite_id')}: {f.get('status')}")
# model matrix / dissection — bare + atlas rows
mm=d.get("model_matrix") or {}
rows=mm.get("rows") if isinstance(mm, dict) else []
if isinstance(rows, list):
  lines.append(f"model_matrix_rows={len(rows)}")
  for r in rows[:20]:
    lines.append(f"model {r.get('model_id')}@{r.get('runtime')} present={r.get('present')} intel={r.get('intelligence') or r.get('composite')}")
for row in (d.get("model_dissections") or []):
  if isinstance(row, dict):
    lines.append(f"dissect {row.get('model_id')}@{row.get('runtime')} present={row.get('present')}")
lines += [
  "HTML=/Users/vitorepf/develop/Atlas/atlas-server/storage/atlas/rivals/enterprise/report.html",
  "JSON=/Users/vitorepf/develop/Atlas/atlas-server/storage/atlas/rivals/enterprise/report.json",
  "MD=/Users/vitorepf/develop/Atlas/atlas-server/storage/atlas/rivals/enterprise/report.md",
  "CSV=/Users/vitorepf/develop/Atlas/atlas-server/storage/atlas/rivals/enterprise/report.csv",
]
Path("storage/atlas/rivals/logs/MORNING_REPORT_READY.txt").write_text("\n".join(lines)+"\n")
print("\n".join(lines))
PY
    touch storage/atlas/rivals/logs/batteries_done.flag
    open storage/atlas/rivals/enterprise/report.html 2>/dev/null || true
    echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) REPORT_READY" | tee -a "$LOG"
    break
  fi
  sleep 120
done
