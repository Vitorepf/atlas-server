# Atlas Longitudinal Evidence Gate

> Canonical EKB owner for the ACOS long-horizon evidence gate, the ACOS
> delta-series producer, and the scheduler/watchdog topology that keeps the
> clock honest.

## Runtime surface

- Gate command: `php artisan atlas:cognition:acos-long-horizon-gate --json`.
- Receipt command: `php artisan atlas:cognition:acos-long-horizon-gate --write-receipt --json`.
- Gate service: `App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService`.
- Series producer: `php artisan atlas:acos:delta-series --json`.
- Series file: `storage/app/atlas/evidence/acos-delta-series.jsonl`.
- Gate receipt: `storage/app/atlas/evidence/acos-long-horizon-gate.json`.

The gate is read-only over the resolved ACOS scorecard plus the append-only
delta series. It certifies only when the live window clears every floor below;
otherwise it returns `status=insufficient_long_horizon_evidence`,
`certified=false`, and named blockers.

## Floors

| Floor | Value | Source |
|---|---:|---|
| Contiguous calendar window | 30 days | `min_days` |
| Current scorecard overall | >= 9.5 | `min_overall` |
| Current scorecard pipeline | >= 9.5 | `min_pipeline` |
| Latest sample staleness | <= 2 days | `max_latest_stale_days` |
| Maximum internal window gap | <= 1 day | `max_gap_days` |

The scorecard hash must be present and must be a `sha256:` hash. The window is
anchored on the real UTC calendar day unless tests inject `now`.

## Blockers by name

The gate currently emits these blocker names:

- `scorecard_overall_below_floor`
- `pipeline_score_below_floor`
- `scorecard_hash_missing`
- `series_day_count_below_floor`
- `calendar_span_below_floor`
- `series_day_below_floor`
- `delta_series_resolved_evidence_source_missing`
- `delta_series_future_dated_rows`
- `delta_series_window_stale`
- `series_gap_exceeds_floor`
- `backfilled_sample_detected`

Important interpretations:

- `series_day_below_floor` means at least one day inside the certification
  window fell below the floor, even if the latest day is healthy.
- `series_gap_exceeds_floor` means the last 30-day window has a missing-day gap
  wider than the allowed gap. A good total row count outside the window does not
  rescue it.
- `backfilled_sample_detected` means a row claims an old `date` with a
  `recorded_at` that proves it was written later, or lacks `recorded_at` inside
  the counted window. This is a hard blocker.

## Delta-series producer

`atlas:acos:delta-series` writes one resolved-evidence snapshot per calendar
day. Each row carries the snapshot date, `recorded_at`, the frozen baseline
timestamp, scorecard values, metrics, impact receipts, and source metadata.

Rules:

1. The normal producer uses today's UTC/local calendar date and appends the
   current resolved-evidence state.
2. `--date` for a past day is refused in runtime. `--allow-past-date` exists
   only for tests.
3. The same-day catch-up is allowed only when today's row is missing. It never
   writes yesterday or older dates.
4. `appendSnapshot()` replaces the row for the same date. The scheduler closure
   is therefore load-bearing: without the same-day presence check, the hourly
   catch-up would overwrite the 05:10 sample all day.
5. The historical names `marco-zero-fable-2026-06-11.json` and frozen
   `fable-l4-*` / `fable-l5-*` receipts remain evidence names. Runtime names
   use ACOS.

## launchd + watchdog topology

There are two roles:

- Patient: `com.atlas.scheduler`, running Laravel `schedule:run`.
- Watcher: `com.atlas.scheduler-watchdog`, running
  `scripts/scheduler-watchdog.php` outside `schedule:run`.

The watcher exists because a dead patient cannot report its own death. It checks
heartbeat freshness, recent fatal blocks in the launchd error log, selected
receipts such as the ACOS long-horizon gate receipt, volume warnings, and
rollback trigger warnings. It writes `watchdog-alarm.jsonl` when there is an
alarm or warning.

`com.atlas.ai-health` is legacy. The decided topology is to boot it out and
remove its plist when installing the external scheduler watcher, so there is one
watcher and one patient rather than two ambiguous watchers.

Scheduler entries:

- Daily delta sample: `atlas:acos:delta-series --json` at 05:10.
- Hourly same-day delta catch-up after 06:00 when today's row is absent.
- Daily gate receipt: `atlas:cognition:acos-long-horizon-gate --write-receipt --json`.
- Hourly same-day gate receipt catch-up after 07:00 when today's receipt is
  absent.

## Recovery runbook for `watchdog-alarm.jsonl`

1. Read the newest alarm row and classify it:
   - `heartbeat_stale`: patient is silent.
   - launchd fatal block: patient started but crashed.
   - ACOS long-horizon receipt warning/blocker: producer or gate is alive but
     the evidence window is not certifiable.
   - volume or rollback warning: another watchdog check needs operator action.
2. For patient silence, inspect launchd status for `com.atlas.scheduler`, then
   run the scheduler health command:
   `php artisan atlas:scheduler:ensure-launchd --json`.
3. If the external watcher itself is missing, reinstall it:
   `php artisan atlas:scheduler:install-watchdog --json`.
4. For ACOS gate blockers, run:
   `php artisan atlas:cognition:acos-long-horizon-gate --json`.
5. For missing today's sample only, run:
   `php artisan atlas:acos:delta-series --json`.
6. For missing today's gate receipt only, run:
   `php artisan atlas:cognition:acos-long-horizon-gate --write-receipt --json`.
7. If a past day is missing, do not repair the data. Record the blocker and let
   the 30-day clock restart from the next honest contiguous window.

Do not delete the alarm before the root cause is understood. The alarm is an
audit breadcrumb, not noise.

## Clock rules

- The medidor freezes before the clock starts.
- Any semantic medidor change after the clock starts resets the counted series.
- Renames that preserve bytes and gate output do not reset the clock, but they
  need proof.
- No backfill. A lost day is a lost day.
- No synthetic rows. A row counts only when produced by the real producer on its
  own day or by same-day catch-up.
- No retro-dating. `--date` for a past day is refused in runtime.
- Staleness is part of the claim, not an operational warning. A stale latest
  sample blocks certification.

## Knowledge sync note

After landing doc or structural changes, the sync commands exist:

```bash
bin/atlas engineering knowledge sync --prune
bin/atlas engineering knowledge index-code --prune --workspace="$PWD"
```

They can be heavy. Run them only when the current session budget makes that
cheap; otherwise leave the command note for the next sync pass.
