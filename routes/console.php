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

// AP-818 F2.1 — drena a fila folder-intel (assembly de inteligência on-link).
// Não há worker queue:work residente; o launchd scheduler (schedule:run a cada
// 60s) é o trilho. runInBackground: um assembly de minutos não pode segurar o
// schedule:run; withoutOverlapping: nunca dois drenos simultâneos (o lock W-10
// ainda protege workspace a workspace). stop-when-empty: o processo morre com
// a fila seca — zero custo residente.
Schedule::command('queue:work database-long --queue=folder-intel --stop-when-empty --max-time=1500 --timeout=1500 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->when(static fn (): bool => (bool) config('atlas.code_folder_intelligence.auto_assemble', false));

// G3 — drena a fila de mission deliveries HTTP (mesmo trilho do folder-intel:
// sem worker residente; o schedule:run dispara um worker stop-when-empty).
// Gated pela mesma flag do endpoint; withoutOverlapping evita dois drenos.
Schedule::command('queue:work database-long --queue=missions --stop-when-empty --max-time=1500 --timeout=1500 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->when(static fn (): bool => (bool) config('atlas.mission.http_delivery_enabled', false));

// AP-819 AUTOPILOT — o loop Self-Harness completo, diário: ponte gera propostas
// dos clusters (propose), depois o autopilot fecha experimentos vencidos
// (monitor: confirma ou auto-reverte pelo outcome cru) e aplica o próximo edit
// sob os 3 gates matemáticos. 1 edit/dia no máximo, tudo receitado.
Schedule::command('atlas:harness propose --json')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.harness_autopilot.enabled', false));
Schedule::command('atlas:harness autopilot --json')
    ->dailyAt('07:10')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.harness_autopilot.enabled', false));

// AP-819 F1 — auto-feed do cérebro de falhas: colhe falhas reais de runtime
// (ai_job_attempts failed/timeout + ledger OPERATION_FAILED) para o corpus
// failure_signatures. Observe-only (escreve só corpus + alertas ≥3); idempotente
// por envelope_id, então rodar de hora em hora é seguro. Gated default-OFF.
Schedule::command('atlas:failure:auto-feed --json')
    ->hourly()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.failure_auto_feed.enabled', false));

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

// Venture Foundry weekly strategist cadence — Monday morning, the business-week
// opener (NOT Sunday: the Sunday digest stays the only Sunday schedule). Reviews
// every active venture (gates + trajectory + memo); deterministic by default —
// the provider-backed opinion only joins when cycle_analyze is flipped on.
Schedule::command('atlas:venture review-cycle --json')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas_venture_foundry.weekly_review_enabled', true));

// NOTE: the Sunday digest (the ONLY weekly notification) is scheduled ONCE in
// bootstrap/app.php (weeklyOn(0, …), timezone-aware, gated by atlas.ai.weekly_memory_digest.enabled).
// Do NOT add a second Sunday schedule here — one report, one time.
