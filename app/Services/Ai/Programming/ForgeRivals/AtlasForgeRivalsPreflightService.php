<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Preflight (v2 orchestration).
 *
 * Composes:
 *   - the v1 forge-native preflight (protocol / forge runtime / canon docs)
 *   - the v2 mode registry (admits {fair, full_power, diagnostic, replay_only, local_fake})
 *   - the v2 model matrix (fair same-model, codex rival-only, auto only in full_power)
 *   - the v2 cases registry (zero-case preset is a fatal harness bug)
 *
 * Returns a single envelope: ok | blocked + a normalised list of blockers
 * and an explicit `topology_declaration` for full_power runs.
 */
final class AtlasForgeRivalsPreflightService
{
    public function __construct(
        private readonly AtlasForgeNativeRivalsPreflightService $protocolPreflight,
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
    public function preflight(array $input): array
    {
        $mode = trim((string) ($input['mode'] ?? AtlasForgeRivalsModeRegistry::MODE_DIAGNOSTIC));
        $atlasModel = $this->normalizeModel((string) ($input['atlas_model'] ?? $input['model'] ?? 'claude_sonnet'));
        $rivalModel = $this->normalizeModel((string) ($input['rival'] ?? $input['baseline_model'] ?? $atlasModel));
        $preset = trim((string) ($input['preset'] ?? 'smoke'));
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $arenaContracts = is_array($input['arena_contracts'] ?? null) ? (array) $input['arena_contracts'] : [];
        $usingArenaContracts = is_array($arenaContracts['arm_a'] ?? null) && is_array($arenaContracts['arm_b'] ?? null);
        [$workspace, $baselineWorkspace] = $this->resolveWorktrees($input);

        $blockers = [];

        // Mode known?
        $modeDef = null;
        try {
            $modeDef = $this->modes->mode($mode);
        } catch (\InvalidArgumentException $e) {
            $blockers[] = 'unknown_mode:'.$mode;
        }

        // Model matrix
        $matrixResult = $usingArenaContracts
            ? $this->arenaMatrixBypass($mode, $atlasModel, $rivalModel)
            : $this->matrix->validate($mode, $atlasModel, $rivalModel);
        if (! $matrixResult['ok']) {
            foreach ($matrixResult['blockers'] as $b) {
                $blockers[] = $b;
            }
        }

        // Cases / preset
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

        // Full_power topology declaration
        $topology = null;
        if ($modeDef !== null && $modeDef['allows_topology_declaration']) {
            $topology = [
                'mode' => $mode,
                'atlas_arm' => [
                    'runtime' => 'atlas_forge',
                    'model' => $atlasModel,
                ],
                'rival_arm' => [
                    'runtime' => 'rival_baseline',
                    'model' => $rivalModel,
                ],
                'atlas_decide_allowed' => $modeDef['allows_atlas_decide'],
                'declared_at' => now()->toJSON(),
            ];
        }

        // Atlas arm must be Forge — non-negotiable
        $forgeOnly = ['atlas_arm_runtime' => 'atlas_forge'];

        // Delegate the protocol/canon checks (read-only, no provider)
        $protocolReport = $this->protocolPreflight->preflight([
            'workspace' => $workspace,
            'baseline_workspace' => $baselineWorkspace,
            'suite_id' => AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'case_id' => $cases[0]['id'] ?? null,
            'case_ids' => array_values(array_filter(array_map(
                static fn (array $case): ?string => is_string($case['id'] ?? null) ? $case['id'] : null,
                $cases,
            ))),
            'preset' => $preset,
            'atlas_model' => $this->legacyModelName($atlasModel),
            'baseline_model' => $this->legacyModelName($rivalModel),
            'intends_provider_battery' => $modeDef !== null && $modeDef['requires_provider'],
            'provider_cost_approved' => (bool) data_get($input, 'confirmations.provider_cost', false),
            'runbook_reviewed' => (bool) data_get($input, 'confirmations.runbook_reviewed', false),
        ]);
        $protocolStatus = (string) ($protocolReport['status'] ?? 'unknown');
        $protocolReady = in_array($protocolStatus, ['ready_for_dry_run', 'ready_for_provider_battery'], true);
        if (! $protocolReady) {
            foreach ((array) ($protocolReport['blocking_reasons'] ?? []) as $r) {
                $blockers[] = 'protocol:'.(string) $r;
            }
        }

        $status = $blockers === [] ? 'ok' : 'blocked';

        return [
            'status' => $status,
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'cases' => $cases,
            'cases_count' => count($cases),
            'topology_declaration' => $topology,
            'protocol_status' => $protocolStatus,
            'forge_only_contract' => $forgeOnly,
            'matrix_result' => $matrixResult,
            'arena_contracts' => $usingArenaContracts ? $arenaContracts : null,
            'mode_definition' => $modeDef,
            'blockers' => $blockers,
            'protocol_report' => $protocolReport,
            'next_command' => $status === 'ok'
                ? sprintf(
                    'php artisan atlas:forge:rivals dry-run --mode=%s --atlas-model=%s --rival=%s --preset=%s%s%s --json',
                    $mode,
                    $atlasModel,
                    $rivalModel,
                    $preset,
                    is_string($workspace) ? ' --atlas-worktree='.$workspace : '',
                    is_string($baselineWorkspace) ? ' --baseline-worktree='.$baselineWorkspace : '',
                )
                : 'fix blockers and re-run preflight',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function arenaMatrixBypass(string $mode, string $atlasModel, string $rivalModel): array
    {
        return [
            'ok' => true,
            'blockers' => [],
            'resolved_atlas' => $atlasModel,
            'resolved_rival' => $rivalModel,
            'mode' => strtolower(trim($mode)),
            'fair_mode_requires_same_model_on_both_arms' => false,
            'auto_only_valid_in_full_power_mode' => false,
            'reason' => 'provider_arena_contracts_resolve_models_before_preflight',
        ];
    }

    private function normalizeModel(string $model): string
    {
        $model = trim($model);

        return match ($model) {
            'sonnet' => 'claude_sonnet',
            'opus' => 'claude_opus',
            default => $model,
        };
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

    private function legacyModelName(string $model): string
    {
        return match ($model) {
            'claude_sonnet' => 'sonnet',
            'claude_opus' => 'opus',
            default => $model,
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:?string,1:?string}
     */
    private function resolveWorktrees(array $input): array
    {
        $workspace = $this->stringOrNull($input['atlas_worktree'] ?? $input['workspace'] ?? null);
        $baselineWorkspace = $this->stringOrNull($input['baseline_worktree'] ?? $input['baseline_workspace'] ?? null);
        $runId = $this->stringOrNull($input['run_id'] ?? null);

        if ($runId !== null && ($workspace === null || $baselineWorkspace === null)) {
            $paths = $this->paths->paths($runId);
            $workspace ??= $paths['atlas'];
            $baselineWorkspace ??= $paths['rival'];
        }

        return [$workspace, $baselineWorkspace];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
