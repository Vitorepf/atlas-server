<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmContractService;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusPlannerService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Arena Run (Provider Arena Core v1).
 *
 * The arena-flavoured entrypoint that lets the operator pit any two
 * declared arms against each other (`arm_a` vs `arm_b`) for a given
 * task category. Bridges to the existing `RunBatteryService` so the
 * heavy lifting (preflight → setup → run-real → collect-evidence →
 * replay → adjudicate → report) stays unchanged.
 *
 * Safety rules (never weakened):
 *   - Both arm contracts must resolve before anything runs.
 *   - Any unknown arm / task category / model ⇒ honest blocker.
 *   - Real-provider arms ⇒ three operator confirmations required.
 *   - `local_fake` mode never invokes a provider, even with confirmations.
 *   - `not_yet_executable` arms ⇒ honest blocker outside `local_fake`.
 *   - Placeholder arms ⇒ honest blocker in every mode.
 *   - This service never unlocks `external_rivals_certification`.
 *   - This service never declares `claim_ready` by itself; the adjudicator
 *     decides via the underlying battery.
 *
 * Schema: atlas.forge.rivals.provider_arena_run.v1
 */
final class AtlasForgeRivalsArenaRunService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_run.v1';

    public function __construct(
        private readonly AtlasForgeRivalsArmRegistryService $registry,
        private readonly AtlasForgeRivalsArmContractService $contracts,
        private readonly AtlasForgeRivalsRunBatteryService $battery,
        private readonly AtlasForgeRivalsModeRegistry $modes,
        private readonly AtlasForgeRivalsCorpusPlannerService $corpusPlanner,
        private readonly AtlasForgeRivalsArmCommandBuilderService $commandBuilder,
        private readonly AtlasForgeRivalsSetupService $setup,
        private readonly AtlasForgeRivalsRunRealService $runReal,
        private readonly AtlasForgeRivalsCollectEvidenceService $collectEvidence,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsAdjudicatorService $adjudicator,
        private readonly AtlasForgeRivalsReportService $report,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $rawMode = trim((string) ($input['mode'] ?? ''));
        $mode = $this->normalizeMode($rawMode);
        $taskCategory = strtolower(trim((string) ($input['task_category'] ?? '')));
        $confirmations = (array) ($input['confirmations'] ?? []);
        $preset = trim((string) ($input['preset'] ?? 'quick'));
        $runId = trim((string) ($input['run_id'] ?? ''));
        $dryRunOnly = (bool) ($input['dry_run'] ?? false);
        $promptMode = trim((string) ($input['prompt_mode'] ?? 'spec-perfect'));

        $armAId = strtolower(trim((string) ($input['arm_a'] ?? '')));
        $armBId = strtolower(trim((string) ($input['arm_b'] ?? '')));
        if ($armAId === AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV_LIGHT) {
            $armAId = AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV;
        }
        if ($armBId === AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV_LIGHT) {
            $armBId = AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV;
        }
        $armAModel = trim((string) ($input['arm_a_model'] ?? ''));
        $armBModel = trim((string) ($input['arm_b_model'] ?? ''));

        $blockers = [];

        if (! in_array($mode, [
            AtlasForgeRivalsModeRegistry::MODE_FAIR,
            AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
            AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE,
        ], true)) {
            $blockers[] = 'mode_not_admissible_for_run_arena:'.$rawMode;
        }

        if ($armAId === '' || $armBId === '') {
            $blockers[] = 'arms_required:--arm-a and --arm-b';
        }
        $corpusCaseSet = trim((string) ($input['case_set'] ?? ''));
        $corpusCase = trim((string) ($input['case'] ?? ''));
        $usingCorpus = $corpusCaseSet !== '' || $corpusCase !== '';
        if ($taskCategory === '' && ! $usingCorpus) {
            $blockers[] = 'task_category_required:--task-category';
        }

        if ($blockers !== []) {
            return $this->terminal($runId, $rawMode, $blockers, $armAId, $armBId, $taskCategory, []);
        }

        // When --case-set / --case is in play we eagerly resolve the corpus
        // plan so contract validation has a real task_category to consult
        // (corpus inherently encodes categories). A blocked plan stops here
        // before we touch arm contracts.
        $resolvedPlan = null;
        if ($usingCorpus) {
            $resolvedPlan = $this->corpusPlanner->plan([
                'case_set' => $corpusCaseSet,
                'case' => $corpusCase,
                'task_category' => $taskCategory,
            ]);
            if (($resolvedPlan['status'] ?? '') === 'blocked') {
                return $this->terminal(
                    $runId,
                    $mode,
                    (array) ($resolvedPlan['blockers'] ?? []),
                    $armAId,
                    $armBId,
                    $taskCategory,
                    [],
                );
            }
            if ($taskCategory === '') {
                $first = $resolvedPlan['cases'][0] ?? null;
                if (is_array($first) && isset($first['task_category']) && trim((string) $first['task_category']) !== '') {
                    $taskCategory = strtolower(trim((string) $first['task_category']));
                }
            }
        }

        $contractA = $this->contracts->contract('arm_a', [
            'arm_id' => $armAId,
            'model' => $armAModel,
            'task_category' => $taskCategory,
            'mode' => $mode,
            'dry_run' => $dryRunOnly,
        ]);
        $contractB = $this->contracts->contract('arm_b', [
            'arm_id' => $armBId,
            'model' => $armBModel,
            'task_category' => $taskCategory,
            'mode' => $mode,
            'dry_run' => $dryRunOnly,
        ]);

        $blockers = array_merge($blockers, (array) $contractA['blockers'], (array) $contractB['blockers']);

        // Real provider arms enforce three operator confirmations BEFORE we hand off to RunBatteryService.
        $requiresProvider = (bool) ($contractA['external_provider_call'])
            || (bool) ($contractB['external_provider_call']);
        if ($requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE && ! $dryRunOnly) {
            foreach (['runbook_reviewed', 'provider_cost', 'real_provider_call'] as $confirm) {
                if (! ($confirmations[$confirm] ?? false)) {
                    $blockers[] = 'missing_confirmation:'.$confirm;
                }
            }
        }

        // Fair mode + cross-provider arms: refuse honestly.
        if ($mode === AtlasForgeRivalsModeRegistry::MODE_FAIR) {
            $providerA = $contractA['arm']['provider'] ?? null;
            $providerB = $contractB['arm']['provider'] ?? null;
            $modelA = $contractA['resolved_model'];
            $modelB = $contractB['resolved_model'];
            if ($providerA !== null && $providerB !== null && $providerA !== $providerB) {
                $blockers[] = 'fair_mode_requires_same_provider_on_both_arms:'.$providerA.'_vs_'.$providerB;
            }
            if ($modelA !== null && $modelB !== null && $this->canonicalModel($modelA) !== $this->canonicalModel($modelB)) {
                $blockers[] = 'fair_mode_requires_same_model_on_both_arms';
            }
        }

        if ($blockers !== []) {
            return $this->terminal(
                $runId,
                $mode,
                $blockers,
                $armAId,
                $armBId,
                $taskCategory,
                ['arm_a' => $contractA, 'arm_b' => $contractB],
            );
        }

        if ($dryRunOnly) {
            return $this->arenaPlanResult(
                $runId,
                $mode,
                $armAId,
                $armBId,
                $taskCategory,
                $contractA,
                $contractB,
                $requiresProvider,
                $promptMode,
                is_array($resolvedPlan) ? $resolvedPlan : null,
            );
        }

        // Corpus integration: --case / --case-set resolves to a declarative
        // multi-case plan. In local_fake we return the plan as the result
        // (no battery invocation, no provider call). In provider_arena /
        // provider_pure the v2 executor reuses RunRealService so worktree
        // isolation, streaming receipts, replay artifacts and resume stay on
        // the same contract surface as run-battery.
        if ($usingCorpus && is_array($resolvedPlan)) {
            if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
                return $this->corpusDryRunResult(
                    $runId,
                    $mode,
                    $armAId,
                    $armBId,
                    $taskCategory,
                    $contractA,
                    $contractB,
                    $requiresProvider,
                    $resolvedPlan,
                    $corpusCaseSet,
                    $corpusCase,
                );
            }
            if (! in_array($mode, [
                AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
                AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
                AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE,
            ], true)) {
                return $this->terminal(
                    $runId,
                    $mode,
                    ['real_multi_case_requires_provider_arena_v2_mode:use_--mode=provider_arena_or_full_power_or_--dry-run'],
                    $armAId,
                    $armBId,
                    $taskCategory,
                    ['arm_a' => $contractA, 'arm_b' => $contractB],
                );
            }

            return $this->runProviderArenaReal(
                $runId,
                $mode,
                $armAId,
                $armBId,
                $taskCategory,
                $contractA,
                $contractB,
                $preset,
                $promptMode,
                $sourceRef = trim((string) ($input['source_ref'] ?? 'HEAD')),
                $confirmations,
                $corpusCaseSet,
                $corpusCase,
                true,
            );
        }

        if (in_array($mode, [
            AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
            AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE,
        ], true)) {
            return $this->runProviderArenaReal(
                $runId,
                $mode,
                $armAId,
                $armBId,
                $taskCategory,
                $contractA,
                $contractB,
                $preset,
                $promptMode,
                trim((string) ($input['source_ref'] ?? 'HEAD')),
                $confirmations,
                '',
                '',
                false,
            );
        }

        // Map to the legacy battery contract. arm_a → atlas_model, arm_b → rival.
        $legacyAtlasModel = (string) ($contractA['legacy_model_id'] ?? 'claude_sonnet');
        $legacyRivalModel = (string) ($contractB['legacy_model_id'] ?? $legacyAtlasModel);

        $batteryInput = [
            'mode' => $mode,
            'atlas_model' => $legacyAtlasModel,
            'rival' => $legacyRivalModel,
            'preset' => $preset,
            'prompt_mode' => $promptMode,
            'source_ref' => trim((string) ($input['source_ref'] ?? 'HEAD')),
            'confirmations' => [
                'runbook_reviewed' => (bool) ($confirmations['runbook_reviewed'] ?? false),
                'provider_cost' => (bool) ($confirmations['provider_cost'] ?? false),
                'real_provider_call' => (bool) ($confirmations['real_provider_call'] ?? false),
            ],
            'run_id' => $runId,
        ];

        $batteryResult = $this->battery->run($batteryInput);

        $arenaContext = [
            'arena_schema_version' => self::SCHEMA_VERSION,
            'arm_a' => [
                'arm_id' => $armAId,
                'runner_type' => $contractA['arm']['runner_type'] ?? null,
                'provider' => $contractA['arm']['provider'] ?? null,
                'model' => $contractA['resolved_model'],
                'model_id' => $contractA['resolved_model_id'],
                'legacy_model_id' => $contractA['legacy_model_id'],
                'status' => $contractA['arm']['status'] ?? null,
                'safety_contract' => $contractA['safety_contract'],
                'human_label' => $contractA['arm']['human_label'] ?? null,
            ],
            'arm_b' => [
                'arm_id' => $armBId,
                'runner_type' => $contractB['arm']['runner_type'] ?? null,
                'provider' => $contractB['arm']['provider'] ?? null,
                'model' => $contractB['resolved_model'],
                'model_id' => $contractB['resolved_model_id'],
                'legacy_model_id' => $contractB['legacy_model_id'],
                'status' => $contractB['arm']['status'] ?? null,
                'safety_contract' => $contractB['safety_contract'],
                'human_label' => $contractB['arm']['human_label'] ?? null,
            ],
            'task_category' => $taskCategory,
            'prompt_mode' => $promptMode,
            'requires_external_provider_call' => $requiresProvider,
            'safety_promises' => [
                'never_promotes_completion_claim' => true,
                'never_unlocks_external_rivals_certification' => true,
                'scripted_or_manual_cannot_forge_score' => true,
            ],
            'separated_from_external_rivals_certification' => true,
        ];

        $merged = array_replace($batteryResult, $arenaContext);
        // Always expose the action's authoritative status from the battery layer.
        $merged['status'] = (string) ($batteryResult['status'] ?? 'blocked');
        $merged['note'] = $merged['status'] === 'ok'
            ? 'Provider Arena run completed end-to-end via RunBatteryService.'
            : 'Provider Arena run halted before declaring a winner.';

        return $merged;
    }

    /**
     * @param  array<string,mixed>  $contractA
     * @param  array<string,mixed>  $contractB
     * @return array<string,mixed>
     */
    private function arenaPlanResult(
        string $runId,
        string $mode,
        string $armAId,
        string $armBId,
        string $taskCategory,
        array $contractA,
        array $contractB,
        bool $requiresProvider,
        string $promptMode,
        ?array $resolvedPlan = null,
    ): array {
        $previewPrompt = 'Provider Arena v2 dry-run command preview. Real case prompt is generated per case at execution time.';
        $commandA = $this->commandBuilder->build($contractA, $previewPrompt, '<arm_a_worktree>');
        $commandB = $this->commandBuilder->build($contractB, $previewPrompt, '<arm_b_worktree>');

        return [
            'status' => 'ok',
            'arena_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId === '' ? 'arena-plan-'.gmdate('Ymd-His') : $runId,
            'mode' => $mode,
            'dry_run' => true,
            'verdict' => 'arena_plan_ready',
            'arm_a' => $this->armEnvelope($armAId, $contractA),
            'arm_b' => $this->armEnvelope($armBId, $contractB),
            'task_category' => $taskCategory,
            'prompt_mode' => $promptMode !== '' ? $promptMode : 'spec-perfect',
            'case_set' => $this->resolvedPlanCaseSet($resolvedPlan),
            'case_count' => is_array($resolvedPlan) ? (int) ($resolvedPlan['count'] ?? 0) : null,
            'cases' => is_array($resolvedPlan) ? ($resolvedPlan['cases'] ?? []) : [],
            'requires_external_provider_call' => $requiresProvider,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'winner' => null,
            'scorecard' => null,
            'command_plan' => [
                'arm_a' => $this->redactPromptFromCommandPlan($commandA),
                'arm_b' => $this->redactPromptFromCommandPlan($commandB),
            ],
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals run-arena --arm-a='.$armAId.' --arm-b='.$armBId.' --task-category='.$taskCategory.' --mode='.$mode.' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
            'note' => 'Provider Arena v2 dry-run: contracts and commands resolved without invoking providers.',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $resolvedPlan
     */
    private function resolvedPlanCaseSet(?array $resolvedPlan): ?string
    {
        if (! is_array($resolvedPlan)) {
            return null;
        }

        foreach ((array) ($resolvedPlan['applied_filters'] ?? []) as $filter) {
            $filter = (string) $filter;

            if (str_starts_with($filter, 'case_set=')) {
                return substr($filter, strlen('case_set='));
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $contracts
     * @return array<string,mixed>
     */
    private function terminal(
        string $runId,
        string $mode,
        array $blockers,
        string $armAId,
        string $armBId,
        string $taskCategory,
        array $contracts,
    ): array {
        return [
            'status' => 'blocked',
            'arena_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'mode' => $mode,
            'arm_a' => [
                'arm_id' => $armAId,
                'contract' => $contracts['arm_a'] ?? null,
            ],
            'arm_b' => [
                'arm_id' => $armBId,
                'contract' => $contracts['arm_b'] ?? null,
            ],
            'task_category' => $taskCategory,
            'blockers' => array_values(array_unique(array_map(static fn ($b): string => (string) $b, $blockers))),
            'winner' => null,
            'scorecard' => null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'fix arena blockers and re-run: php artisan atlas:forge:rivals run-arena ... --json',
            'note' => 'Arena run blocked before invoking RunBatteryService — no provider call, no token spend.',
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function armEnvelope(string $armId, array $contract): array
    {
        return [
            'arm_id' => $armId,
            'runner_type' => $contract['arm']['runner_type'] ?? null,
            'provider' => $contract['provider'] ?? ($contract['arm']['provider'] ?? null),
            'model' => $contract['resolved_model'] ?? null,
            'model_id' => $contract['resolved_model_id'] ?? null,
            'model_label' => $contract['resolved_model_label'] ?? null,
            'legacy_model_id' => $contract['legacy_model_id'] ?? null,
            'status' => $contract['arm']['status'] ?? null,
            'safety_contract' => $contract['safety_contract'] ?? [],
            'human_label' => $contract['arm']['human_label'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function redactPromptFromCommandPlan(array $plan): array
    {
        $command = (array) ($plan['command'] ?? []);
        if ($command !== []) {
            $last = array_key_last($command);
            if ($last !== null) {
                $command[$last] = '<prompt>';
            }
        }

        return [
            'ok' => (bool) ($plan['ok'] ?? false),
            'provider' => $plan['provider'] ?? null,
            'model' => $plan['model'] ?? null,
            'model_id' => $plan['model_id'] ?? null,
            'command_family' => $plan['command_family'] ?? null,
            'command' => array_values(array_map(static fn ($part): string => (string) $part, $command)),
            'blockers' => array_values(array_map(static fn ($b): string => (string) $b, (array) ($plan['blockers'] ?? []))),
        ];
    }

    /**
     * @param  array<string,mixed>  $contractA
     * @param  array<string,mixed>  $contractB
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function corpusDryRunResult(
        string $runId,
        string $mode,
        string $armAId,
        string $armBId,
        string $taskCategory,
        array $contractA,
        array $contractB,
        bool $requiresProvider,
        array $plan,
        string $caseSet,
        string $caseId,
    ): array {
        return [
            'status' => 'ok',
            'arena_schema_version' => self::SCHEMA_VERSION,
            'corpus_schema_version' => AtlasForgeRivalsProviderArenaCorpusService::SCHEMA_VERSION,
            'run_id' => $runId === '' ? 'arena-corpus-'.gmdate('Ymd-His') : $runId,
            'mode' => $mode,
            'corpus_dry_run' => true,
            'arm_a' => [
                'arm_id' => $armAId,
                'runner_type' => $contractA['arm']['runner_type'] ?? null,
                'provider' => $contractA['arm']['provider'] ?? null,
                'model' => $contractA['resolved_model'],
                'model_id' => $contractA['resolved_model_id'],
                'legacy_model_id' => $contractA['legacy_model_id'],
                'status' => $contractA['arm']['status'] ?? null,
                'safety_contract' => $contractA['safety_contract'],
                'human_label' => $contractA['arm']['human_label'] ?? null,
            ],
            'arm_b' => [
                'arm_id' => $armBId,
                'runner_type' => $contractB['arm']['runner_type'] ?? null,
                'provider' => $contractB['arm']['provider'] ?? null,
                'model' => $contractB['resolved_model'],
                'model_id' => $contractB['resolved_model_id'],
                'legacy_model_id' => $contractB['legacy_model_id'],
                'status' => $contractB['arm']['status'] ?? null,
                'safety_contract' => $contractB['safety_contract'],
                'human_label' => $contractB['arm']['human_label'] ?? null,
            ],
            'task_category' => $taskCategory,
            'case_set' => $caseSet,
            'case' => $caseId,
            'applied_filters' => $plan['applied_filters'] ?? [],
            'cases' => $plan['cases'] ?? [],
            'count' => $plan['count'] ?? 0,
            'replay_manifest' => $plan['replay_manifest'] ?? null,
            'winner' => null,
            'scorecard' => null,
            'requires_external_provider_call' => $requiresProvider,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'safety_promises' => [
                'never_promotes_completion_claim' => true,
                'never_unlocks_external_rivals_certification' => true,
                'scripted_or_manual_cannot_forge_score' => true,
                'dry_run_never_invokes_provider' => true,
            ],
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals cases --case-set='.($caseSet !== '' ? $caseSet : 'quick').' --json',
            'note' => 'Provider Arena Corpus dry-run: plano multi-case emitido sem invocar provider ou battery. Cada caso é replayable via replay_manifest.',
        ];
    }

    /**
     * @param  array<string,mixed>  $contractA
     * @param  array<string,mixed>  $contractB
     * @param  array<string,mixed>  $confirmations
     * @return array<string,mixed>
     */
    private function runProviderArenaReal(
        string $runId,
        string $mode,
        string $armAId,
        string $armBId,
        string $taskCategory,
        array $contractA,
        array $contractB,
        string $preset,
        string $promptMode,
        string $sourceRef,
        array $confirmations,
        string $caseSet,
        string $caseId,
        bool $usingCorpus,
    ): array {
        if ($runId === '') {
            $runId = 'arena-'.gmdate('Ymd-His').'-'.substr(hash('sha256', $armAId.'|'.$armBId.'|'.microtime(true)), 0, 6);
        }
        $promptMode = $promptMode !== '' ? $promptMode : 'spec-perfect';
        $preset = $preset !== '' ? $preset : 'quick';
        $sourceRef = $sourceRef !== '' ? $sourceRef : 'HEAD';

        $phases = [];
        $setup = $this->setup->provision([
            'run_id' => $runId,
            'source_ref' => $sourceRef,
        ]);
        $phases[] = $this->phase('setup', $setup);
        if (($setup['status'] ?? '') !== 'ok') {
            return $this->arenaPipelineBlocked($runId, $mode, $armAId, $armBId, $taskCategory, $contractA, $contractB, $phases, (array) ($setup['blockers'] ?? []), 'fix setup blockers');
        }

        $runReal = $this->runReal->run([
            'mode' => $mode,
            'atlas_model' => (string) ($contractA['legacy_model_id'] ?? $contractA['resolved_model'] ?? 'claude_sonnet'),
            'rival' => (string) ($contractB['legacy_model_id'] ?? $contractB['resolved_model'] ?? 'claude_sonnet'),
            'preset' => $usingCorpus ? 'release' : $preset,
            'case' => $caseId !== '' ? $caseId : null,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'prompt_mode' => $promptMode,
            'run_id' => $runId,
            'confirmations' => [
                'runbook_reviewed' => (bool) ($confirmations['runbook_reviewed'] ?? false),
                'provider_cost' => (bool) ($confirmations['provider_cost'] ?? false),
                'real_provider_call' => (bool) ($confirmations['real_provider_call'] ?? false),
            ],
            'arena_contracts' => [
                'arm_a' => $contractA,
                'arm_b' => $contractB,
            ],
        ]);
        $phases[] = $this->phase('run-real', $runReal);
        if (($runReal['status'] ?? '') !== 'ok') {
            return $this->arenaPipelineBlocked($runId, $mode, $armAId, $armBId, $taskCategory, $contractA, $contractB, $phases, (array) ($runReal['blockers'] ?? []), 'fix run-real blockers', $runReal);
        }

        foreach ([
            ['collect-evidence-pre', fn (): array => $this->collectEvidence->collect(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION]), 'fix evidence blockers'],
            ['replay-pre', fn (): array => $this->replay->replay(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION]), 'replay failed - evidence pack untrustworthy'],
            ['adjudicate', fn (): array => $this->adjudicator->adjudicate(['run_id' => $runId]), 'fix adjudicator blockers'],
            ['collect-evidence-final', fn (): array => $this->collectEvidence->collect(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL]), 'fix final evidence blockers'],
            ['replay-final', fn (): array => $this->replay->replay(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL]), 'final replay failed - scorecard hash drifted'],
            ['report', fn (): array => $this->report->render(['run_id' => $runId]), 'fix report blockers'],
        ] as [$phaseName, $callback, $hint]) {
            $payload = $callback();
            $phases[] = $this->phase((string) $phaseName, $payload);
            if (($payload['status'] ?? '') !== 'ok') {
                return $this->arenaPipelineBlocked($runId, $mode, $armAId, $armBId, $taskCategory, $contractA, $contractB, $phases, (array) ($payload['blockers'] ?? []), (string) $hint);
            }
            if ($phaseName === 'adjudicate') {
                $adjudicate = $payload;
            } elseif ($phaseName === 'replay-final') {
                $replayFinal = $payload;
            } elseif ($phaseName === 'report') {
                $report = $payload;
            }
        }

        $paths = $this->paths->paths($runId);
        $scorecard = is_array(($adjudicate ?? [])['scorecard'] ?? null) ? $adjudicate['scorecard'] : null;

        return [
            'status' => 'ok',
            'arena_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'mode' => $mode,
            'executor' => 'provider_arena_v2',
            'using_corpus' => $usingCorpus,
            'arm_a' => $this->armEnvelope($armAId, $contractA),
            'arm_b' => $this->armEnvelope($armBId, $contractB),
            'task_category' => $taskCategory,
            'preset' => $preset,
            'case_set' => $caseSet !== '' ? $caseSet : null,
            'case' => $caseId !== '' ? $caseId : null,
            'prompt_mode' => $promptMode,
            'phases' => $phases,
            'phases_passed' => count(array_filter($phases, static fn (array $p): bool => (bool) ($p['ok'] ?? false))),
            'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! (bool) ($p['ok'] ?? false))),
            'winner' => $scorecard['winner'] ?? null,
            'scorecard' => $scorecard,
            'manifest' => $runReal['manifest'] ?? null,
            'report_path' => ($report ?? [])['report_path'] ?? null,
            'evidence_paths' => array_values(array_filter([
                $paths['events_jsonl'],
                $paths['manifest_json'],
                $paths['scorecard_json'],
                $paths['report_md'],
            ], static fn (string $path): bool => is_file($path))),
            'external_provider_call' => (bool) ($runReal['external_provider_call'] ?? true),
            'provider_tokens_spent' => (bool) ($runReal['provider_tokens_spent'] ?? true),
            'claim_ready' => false,
            'replay_passes' => (bool) (($replayFinal ?? [])['replay_passes'] ?? false),
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals report --run-id='.$runId.' --json',
            'note' => 'Provider Arena v2 completed with replay-gated evidence. Rivals emits measured evidence; Atlas Decide decides model routing.',
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
            'blockers' => array_values(array_map(static fn ($blocker): string => (string) $blocker, (array) ($payload['blockers'] ?? []))),
        ];
    }

    /**
     * @param  array<string,mixed>  $contractA
     * @param  array<string,mixed>  $contractB
     * @param  list<array<string,mixed>>  $phases
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $runReal
     * @return array<string,mixed>
     */
    private function arenaPipelineBlocked(
        string $runId,
        string $mode,
        string $armAId,
        string $armBId,
        string $taskCategory,
        array $contractA,
        array $contractB,
        array $phases,
        array $blockers,
        string $hint,
        ?array $runReal = null,
    ): array {
        return [
            'status' => 'blocked',
            'arena_schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'mode' => $mode,
            'executor' => 'provider_arena_v2',
            'arm_a' => $this->armEnvelope($armAId, $contractA),
            'arm_b' => $this->armEnvelope($armBId, $contractB),
            'task_category' => $taskCategory,
            'phases' => $phases,
            'phases_passed' => count(array_filter($phases, static fn (array $p): bool => (bool) ($p['ok'] ?? false))),
            'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! (bool) ($p['ok'] ?? false))),
            'blockers' => array_values(array_unique(array_map(static fn ($b): string => (string) $b, $blockers))),
            'failed_run_real' => $runReal,
            'winner' => null,
            'scorecard' => null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'next_command' => $hint,
            'note' => 'Provider Arena v2 halted before declaring a winner. No score is claimable until evidence, replay and matrix gates pass.',
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'power', 'full-power' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            'provider-arena', 'arena' => AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
            'provider-pure', 'pure' => AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE,
            '' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            default => strtolower($mode),
        };
    }

    private function canonicalModel(string $model): string
    {
        return match (strtolower($model)) {
            'sonnet' => 'claude_sonnet',
            'opus' => 'claude_opus',
            'gpt-codex', 'codex-default', 'gpt-5.5' => 'codex',
            default => strtolower($model),
        };
    }
}
