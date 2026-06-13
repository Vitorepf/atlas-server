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

// L3-1 · Drain do merge-livre v2 em CADÊNCIA — o consumo do flywheel. Sem isto, as
// propostas certificadas acumulam (82 candidatas / 0 merges era exatamente este buraco).
// O drain é internamente seguro: gated pela flag do operador, freado pelo guard de saldo
// líquido (AtlasLoopNetDirectionGuard), re-prova cada proposta em workspace completo, e a
// porta governada no DB é a única que registra merge. --limit bound por passe.
// Pace: o loop certifica mais rápido do que o drain consumia (drenáveis acumulavam) —
// limit 10 a cada 15min para os merges acompanharem o ritmo da certificação.
// withoutOverlapping(10): expiry de 10min no mutex. O default (24h) seria um buraco de
// 24h-independência — um drain agendado morto no meio (OOM/kill) seguraria o lock por 24h
// e NENHUM drain rodaria mais. Um passe de drain nunca passa de ~2min, então 10min é folga
// segura que destrava sozinho se um passe crashar.
Schedule::command('atlas:loop:automerge --limit=10 --json')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->when(static fn (): bool => (bool) config('atlas.ai.loop.auto_merge_to_main', false));

// L3-11 · Mint de green-run receipts da dimensão pipeline do ACOS, em cadência. Mira os
// subsistemas `partial` (cada receipt verde flipa partial→ready) e sobe o scorecard com
// evidência resolved (nunca self-declared). Bounded por passe; gated para o operador ligar.
Schedule::command('atlas:cognition:mint-pipeline-receipts --limit=8 --json')
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.cognition.mint_pipeline_receipts_enabled', true));

// L3-14 · Série diária do delta N×M da campanha Fable — a foto persistida de
// HOJE-vs-Marco-Zero (waste, merges, custo, scorecard, recall semântico). Idempotente
// por data; alimenta o relatório final que decide pagar API Fable.
Schedule::command('atlas:fable:delta-series --json')
    ->dailyAt('05:10')
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.fable.delta_series_enabled', true));

// L4-4 · Loss-observer diário: autópsia do ledger do Loop. Detecta razões/gates
// dominantes de rejeição e abre backlog intents dedupados para o próprio Loop atacar.
Schedule::command('atlas:loop:loss-observer --json')
    ->dailyAt((string) config('atlas.loop.loss_observer.schedule_time', '05:20'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.loss_observer.enabled', true));

// L4-2 · Backlog auto-alimentado: transforma loss observer, corpus de falhas,
// residuais de campanha, scorecard fraco e achados de sweep em intents dedupados.
Schedule::command('atlas:loop:backlog-feed --json')
    ->dailyAt((string) config('atlas.loop.backlog_auto_feed.schedule_time', '05:25'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.backlog_auto_feed.enabled', true));

// L4-6 · Painel 24h no digest matinal: um comando responde "o que Atlas fez
// sozinho ontem?" com funil, merges+impacto, canários, custo, keepalive e fila
// parked-for-review. Read-only; não manda e-mail nem chama provider.
Schedule::command('atlas:loop:morning-digest --json')
    ->dailyAt((string) config('atlas.loop.morning_digest.schedule_time', '05:35'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.morning_digest.enabled', true));

// L5-1 · Pauta semanal governada: propõe a semana a partir de evidência resolvida.
// O schedule pode criar um draft no backlog, mas nunca aprova nem executa a pauta.
$weeklyAgendaCommand = (bool) config('atlas.loop.weekly_agenda.scheduled_create_proposal', true)
    ? 'atlas:loop:weekly-agenda --create-proposal --json'
    : 'atlas:loop:weekly-agenda --json';
Schedule::command($weeklyAgendaCommand)
    ->weeklyOn((int) config('atlas.loop.weekly_agenda.schedule_day', 1), (string) config('atlas.loop.weekly_agenda.schedule_time', '05:45'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.weekly_agenda.enabled', true));

// 24h-autonomia · Keepalive do supervisor do Loop: campanha running com heartbeat velho
// E sem processo vivo é relançada detached (resume pelo campaign-id; nada se perde).
// Motivado por evidência real: o soak morreu silenciosamente em 12/06 com budget sobrando.
Schedule::command('atlas:loop:keepalive --stale-minutes=2 --json')
    ->everyFiveMinutes()
    // withoutOverlapping(5): o keepalive é a REDE DE SEGURANÇA — se o lock dele travasse
    // 24h (default), ele pararia de respawnar o supervisor morto = independência perdida.
    // Expiry de 5min (= sua própria cadência) destrava sozinho se um passe crashar.
    ->withoutOverlapping(5)
    ->when(static fn (): bool => (bool) config('atlas.loop.keepalive_enabled', true));

// NOTE: the Sunday digest (the ONLY weekly notification) is scheduled ONCE in
// bootstrap/app.php (weeklyOn(0, …), timezone-aware, gated by atlas.ai.weekly_memory_digest.enabled).
// Do NOT add a second Sunday schedule here — one report, one time.
