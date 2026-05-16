<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
        private readonly AtlasForgeRivalsCorpusPreValidationService $corpusPreValidation,
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
        $promptMode = $this->normalizePromptMode((string) ($input['prompt_mode'] ?? ''));
        $case = trim((string) ($input['case'] ?? ''));
        $cases = array_values(array_filter(
            array_map(static fn ($entry): string => trim((string) $entry), (array) ($input['cases'] ?? [])),
            static fn (string $entry): bool => $entry !== '',
        ));
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $sourceRef = trim((string) ($input['source_ref'] ?? 'HEAD'));
        $confirmations = (array) ($input['confirmations'] ?? []);
        $dryRunOnly = (bool) ($input['dry_run'] ?? false);
        $resumeRequested = (bool) ($input['resume'] ?? false);
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
        if (! in_array($promptMode, ['spec-perfect', 'human-normal', 'messy-real', 'enterprise-change'], true)) {
            return $this->terminal(
                runId: $runId,
                mode: $mode,
                phases: $phases,
                blockers: ['prompt_mode_not_admissible_for_run_battery:'.$promptMode],
                hint: 'pick --prompt-mode=spec-perfect|human-normal|messy-real|enterprise-change',
            );
        }

        $modeDef = $this->modes->mode($mode);
        $requiresProvider = $modeDef['requires_provider'];

        // --dry-run flag short-circuits the pipeline at plan-real: preflight
        // and dry-run still execute (those never invoke a provider), but the
        // confirmation gates are not required because no real provider will
        // be reached. This keeps `run-battery --dry-run` a safe planning
        // operation for any preset, including release.
        // For real-provider modes (without --dry-run), refuse to start
        // without all three confirms.
        if ($requiresProvider && ! $dryRunOnly) {
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

        // In --dry-run mode the pipeline never invokes a provider, so the
        // preflight gates that exist to guard provider spend would block for
        // no reason. Mark them satisfied; the run-real phase is skipped
        // entirely below, so this is an honest pass.
        $sharedConfirms = [
            'runbook_reviewed' => $dryRunOnly || (bool) ($confirmations['runbook_reviewed'] ?? ! $requiresProvider),
            'provider_cost' => $dryRunOnly || (bool) ($confirmations['provider_cost'] ?? ! $requiresProvider),
            'real_provider_call' => $dryRunOnly || (bool) ($confirmations['real_provider_call'] ?? ! $requiresProvider),
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

        if ($resumeRequested) {
            $activeRunnerBlockers = $this->resumeActiveRunnerBlockers($runPaths);
            if ($activeRunnerBlockers !== []) {
                return $this->terminal(
                    $runId,
                    $mode,
                    $phases,
                    $activeRunnerBlockers,
                    'wait for the active battery runner to finish or become stalled before resuming',
                );
            }

            $resumeCleanup = $this->prepareResumeWorktrees($runPaths);
            if (($resumeCleanup['blockers'] ?? []) !== []) {
                return $this->terminal(
                    $runId,
                    $mode,
                    $phases,
                    (array) $resumeCleanup['blockers'],
                    'fix resume worktree cleanup blockers before continuing the battery',
                );
            }
        }

        // Phase 3 — preflight
        $preflight = $this->preflight->preflight([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'case' => $case !== '' ? $case : null,
            'cases' => $cases,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
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
            'case' => $case !== '' ? $case : null,
            'cases' => $cases,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
            'workspace' => $runPaths['atlas'],
            'baseline_workspace' => $runPaths['rival'],
        ]);
        $phases[] = $this->phase('dry-run', $dryRun);
        if (($dryRun['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($dryRun['blockers'] ?? []), 'fix dry-run blockers');
        }

        // Phase 4.5 — corpus pre-validation. Walks every case the operator's
        // flags will resolve to and refuses to enter plan-real/run-real if any
        // case is contaminated (empty seed, only README.md, missing
        // expected_changed_files, unknown case_set, etc.). This is the
        // canonical fail-closed gate that keeps a contaminated corpus from
        // turning into a false Atlas 100 × 0 score.
        //
        // Skip the gate for legacy hand-crafted presets (smoke/quick) that
        // resolve to a single non-corpus case via the cases registry; those
        // cases never enter the provider arena corpus and have no seed_dir.
        if ($this->corpusPreValidationApplies($preset, $caseSet, $case, $cases)) {
            $corpusValidation = $this->corpusPreValidation->validate([
                'preset' => $preset,
                'case_set' => $caseSet,
                'case' => $case,
                'cases' => $cases,
                'require_expected_changed_files' => $this->shouldRequireExpectedChangedFiles($preset, $caseSet, $case, $cases),
            ]);
            $phases[] = $this->phase('corpus-pre-validation', $corpusValidation);
            if (($corpusValidation['status'] ?? '') !== 'ok') {
                return $this->terminal(
                    $runId,
                    $mode,
                    $phases,
                    (array) ($corpusValidation['blockers'] ?? []),
                    'fix corpus contamination (empty seeds, missing expected_changed_files) before any provider call',
                    corpusValidation: $corpusValidation,
                );
            }
        }

        // Phase 5 — plan-real
        $planReal = $this->planReal->plan([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'case' => $case !== '' ? $case : null,
            'cases' => $cases,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
            'confirmations' => $sharedConfirms,
        ]);
        $phases[] = $this->phase('plan-real', $planReal);
        if (($planReal['status'] ?? '') !== 'ok') {
            return $this->terminal($runId, $mode, $phases, (array) ($planReal['blockers'] ?? []), 'fix plan-real blockers');
        }

        // --dry-run short-circuit: stop after plan-real with a planning-only
        // verdict. No worktrees executed, no provider invoked, no scorecard.
        if ($dryRunOnly) {
            return [
                'status' => 'ok',
                'run_battery_schema_version' => self::SCHEMA_VERSION,
                'run_id' => $runId,
                'mode' => $mode,
                'atlas_model' => $atlasModel,
                'rival_model' => $rivalModel,
                'preset' => $preset,
                'case' => $case !== '' ? $case : null,
                'case_set' => $caseSet !== '' ? $caseSet : null,
                'prompt_mode' => $promptMode,
                'dry_run' => true,
                'verdict' => 'dry_run_planned',
                'phases' => $phases,
                'phases_passed' => count(array_filter($phases, static fn (array $p): bool => $p['ok'])),
                'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! $p['ok'])),
                'winner' => null,
                'scorecard' => null,
                'requires_provider' => $requiresProvider,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'claim_ready' => false,
                'separated_from_external_rivals_certification' => true,
                'next_command' => 'php artisan atlas:forge:rivals run-battery --mode='.$mode.' --atlas-model='.$atlasModel.' --rival='.$rivalModel.' --preset='.$preset.' --prompt-mode='.$promptMode.' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
                'note' => 'Dry-run completed (preflight + dry-run + plan-real). No provider invoked. No score, no winner. Re-run without --dry-run to execute the battery.',
            ];
        }

        // Phase 6 — run-real (real provider gated; or local_fake)
        $runReal = $this->runReal->run([
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival' => $rivalModel,
            'preset' => $preset,
            'case' => $case !== '' ? $case : null,
            'cases' => $cases,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
            'run_id' => $runId,
            'confirmations' => $sharedConfirms,
            'resume' => $resumeRequested,
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
            'case' => $case !== '' ? $case : null,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
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

    private function normalizePromptMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            '', 'spec', 'spec_perfect', 'spec-perfect' => 'spec-perfect',
            'human', 'human_normal', 'human-normal' => 'human-normal',
            'messy', 'messy_real', 'messy-real' => 'messy-real',
            'enterprise', 'enterprise_change', 'enterprise-change' => 'enterprise-change',
            default => strtolower(trim($mode)),
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
     * Classify the verdict for a blocked battery so the operator can read it
     * at a glance instead of grepping blockers. Honest verdicts only —
     * "invalid_harness_blocked" is the catch-all; specific signals override.
     *
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $runReal
     * @param  array<string,mixed>|null  $corpusValidation
     */
    private function classifyBlockedVerdict(array $blockers, ?array $runReal, ?array $corpusValidation): string
    {
        $joined = implode('|', array_map(static fn ($b): string => (string) $b, $blockers));

        if ($runReal !== null) {
            if ((bool) data_get($runReal, 'atlas_arm_receipt.workspace_has_blocking_changes', false)
                || (bool) data_get($runReal, 'rival_arm_receipt.workspace_has_blocking_changes', false)
                || (bool) data_get($runReal, 'workspace_has_blocking_changes', false)
            ) {
                return 'invalid_dirty_after_run';
            }
        }

        if ($corpusValidation !== null && ($corpusValidation['blocked_count'] ?? 0) > 0) {
            return 'invalid_corpus_contaminated';
        }

        if (str_contains($joined, 'missing_confirmation:')) {
            return 'invalid_operator_confirmations_missing';
        }
        if (str_contains($joined, 'fixture_seed_empty:')
            || str_contains($joined, 'fixture_seed_no_stageable_files:')
            || str_contains($joined, 'fixture_seed_dir_missing:')
            || str_contains($joined, 'fixture_seed_dir_not_found:')
            || str_contains($joined, 'expected_changed_files_missing:')
        ) {
            return 'invalid_corpus_contaminated';
        }
        if (str_contains($joined, 'fingerprint_mismatch')
            || str_contains($joined, 'fingerprint_drift')
        ) {
            return 'invalid_fingerprint_divergence';
        }
        if (str_contains($joined, 'replay')
            || str_contains($joined, 'evidence')
        ) {
            return 'invalid_evidence_or_replay_failed';
        }

        return 'invalid_harness_blocked';
    }

    /**
     * Decide whether the corpus pre-validation gate applies to the operator's
     * flags. The gate is skipped for legacy hand-crafted single-case presets
     * (smoke/quick) where cases never enter the provider arena corpus and
     * therefore have no seed_dir to validate.
     *
     * @param  list<string>  $cases
     */
    private function corpusPreValidationApplies(string $preset, string $caseSet, string $case, array $cases): bool
    {
        if (trim($caseSet) !== '') {
            return true;
        }
        if ($cases !== []) {
            return true;
        }
        $presetKey = strtolower(trim($preset));
        if ($presetKey === AtlasForgeRivalsCasesRegistry::PRESET_RELEASE
            || $presetKey === AtlasForgeRivalsCasesRegistry::PRESET_FULL
        ) {
            return true;
        }

        // Explicit single case: apply only when it looks like a corpus case.
        // Corpus cases live under storage/forge-rivals-corpus/<case_id>/.
        if (trim($case) !== '' && is_dir(base_path('storage/forge-rivals-corpus/'.trim($case)))) {
            return true;
        }

        return false;
    }

    /**
     * Decide whether the corpus pre-validation should require
     * expected_changed_files for the resolved case-set. Strict for release
     * multi-case batteries and provider arena corpus runs; lenient for legacy
     * smoke/quick presets that intentionally resolve to a single legacy case.
     *
     * @param  list<string>  $cases
     */
    private function shouldRequireExpectedChangedFiles(string $preset, string $caseSet, string $case, array $cases): bool
    {
        $preset = strtolower(trim($preset));
        $caseSet = strtolower(trim($caseSet));

        if ($caseSet !== '') {
            return true;
        }
        if ($preset === AtlasForgeRivalsCasesRegistry::PRESET_RELEASE
            || $preset === AtlasForgeRivalsCasesRegistry::PRESET_FULL
        ) {
            return true;
        }

        return false;
    }

    /**
     * Refuse resume while another process is still emitting events for the
     * same run. A resume is only for crashed/stalled runners; concurrent
     * resume can mutate an arm while a provider is still working.
     *
     * @param  array<string,string>  $runPaths
     * @return list<string>
     */
    private function resumeActiveRunnerBlockers(array $runPaths): array
    {
        $batteryPath = (string) ($runPaths['base'] ?? '').DIRECTORY_SEPARATOR.'battery.json';
        $eventsPath = (string) ($runPaths['events_jsonl'] ?? '');
        if (! is_file($batteryPath) || ! is_file($eventsPath)) {
            return [];
        }

        $battery = json_decode((string) @file_get_contents($batteryPath), true);
        if (! is_array($battery)) {
            return [];
        }

        $hasRunningCase = false;
        foreach ((array) ($battery['cases'] ?? []) as $case) {
            if (is_array($case) && (string) ($case['state'] ?? '') === 'running') {
                $hasRunningCase = true;
                break;
            }
        }
        if (! $hasRunningCase) {
            return [];
        }

        $mtime = @filemtime($eventsPath);
        if (! is_int($mtime)) {
            return [];
        }

        $ageSeconds = max(0, time() - $mtime);
        if ($ageSeconds <= 30) {
            return ['resume_refused_active_runner_heartbeat:'.$ageSeconds.'s'];
        }

        return [];
    }

    /**
     * Resume can restart a case that was interrupted while an arm worktree
     * still contains provider output. Clean only the generated rival arms,
     * never the operator's source workspace, so preflight sees the same clean
     * baseline that run-real would restore between cases.
     *
     * @param  array<string,string>  $runPaths
     * @return array{status:string,blockers:list<string>}
     */
    private function prepareResumeWorktrees(array $runPaths): array
    {
        $blockers = [];
        $armsRoot = realpath((string) ($runPaths['arms_root'] ?? '')) ?: null;

        foreach (['atlas', 'rival'] as $arm) {
            $worktree = (string) ($runPaths[$arm] ?? '');
            $realWorktree = realpath($worktree) ?: null;
            if ($armsRoot === null || $realWorktree === null || ! str_starts_with($realWorktree, $armsRoot.DIRECTORY_SEPARATOR)) {
                $blockers[] = 'resume_worktree_cleanup_refused:'.$arm;

                continue;
            }
            if (! is_dir($worktree.'/.git') && ! is_file($worktree.'/.git')) {
                $blockers[] = 'resume_worktree_not_git:'.$arm;

                continue;
            }

            $reset = new Process(['git', '-C', $worktree, 'reset', '--hard', 'HEAD']);
            $reset->setTimeout(30);
            $reset->run();
            if (! $reset->isSuccessful()) {
                $blockers[] = 'resume_worktree_reset_failed:'.$arm;

                continue;
            }

            $clean = new Process([
                'git',
                '-C',
                $worktree,
                'clean',
                '-ffdx',
                '-e',
                '/vendor',
                '-e',
                '/.env',
                '-e',
                '/.env.testing',
            ]);
            $clean->setTimeout(30);
            $clean->run();
            if (! $clean->isSuccessful()) {
                $blockers[] = 'resume_worktree_clean_failed:'.$arm;

                continue;
            }

            $status = new Process(['git', '-C', $worktree, 'status', '--porcelain']);
            $status->setTimeout(15);
            $status->run();
            if (! $status->isSuccessful() || trim((string) $status->getOutput()) !== '') {
                $blockers[] = 'resume_worktree_still_dirty:'.$arm;
            }
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $phases
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $runReal
     * @param  array<string,mixed>|null  $corpusValidation
     * @return array<string,mixed>
     */
    private function terminal(
        string $runId,
        string $mode,
        array $phases,
        array $blockers,
        string $hint,
        ?array $runReal = null,
        ?array $corpusValidation = null,
    ): array {
        $runPaths = null;
        try {
            $runPaths = $this->paths->paths($runId);
        } catch (\Throwable) {
            $runPaths = null;
        }

        $verdict = $this->classifyBlockedVerdict($blockers, $runReal, $corpusValidation);

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
            'score' => null,
            'scorecard' => null,
            'verdict' => $verdict,
            'claim_ready' => false,
            'human_review_required' => true,
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
            'corpus_pre_validation' => $corpusValidation,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Battery aborted before declaring a winner. Partial evidence preserved at runs/<run_id>/.',
            'next_command' => $hint,
        ];
    }
}
