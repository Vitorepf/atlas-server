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

// L5-3 — auto-cura da suíte real: grava SEMANALMENTE o snapshot do número REAL de
// testes vermelhos (derivado do último relatório real) por ISO-week. É a fonte-de-verdade
// do trend que destrava o claim L5-3 (gate exige ≥2 semanas REAIS caindo). Observe-only:
// só mede e persiste, nunca corrige/quarentena. Idempotente por semana. Gated default-OFF.
Schedule::command('atlas:failure:weekly-red-snapshot --json')
    ->weeklyOn(
        match (mb_strtolower((string) config('atlas.ai.suite_red_snapshot.schedule_day', 'monday'))) {
            'sunday' => 0, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6,
            default => 1,
        },
        (string) config('atlas.ai.suite_red_snapshot.schedule_time', '06:30'),
    )
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.suite_red_snapshot.schedule_enabled', false));

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
// appendOutputTo: o output JSON de CADA drain agendado vai p/ um log (antes era /dev/null)
// — observabilidade de operação 24h: dá p/ auditar POR QUE um drain mergeou 0 (vazio?
// throttled? apply-conflict?) sem precisar re-rodar manual.
Schedule::command('atlas:loop:automerge --limit=10 --json')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/loop-automerge.log'))
    ->when(static fn (): bool => (bool) config('atlas.ai.loop.auto_merge_to_main', false));

// Conversion flywheel: feed a characterization_test task per REAL coverage gap (a refactor blocked
// by mutation_survived on a still-uncovered decision) to the live supervisor. The command itself is
// the hard gate — it no-ops unless the lane flag is ON and pre-validates each gap is real on current
// code (it never enqueues spurious/already-covered gaps). withoutOverlapping + a generous interval so
// a live materialize+verify pre-check pass never stacks; appendOutputTo for 24h observability.
Schedule::command('atlas:loop:coverage-gaps --hours=24 --feed --json')
    ->everyThirtyMinutes()
    ->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/loop-coverage-gaps-feed.log'))
    ->when(static fn (): bool => (bool) config('atlas.loop.characterization_test_lane_enabled', false));

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

