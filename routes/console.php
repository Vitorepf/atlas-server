<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(sprintf(
    'atlas:software-company-stewardship native-obra-runner --area=%s --enable-native-obra-runner --record-scheduler-run --record-continuous-cycle %s --min-interval-seconds=%d --json',
    escapeshellarg((string) config('atlas.software_company_stewardship.native_obra_runner.area_id', 'agentic_engineering_os')),
    (bool) config('atlas.software_company_stewardship.native_obra_runner.record_runs', true) ? '--record-native-obra-run' : '',
    (int) config('atlas.software_company_stewardship.native_obra_runner.min_interval_seconds', 900),
))
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.software_company_stewardship.native_obra_runner.enabled', false));

// Hermes Capability Registry drift capture: probe the local Hermes daily and persist the manifest +
// quarantined CapabilityCandidates (read-only; never enables a capability). Gated off by default so it
// runs only when the operator opts in — this is the "Atlas auto-detects Hermes changes" heartbeat.
Schedule::command('atlas:hermes:capabilities probe --write --json')
    ->daily()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.providers.hermes_cli.capability_probe_schedule_enabled', false));

// "Atlas learns YOU, automatically" — Phase 2. The DAILY pass re-mines recent turns into
// the governed candidate pipeline (safe explicit items auto-apply; the rest queue for the
// Sunday review). The per-turn job already captures live; this is the catch-up + compounding
// heartbeat ("improves more each day"). Gated by the comprehension mode (off ⇒ skip).
Schedule::command('atlas:ai:operator-comprehend --since=36h')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->when(static fn (): bool => (string) config('atlas_operator_intelligence.comprehension_extraction_mode', 'observe') !== 'off'
        && (bool) config('atlas_operator_intelligence.daily_comprehension_enabled', true));

// Proactive detection — "Atlas notices you repeat X". Daily, after the comprehension
// mine has refreshed the signals. Propose-only (mission drafts + never-merge skill
// proposals); everything surfaces in the Sunday report for review.
Schedule::command('atlas:ai:operator-patterns')
    ->dailyAt('06:10')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas_operator_intelligence.pattern_detection_enabled', true));

// AURG vivo (Salto 1 / F4) — COMPOUNDING heartbeat: daily full fused-store sync of
// the 5 read-models (+ --prune sweeps vanished source rows) so the brain keeps
// accruing even for sources without a live write hook, and the full-sync path
// appends the daily AURG-4D snapshot tick (real snapshot_hash → growth deltas in
// atlas:aurg:status). Local-only; ingest-on-write covers memory between runs.
Schedule::command('atlas:aurg:ingest --prune --json')
    ->dailyAt((string) config('atlas.aurg.schedule_time', '05:50'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.aurg.enabled', true)
        && (bool) config('atlas.aurg.schedule_enabled', true));

// NOTE: the Sunday digest (the ONLY weekly notification) is scheduled ONCE in
// bootstrap/app.php (weeklyOn(0, …), timezone-aware, gated by atlas.ai.weekly_memory_digest.enabled).
// Do NOT add a second Sunday schedule here — one report, one time.
