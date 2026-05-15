<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use Illuminate\Support\Str;

/**
 * Atlas Forge Rivals · Run Battery (single-button orchestrator).
 *
 * The operator-facing one-command path that chains every phase needed to
 * produce an auditable winner / tie / invalid verdict. v2 canonical order
 * splits evidence/replay into a pre-adjudication pass and a final pass so
 * that the scorecard is never required before the adjudicator has produced
 * it (the old order died with `scorecard:not_present_at_replay`).
 *
 *   doctor → setup → preflight → dry-run → plan-real → run-real →
 *   collect-evidence(pre_adjudication) → replay(pre_adjudication) →
 *   adjudicate → collect-evidence(final) → replay(final) → report
 *
 * Stops at the first non-`ok` phase and returns the partial pipeline so the
 * operator can see exactly where it broke. The evidence directory is
 * preserved (it is the operator's only source of truth).
 *
 * Provider safety:
 *   - For real-provider modes (`fair`, `full_power`) the three operator
 *     confirmations are mandatory. Missing any one ⇒ pipeline blocked
 *     *before* run-real (no provider invoked).
 *   - For `local_fake` mode no provider is invoked even if confirmations
 *     are passed.
 *   - `--rival=codex` triggers an honest preflight blocker
 *     (`rival_driver_not_configured`) if the `codex` binary is unreachable;
 *     the pipeline does not pretend support.
 *
 * Schema: atlas.forge.rivals.run_battery.v1
 */