// L6-9 · ACOS long-horizon readiness. Read-only claim gate: requires
// resolved-evidence score floors plus >=30 real days in the delta series.
Schedule::command('atlas:cognition:acos-long-horizon-gate --write-receipt --json')
    ->dailyAt((string) config('atlas.cognition.acos_long_horizon_gate.schedule_time', '06:55'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.cognition.acos_long_horizon_gate.enabled', true)
        && (bool) config('atlas.cognition.acos_long_horizon_gate.schedule_enabled', true));

// L6-10 · Swarm topology auto-composer. Shadow-only: selects a topology by
// task type and measures convergence from real plan_trace envelopes.
Schedule::command('atlas:swarm:topology-auto-compose --fixture=two-types --write-receipt --json')
    ->dailyAt((string) config('atlas.patamar4.swarm_topology_auto_composer.schedule_time', '07:20'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.patamar4.swarm_topology_auto_composer.enabled', true)
        && (bool) config('atlas.patamar4.swarm_topology_auto_composer.schedule_enabled', true));

// L6-11 · Predictive code intelligence correlation. Claim gate only: requires
// fresh code intelligence plus resolved predictive-failure outcomes.
Schedule::command('atlas:cognition:predictive-code-intelligence-gate --write-receipt --json')
    ->dailyAt((string) config('atlas.cognition.predictive_code_intelligence_gate.schedule_time', '07:25'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.cognition.predictive_code_intelligence_gate.enabled', true)
        && (bool) config('atlas.cognition.predictive_code_intelligence_gate.schedule_enabled', true));

// L6-11 follow-up · Daily predictive-failure calibration recompute. The loop predictive
// outcome bridge (atlas.loop.predictive_outcome_bridge) feeds real grind predictions+outcomes
// into predictive_failure_insertions, but the calibration metrics only recompute on demand
// (atlas:predict metrics / the gate above in live mode). This refreshes the
// predictive_failure_calibration_metrics read-model daily for the SAME domain the bridge
// writes, so dashboards/digests stay fresh without waiting for a gate run. It is read-only
// telemetry rollup — no code, proposal, or merge. DEFAULT OFF and gated on the bridge being
// ON too: with no bridge data there is nothing to summarize.
$predictiveCalibrationRecomputeCommand = sprintf(
    'atlas:predict metrics --domain=%s --window=%d --json',
    (string) config('atlas.loop.predictive_outcome_bridge.domain', 'programming'),
    max(1, (int) config('atlas.loop.predictive_outcome_bridge.metrics_recompute.window_days', 60)),
);
Schedule::command($predictiveCalibrationRecomputeCommand)
    ->dailyAt((string) config('atlas.loop.predictive_outcome_bridge.metrics_recompute.schedule_time', '07:45'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.predictive_outcome_bridge.enabled', false)
        && (bool) config('atlas.loop.predictive_outcome_bridge.metrics_recompute.enabled', false)
        && (bool) config('atlas.loop.predictive_outcome_bridge.metrics_recompute.schedule_enabled', true));

// L6-12 · Long-horizon continuity pack. Explicitly writes a provider-safe
// continuation pack + replay manifest, then certifies through the read-only gate.
Schedule::command('atlas:long-horizon:continuity-pack --strict-replay --write-receipt --json')
    ->dailyAt((string) config('atlas.long_horizon.continuity_pack_emitter.schedule_time', '07:30'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.long_horizon.continuity_pack_emitter.enabled', true)
        && (bool) config('atlas.long_horizon.continuity_pack_emitter.schedule_enabled', true));

// L6-12 keystone · Cross-week recall-lift gate. Reads the REAL persisted
// continuation packs and proves the temporal DoD (old-memory recall lifting a
// task today) on real elapsed calendar time. Fail-closed, never fabricates
// elapsed time / recall events / lift; auto-greens once >=3-week-old recall
// data exists.
Schedule::command('atlas:long-horizon:cross-week-recall-lift-gate --write-receipt --json')
    ->dailyAt((string) config('atlas.long_horizon.cross_week_recall_lift_gate.schedule_time', '07:32'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.long_horizon.cross_week_recall_lift_gate.enabled', true)
        && (bool) config('atlas.long_horizon.cross_week_recall_lift_gate.schedule_enabled', true));

// L6-13 · Fixed-N capability-per-dollar series. Writes a daily measured-cost
// snapshot, then gates the monthly positive-trend claim without estimating N.
Schedule::command('atlas:compounding:fixed-n-capability-dollar-gate --write-snapshot --write-receipt --json')
    ->dailyAt((string) config('atlas.compounding.fixed_n_capability_dollar_gate.schedule_time', '07:35'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.compounding.fixed_n_capability_dollar_gate.enabled', true)
        && (bool) config('atlas.compounding.fixed_n_capability_dollar_gate.schedule_enabled', true));

// L6-14 · Change-class trust release gate. Reads the per-class trust ladder and
// proves Admission only relaxes review for allowlisted classes, with regression
// revocation and sensitive-class blocking checked on every run.
Schedule::command('atlas:governance:change-class-trust-release-gate --write-receipt --json')
    ->dailyAt((string) config('atlas.ai.trust_ladder.release_gate.schedule_time', '07:40'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.trust_ladder.enabled', false)
        && (bool) config('atlas.ai.trust_ladder.release_gate.enabled', true)
        && (bool) config('atlas.ai.trust_ladder.release_gate.schedule_enabled', true));

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

// L5-14 · Weekly Atlas report, written before the L5-1 agenda cadence. It is
// a readable source artifact and agenda feed, never an approval/merge action.
Schedule::command('atlas:fable:weekly-report --write-report --write-markdown --json')
    ->weeklyOn((int) config('atlas.loop.weekly_report.schedule_day', 1), (string) config('atlas.loop.weekly_report.schedule_time', '05:40'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.weekly_report.enabled', true)
        && (bool) config('atlas.loop.weekly_report.schedule_enabled', true));

// L5-4 · Self-construction tool gap scan. Read-only: sees ACOS gaps on a cadence
// so recurring loss-observer/tooling gaps can be proposed through the governed
// self-construction commands. It never approves, stages, promotes, merges or runs providers.
Schedule::command('atlas:self-construction:detect-gaps --json')
    ->dailyAt((string) config('atlas.ai.self_construction.tool_gap_schedule_time', '05:50'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.self_construction.tool_gap_schedule_enabled', true));

// L5-4 keystone · recurrent-capability-gap bridge. Reads the Loop loss-observer's
// dominant loss patterns and routes the ones that signal a MISSING TOOL/CAPABILITY
// (fixture builder, contract linter, worktree helper, …) into the governed
// self-construction corridor as a PARKED proposal (human approval required). It
// never approves, stages, promotes, merges or runs a provider; the cadence is
// dry-run unless the operator turns schedule_write_enabled on.
$toolGapBridgeCommand = (bool) config('atlas.ai.self_construction.tool_gap_bridge.schedule_write_enabled', false)
    ? 'atlas:self-construction:tool-gap-bridge --write --json'
    : 'atlas:self-construction:tool-gap-bridge --json';
Schedule::command($toolGapBridgeCommand)
    ->dailyAt((string) config('atlas.ai.self_construction.tool_gap_bridge.schedule_time', '05:52'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.self_construction.tool_gap_bridge.enabled', true)
        && (bool) config('atlas.ai.self_construction.tool_gap_bridge.schedule_enabled', true));

// L5-11 · Learn→recall→USE lift: read-only A/B measurement over compounding
// RAG feedback. It never writes memory or changes retrieval; strict completion
// remains blocked until live feedback marks recalled memory used in passing tasks.
Schedule::command('atlas:ai:learning-recall-lift --json')
    ->dailyAt((string) config('atlas.ai.loop.learning_recall_use_lift.schedule_time', '06:00'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.ai.loop.learning_recall_use_lift.enabled', true)
        && (bool) config('atlas.ai.loop.learning_recall_use_lift.schedule_enabled', true));

// L6-1 · Meta-harness A/B lift read-model. It never edits harness code; it
// only proves or blocks the claim from real Loop outcomes.
Schedule::command('atlas:loop:meta-harness-ab-lift --json')
    ->dailyAt((string) config('atlas.loop.meta_harness_ab_lift.schedule_time', '06:15'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.meta_harness_ab_lift.enabled', true)
        && (bool) config('atlas.loop.meta_harness_ab_lift.schedule_enabled', true));

// L6-2 · Judge self-calibration from historical RED-canary fix-forward cases.
// Writes only evidence artifacts/packets; it never changes merge gates or runs providers.
Schedule::command('atlas:loop:judge-calibration --write --json')
    ->dailyAt((string) config('atlas.loop.judge_self_calibration.schedule_time', '06:20'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.judge_self_calibration.enabled', true)
        && (bool) config('atlas.loop.judge_self_calibration.schedule_enabled', true));

// L6-3 · Explorer strategy bandit. Measures certification-per-token by target
// type and writes a routing receipt; the grinder applies only proven lift.
Schedule::command('atlas:loop:strategy-bandit --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.explorer_strategy_bandit.schedule_time', '06:25'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.explorer_strategy_bandit.enabled', true)
        && (bool) config('atlas.loop.explorer_strategy_bandit.schedule_enabled', true));

// L6-4 · Code-graph auto-architecture proposals. Parked draft only; no
// provider call, no Obra creation, no refactor apply.
$autoArchitectureCommand = (bool) config('atlas.loop.auto_architecture_proposals.scheduled_create_proposal', true)
    ? 'atlas:loop:auto-architecture --write-receipt --create-proposal --json'
    : 'atlas:loop:auto-architecture --write-receipt --json';
Schedule::command($autoArchitectureCommand)
    ->dailyAt((string) config('atlas.loop.auto_architecture_proposals.schedule_time', '06:30'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.auto_architecture_proposals.enabled', true)
        && (bool) config('atlas.loop.auto_architecture_proposals.schedule_enabled', true));

// L6-5 · Mutation adequacy proof. The live semantic certifier uses this gate
// inline; the scheduled fixture proves the gate itself still rejects weak tests
// and generates NaN/INF/overflow adversarial inputs without touching source.
Schedule::command('atlas:loop:mutation-gate --fixture=strong --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.mutation_adequacy_gate.schedule_time', '06:35'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.mutation_adequacy_gate.enabled', true)
        && (bool) config('atlas.loop.mutation_adequacy_gate.schedule_enabled', true));

// L6-6 · Cross-file consumer proof. The live semantic certifier uses this gate
// inline; the scheduled fixture proves code-graph-discovered consumer contracts
// are replayed without touching source or merge policy.
Schedule::command('atlas:loop:cross-file-consumer-gate --fixture=safe --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.cross_file_consumer_gate.schedule_time', '06:40'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.cross_file_consumer_gate.enabled', true)
        && (bool) config('atlas.loop.cross_file_consumer_gate.schedule_enabled', true));

// L6-7 · Observed-behavior regression oracle. Receipt-only: proves the sentinel
// still blocks drift in behavior contracts that are not covered by test specs.
Schedule::command('atlas:self-improvement:regression-sentinel --fixture=safe --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.self_improvement_regression_oracle.schedule_time', '06:45'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.self_improvement_regression_oracle.enabled', true)
        && (bool) config('atlas.loop.self_improvement_regression_oracle.schedule_enabled', true));

// L6-8 · Formal-light invariant gate. Receipt-only: verifies reproducible
// proof envelopes for sensitive kernel floors without claiming full formal
// verification, touching source, providers, merge policy or never-merge.
Schedule::command('atlas:loop:formal-invariant-gate --fixture=safe --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.formal_invariant_gate.schedule_time', '06:50'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.formal_invariant_gate.enabled', true)
        && (bool) config('atlas.loop.formal_invariant_gate.schedule_enabled', true));

// L5-13 · Perpetual adversarial sweep, fortnightly by ISO-week parity. LOW is
// enqueued as governed backlog intent; HIGH is parked for operator review.
$perpetualSweepWeekParity = (int) config('atlas.loop.perpetual_sweep.schedule_week_parity', 0);
Schedule::command('atlas:loop:perpetual-sweep --write --json')
    ->weeklyOn((int) config('atlas.loop.perpetual_sweep.schedule_day', 6), (string) config('atlas.loop.perpetual_sweep.schedule_time', '06:05'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.perpetual_sweep.enabled', true)
        && (bool) config('atlas.loop.perpetual_sweep.schedule_enabled', true)
        && ((int) now()->format('W') % 2) === $perpetualSweepWeekParity);

// L5-5 · TAXA² dials: daily receipt of the raise-only/clamped overlay that the
// campaign supervisor also consumes on boot. Receipt-only; no providers, no merge.
Schedule::command('atlas:loop:taxa2-dials --write-receipt --json')
    ->dailyAt((string) config('atlas.loop.taxa2_dials.schedule_time', '05:55'))
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('atlas.loop.taxa2_dials.enabled', false)
        && (bool) config('atlas.loop.taxa2_dials.schedule_enabled', true));

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
