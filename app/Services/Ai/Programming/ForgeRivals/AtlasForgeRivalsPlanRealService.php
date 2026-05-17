<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Plan-Real.
 *
 * Emits the final operator-facing plan for a real run: cost estimate,
 * required confirmations, the EXACT next command line including every
 * `--confirm-*` flag, and the per-arm provider/runtime that WILL be
 * dispatched. Never dispatches anything.
 *
 * The cost estimate is intentionally honest: for `fair`/`full_power` modes
 * we declare 'paid_provider_invocation', for `local_fake` we declare
 * 'no_paid_calls', etc. Operator can use the plan to gate spend.
 */
final class AtlasForgeRivalsPlanRealService
{
    public function __construct(
        private readonly AtlasForgeRivalsModeRegistry $modes,
        private readonly AtlasForgeRivalsModelMatrix $matrix,
        private readonly AtlasForgeRivalsCasesRegistry $cases,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $mode = trim((string) ($input['mode'] ?? 'fair'));
        $atlasModel = trim((string) ($input['atlas_model'] ?? 'claude_sonnet'));
        $rivalModel = trim((string) ($input['rival'] ?? $atlasModel));
        $preset = trim((string) ($input['preset'] ?? 'smoke'));
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $confirms = (array) ($input['confirmations'] ?? []);
        $arenaContracts = is_array($input['arena_contracts'] ?? null) ? (array) $input['arena_contracts'] : [];
        $usingArenaContracts = is_array($arenaContracts['arm_a'] ?? null) && is_array($arenaContracts['arm_b'] ?? null);
        [$workspace, $baselineWorkspace, $runId] = $this->resolveRunContext($input);

        $blockers = [];
        $modeDef = null;
        try {
            $modeDef = $this->modes->mode($mode);
        } catch (\InvalidArgumentException $e) {
            $blockers[] = 'unknown_mode:'.$mode;
        }

        $matrixResult = $usingArenaContracts
            ? ['ok' => true, 'blockers' => [], 'reason' => 'provider_arena_contracts_resolve_models_before_plan_real']
            : $this->matrix->validate($mode, $atlasModel, $rivalModel);
        foreach ($matrixResult['blockers'] as $b) {
            $blockers[] = $b;
        }

        $cases = [];
        $presetCaseSet = $caseSet !== '' ? $caseSet : $this->presetCaseSet($preset);
        if ($presetCaseSet !== null) {
            try {
                $cases = $this->corpus->casesForCaseSet($presetCaseSet);
            } catch (\Throwable $e) {
                $blockers[] = $e->getMessage() !== '' ? $e->getMessage() : 'unknown_case_set:'.$presetCaseSet;
            }
        } else {
            try {
                $cases = $this->cases->casesForPreset($preset);
            } catch (EmptyPresetIsFatalHarnessBug $e) {
                $blockers[] = 'zero_case_preset_fatal_harness_bug:'.$preset;
            } catch (\Throwable $e) {
                $blockers[] = 'preset_unknown:'.$preset;
            }
        }

        $requiresProvider = $modeDef !== null && $modeDef['requires_provider'];
        $confirmsPresent = [
            'runbook_reviewed' => (bool) ($confirms['runbook_reviewed'] ?? false),
            'provider_cost' => (bool) ($confirms['provider_cost'] ?? false),
            'real_provider_call' => (bool) ($confirms['real_provider_call'] ?? false),
        ];
        $missingConfirms = [];
        if ($requiresProvider) {
            foreach ($confirmsPresent as $k => $v) {
                if (! $v) {
                    $missingConfirms[] = 'missing_confirmation:'.$k;
                }
            }
        }

        $costEstimate = $this->costEstimate($mode, $atlasModel, $rivalModel, $preset, count($cases));
        $runCommand = sprintf(
            'php artisan atlas:forge:rivals run-real%s --mode=%s --atlas-model=%s --rival=%s --preset=%s%s%s%s%s --json',
            $runId !== null ? ' --run-id='.$runId : '',
            $mode,
            $atlasModel,
            $rivalModel,
            $preset,
            $presetCaseSet !== null ? ' --case-set='.$presetCaseSet : '',
            $workspace !== null && $runId === null ? ' --atlas-worktree='.$workspace : '',
            $baselineWorkspace !== null && $runId === null ? ' --baseline-worktree='.$baselineWorkspace : '',
            $requiresProvider ? ' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call' : '',
        );

        $status = $blockers === [] ? 'ok' : 'blocked';

        return [
            'status' => $status,
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'case_set' => $presetCaseSet,
            'run_id' => $runId,
            'atlas_worktree' => $workspace,
            'baseline_worktree' => $baselineWorkspace,
            'cases' => $cases,
            'cases_count' => count($cases),
            'requires_provider' => $requiresProvider,
            'confirmations_required' => $requiresProvider ? array_keys($confirmsPresent) : [],
            'confirmations_present' => $confirmsPresent,
            'confirmations_missing' => $missingConfirms,
            'cost_estimate' => $costEstimate,
            'planned_run_command' => $runCommand,
            'topology' => $modeDef !== null && $modeDef['allows_topology_declaration']
                ? ['atlas_runtime' => 'atlas_forge', 'rival_runtime' => 'rival_baseline', 'atlas_decide_allowed' => $modeDef['allows_atlas_decide']]
                : null,
            'arena_contracts' => $usingArenaContracts ? $arenaContracts : null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'blockers' => $blockers,
            'next_command' => $status === 'ok' ? $runCommand : 'fix blockers and re-run plan-real',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function costEstimate(string $mode, string $atlasModel, string $rivalModel, string $preset, int $caseCount): array
    {
        $paidCalls = in_array($mode, ['fair', 'full_power', 'provider_arena', 'provider_pure'], true);

        return [
            'paid_provider_invocation' => $paidCalls,
            'estimated_atlas_calls' => $paidCalls ? $caseCount : 0,
            'estimated_rival_calls' => $paidCalls ? $caseCount : 0,
            'estimated_minutes' => match ($preset) {
                'smoke' => $paidCalls ? '3-10' : '<1',
                'quick' => $paidCalls ? '10-30' : '<1',
                'release' => $paidCalls ? '60-180' : '<3',
                'full' => $paidCalls ? '180-360' : '<5',
                'industrial-50', 'ambiguous-bugs', 'multi-day-refactors', 'incident-response',
                'product-security-migrations', 'statistical-repeat' => $paidCalls ? '180-480' : '<5',
                'industrial-100' => $paidCalls ? '360-960' : '<10',
                'industrial-200' => $paidCalls ? '720-1920' : '<20',
                default => 'unknown',
            },
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'mode' => $mode,
            'note' => $paidCalls
                ? 'Real provider calls will incur token cost; operator must confirm before run-real.'
                : 'No paid provider calls in this mode.',
        ];
    }

    private function presetCaseSet(string $preset): ?string
    {
        $preset = strtolower(trim($preset));
        if ($preset === AtlasForgeRivalsCasesRegistry::PRESET_RELEASE) {
            return AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE;
        }
        if (in_array($preset, AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SETS, true)) {
            return $preset;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:?string,1:?string,2:?string}
     */
    private function resolveRunContext(array $input): array
    {
        $workspace = $this->stringOrNull($input['atlas_worktree'] ?? $input['workspace'] ?? null);
        $baselineWorkspace = $this->stringOrNull($input['baseline_worktree'] ?? $input['baseline_workspace'] ?? null);
        $runId = $this->stringOrNull($input['run_id'] ?? null);

        if ($runId !== null && ($workspace === null || $baselineWorkspace === null)) {
            $paths = $this->paths->paths($runId);
            $workspace ??= $paths['atlas'];
            $baselineWorkspace ??= $paths['rival'];
        }

        return [$workspace, $baselineWorkspace, $runId];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