final class AtlasForgeRivalsRunBatteryService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.run_battery.v1';

    public function __construct(
        private readonly AtlasForgeRivalsDoctorService $doctor,
        private readonly AtlasForgeRivalsSetupService $setup,
        private readonly AtlasForgeRivalsPreflightService $preflight,
        private readonly AtlasForgeRivalsDryRunService $dryRun,
        private readonly AtlasForgeRivalsPlanRealService $planReal,
        private readonly AtlasForgeRivalsRunRealService $runReal,
        private readonly AtlasForgeRivalsCollectEvidenceService $collectEvidence,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsAdjudicatorService $adjudicator,
        private readonly AtlasForgeRivalsReportService $report,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsModeRegistry $modes,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $rawMode = trim((string) ($input['mode'] ?? ''));
        $mode = $this->normalizeMode($rawMode);
        $rawAtlasModel = trim((string) ($input['atlas_model'] ?? 'claude_sonnet'));
        $rawRivalModel = trim((string) ($input['rival'] ?? $rawAtlasModel));
        $atlasModel = $this->normalizeModel($rawAtlasModel);
        $rivalModel = $this->normalizeModel($rawRivalModel);
        $preset = trim((string) ($input['preset'] ?? AtlasForgeRivalsCasesRegistry::PRESET_QUICK));
        $sourceRef = trim((string) ($input['source_ref'] ?? 'HEAD'));
        $confirmations = (array) ($input['confirmations'] ?? []);
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'battery-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        }

        $phases = [];
        $blockers = [];

        // Validate mode admissibility before any I/O.
        if (! in_array($mode, [
            AtlasForgeRivalsModeRegistry::MODE_FAIR,
            AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
        ], true)) {
            return $this->terminal(
                runId: $runId,
                mode: $rawMode,
                phases: $phases,
                blockers: ['mode_not_admissible_for_run_battery:'.$rawMode],
                hint: 'pick --mode=fair|power|local_fake',
            );
        }

        $modeDef = $this->modes->mode($mode);
        $requiresProvider = $modeDef['requires_provider'];

        // For real-provider modes, refuse to start without all three confirms.
        if ($requiresProvider) {
            foreach (['runbook_reviewed', 'provider_cost', 'real_provider_call'] as $required) {
                if (! ($confirmations[$required] ?? false)) {
                    $blockers[] = 'missing_confirmation:'.$required;
                }
            }
            if ($blockers !== []) {
                return $this->terminal(
                    runId: $runId,
                    mode: $mode,
                    phases: $phases,
                    blockers: $blockers,
                    hint: 'pass --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call',
                );
            }
        }

        $sharedConfirms = [
            'runbook_reviewed' => (bool) ($confirmations['runbook_reviewed'] ?? ! $requiresProvider),
            'provider_cost' => (bool) ($confirmations['provider_cost'] ?? ! $requiresProvider),
            'real_provider_call' => (bool) ($confirmations['real_provider_call'] ?? ! $requiresProvider),
        ];

        // Phase 1 — doctor (honest codex driver check happens here for rival=codex)
        $doctor = $this->doctor->check();
        $phases[] = $this->phase('doctor', $doctor);
        if (($doctor['status'] ?? '') !== 'ok') {
            $blockers = array_merge($blockers, (array) ($doctor['blockers'] ?? []));

            return $this->terminal($runId, $mode, $phases, $blockers, 'fix doctor blockers and re-run');
        }
        if ($rivalModel === AtlasForgeRivalsModelMatrix::MODEL_CODEX
            && empty($doctor['checks']['provider_binary_codex']['ok'])
        ) {
            $blockers[] = 'rival_driver_not_configured:codex';

            return $this->terminal(
                $runId,
                $mode,
                $phases,
                $blockers,
                'install codex CLI (which codex) or pick a different --rival',
            );
        }
        if ($requiresProvider && $atlasModel !== AtlasForgeRivalsModelMatrix::MODEL_AUTO) {
            if (empty($doctor['checks']['provider_binary_claude']['ok'])) {
                $blockers[] = 'atlas_driver_not_configured:claude';

                return $this->terminal(
                    $runId,
                    $mode,
                    $phases,
                    $blockers,
                    'install claude CLI (which claude) before running real-provider battery',
                );
            }
            if ($rivalModel !== AtlasForgeRivalsModelMatrix::MODEL_CODEX
                && empty($doctor['checks']['provider_binary_claude']['ok'])
            ) {
                $blockers[] = 'rival_driver_not_configured:claude';

                return $this->terminal(
                    $runId,
                    $mode,
                    $phases,
                    $blockers,
                    'install claude CLI (which claude) before running real-provider battery',
                );
            }
        }

        // Phase 2 — setup worktrees
        $setup = $this->setup->provision([
            'run_id' => $runId,
            'source_ref' => $sourceRef,
        ]);
        $phases[] = $this->phase('setup', $setup);
        if (($setup['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($setup['blockers'] ?? []), 'fix setup blockers');
        }
        $runId = (string) ($setup['run_id'] ?? $runId);
        $runPaths = $this->paths->paths($runId);

        // Phase 3 — preflight
        $preflight = $this->preflight->preflight([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'workspace' => $runPaths['atlas'],
            'baseline_workspace' => $runPaths['rival'],
            'confirmations' => $sharedConfirms,
        ]);
        $phases[] = $this->phase('preflight', $preflight);
        if (($preflight['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($preflight['blockers'] ?? []), 'fix preflight blockers');
        }

        // Phase 4 — dry-run
        $dryRun = $this->dryRun->plan([
            'mode' => $mode,
            'preset' => $preset,
            'workspace' => $runPaths['atlas'],
            'baseline_workspace' => $runPaths['rival'],
        ]);
        $phases[] = $this->phase('dry-run', $dryRun);
        if (($dryRun['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($dryRun['blockers'] ?? []), 'fix dry-run blockers');
        }

        // Phase 5 — plan-real
        $planReal = $this->planReal->plan([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'confirmations' => $sharedConfirms,
        ]);
        $phases[] = $this->phase('plan-real', $planReal);
        if (($planReal['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($planReal['blockers'] ?? []), 'fix plan-real blockers');
        }

        // Phase 6 — run-real (real provider gated; or local_fake)
        $runReal = $this->runReal->run([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'run_id' => $runId,
            'confirmations' => $sharedConfirms,
        ]);
        $phases[] = $this->phase('run-real', $runReal);
        if (($runReal['status'] ?? '') !== 'ok') {
            return $this->terminal(
                $runId,
                $mode,
                $phases,
                (array) ($runReal['blockers'] ?? []),
                'fix run-real blockers',
                runReal: $runReal,
            );
        }

        // Phase 7 — collect-evidence(pre_adjudication)
        // The scorecard does not exist yet; collecting it as a required
        // artifact at this stage was the root cause of the old
        // `scorecard:not_present_at_replay` blocker.
        $collectPre = $this->collectEvidence->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $phases[] = $this->phase('collect-evidence-pre', $collectPre);
        if (($collectPre['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($collectPre['blockers'] ?? []), 'fix evidence blockers');
        }

        // Phase 8 — replay(pre_adjudication)
        $replayPre = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $phases[] = $this->phase('replay-pre', $replayPre);
        if (($replayPre['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($replayPre['blockers'] ?? []), 'replay failed — evidence pack untrustworthy');
        }

        // Phase 9 — adjudicate (deterministic, writes scorecard.json)
        $adjudicate = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $phases[] = $this->phase('adjudicate', $adjudicate);
        if (($adjudicate['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($adjudicate['blockers'] ?? []), 'fix adjudicator blockers');
        }

        // Phase 10 — collect-evidence(final). Now scorecard exists; re-collect
        // so the on-disk pack records its hash.
        $collectFinal = $this->collectEvidence->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $phases[] = $this->phase('collect-evidence-final', $collectFinal);
        if (($collectFinal['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($collectFinal['blockers'] ?? []), 'fix evidence blockers');
        }

        // Phase 11 — replay(final). Validates the scorecard hash too.
        $replayFinal = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $phases[] = $this->phase('replay-final', $replayFinal);
        if (($replayFinal['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($replayFinal['blockers'] ?? []), 'final replay failed — scorecard hash drifted');
        }

        // Phase 12 — report
        $report = $this->report->render(['run_id' => $runId]);
        $phases[] = $this->phase('report', $report);
        if (($report['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($report['blockers'] ?? []), 'fix report blockers');
        }

        $scorecard = $adjudicate['scorecard'] ?? null;
        $winner = is_array($scorecard) ? ($scorecard['winner'] ?? null) : null;

        return [
            'status' => 'ok',
            'run_battery_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'requires_provider' => $requiresProvider,
            'external_provider_call' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'provider_tokens_spent' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'phases' => $phases,
            'phases_passed' => count(array_filter($phases, static fn (array $p): bool => $p['ok'])),
            'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! $p['ok'])),
            'winner' => $winner,
            'scorecard' => $scorecard,
            'report_path' => is_array($report) ? ($report['report_path'] ?? null) : null,
            'evidence_paths' => array_values(array_filter([
                $runPaths['events_jsonl'],
                $runPaths['manifest_json'],
                $runPaths['scorecard_json'],
                $runPaths['report_md'],
            ], static fn (string $p): bool => is_file($p))),
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$runId.' --json',
            'note' => $winner === AtlasForgeRivalsAdjudicatorService::WINNER_NONE
                ? 'Battery completed with hard-gate failure — see scorecard.hard_failures.'
                : ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE
                    ? 'Battery completed with statistical tie — operator review required.'
                    : 'Battery completed with quality-determined winner.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function phase(string $name, array $payload): array
    {
        $status = (string) ($payload['status'] ?? 'unknown');

        return [
            'phase' => $name,
            'status' => $status,
            'ok' => $status === 'ok' || $status === 'completed',
            'blockers' => array_values(array_map(
                static fn ($b): string => (string) $b,
                (array) ($payload['blockers'] ?? [])
            )),
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'power', 'full-power' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            '' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            default => strtolower($mode),
        };
    }

    private function normalizeModel(string $model): string
    {
        return match (strtolower($model)) {
            'sonnet' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            'opus' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            default => strtolower($model),
        };
    }

    /**
     * @param  list<array<string,mixed>>  $phases
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $runReal
     * @return array<string,mixed>
     */
    private function terminal(
        string $runId,
        string $mode,
        array $phases,
        array $blockers,
        string $hint,
        ?array $runReal = null,
    ): array {
        $runPaths = null;
        try {
            $runPaths = $this->paths->paths($runId);
        } catch (\Throwable) {
            $runPaths = null;
        }

        return [
            'status' => 'blocked',
            'run_battery_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'mode' => $mode,
            'phases' => $phases,
            'phases_passed' => count(array_filter($phases, static fn (array $p): bool => $p['ok'])),
            'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! $p['ok'])),
            'blockers' => array_values(array_unique(array_map(static fn ($b): string => (string) $b, $blockers))),
            'winner' => null,
            'scorecard' => null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'evidence_paths' => $runPaths !== null
                ? array_values(array_filter([
                    $runPaths['events_jsonl'],
                    $runPaths['manifest_json'],
                    $runPaths['scorecard_json'],
                    $runPaths['report_md'],
                ], static fn (string $p): bool => is_file($p)))
                : [],
            'failed_run_real' => $runReal,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Battery aborted before declaring a winner. Partial evidence preserved at runs/<run_id>/.',
            'next_command' => $hint,
        ];
    }
}
