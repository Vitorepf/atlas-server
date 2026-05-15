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

        $armAId = strtolower(trim((string) ($input['arm_a'] ?? '')));
        $armBId = strtolower(trim((string) ($input['arm_b'] ?? '')));
        $armAModel = trim((string) ($input['arm_a_model'] ?? ''));
        $armBModel = trim((string) ($input['arm_b_model'] ?? ''));

        $blockers = [];

        if (! in_array($mode, [
            AtlasForgeRivalsModeRegistry::MODE_FAIR,
            AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
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
        ]);
        $contractB = $this->contracts->contract('arm_b', [
            'arm_id' => $armBId,
            'model' => $armBModel,
            'task_category' => $taskCategory,
            'mode' => $mode,
        ]);

        $blockers = array_merge($blockers, (array) $contractA['blockers'], (array) $contractB['blockers']);

        // Real provider arms enforce three operator confirmations BEFORE we hand off to RunBatteryService.
        $requiresProvider = (bool) ($contractA['external_provider_call'])
            || (bool) ($contractB['external_provider_call']);
        if ($requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
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

        // Corpus integration: --case / --case-set resolves to a declarative
        // multi-case plan. In local_fake we return the plan as the result
        // (no battery invocation, no provider call). Real-mode multi-case
        // execution is pending implementation — fail closed honestly rather
        // than pretend support.
        if ($usingCorpus && is_array($resolvedPlan)) {
            if ($mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
                return $this->terminal(
                    $runId,
                    $mode,
                    ['real_multi_case_pending_implementation:use_mode=local_fake_for_corpus_dry_run'],
                    $armAId,
                    $armBId,
                    $taskCategory,
                    ['arm_a' => $contractA, 'arm_b' => $contractB],
                );
            }

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

        // Map to the legacy battery contract. arm_a → atlas_model, arm_b → rival.
        $legacyAtlasModel = (string) ($contractA['legacy_model_id'] ?? 'claude_sonnet');
        $legacyRivalModel = (string) ($contractB['legacy_model_id'] ?? $legacyAtlasModel);

        $batteryInput = [
            'mode' => $mode,
            'atlas_model' => $legacyAtlasModel,
            'rival' => $legacyRivalModel,
            'preset' => $preset,
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
                'legacy_model_id' => $contractB['legacy_model_id'],
                'status' => $contractB['arm']['status'] ?? null,
                'safety_contract' => $contractB['safety_contract'],
                'human_label' => $contractB['arm']['human_label'] ?? null,
            ],
            'task_category' => $taskCategory,
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

    private function normalizeMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'power', 'full-power' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            '' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            default => strtolower($mode),
        };
    }

    private function canonicalModel(string $model): string
    {
        return match (strtolower($model)) {
            'sonnet' => 'claude_sonnet',
            'opus' => 'claude_opus',
            default => strtolower($model),
        };
    }
}
