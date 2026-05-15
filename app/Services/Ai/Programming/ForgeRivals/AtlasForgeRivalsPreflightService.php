<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;

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
        $workspace = $input['atlas_worktree'] ?? $input['workspace'] ?? null;
        $baselineWorkspace = $input['baseline_worktree'] ?? $input['baseline_workspace'] ?? null;

        $blockers = [];

        // Mode known?
        $modeDef = null;
        try {
            $modeDef = $this->modes->mode($mode);
        } catch (\InvalidArgumentException $e) {
            $blockers[] = 'unknown_mode:'.$mode;
        }

        // Model matrix
        $matrixResult = $this->matrix->validate($mode, $atlasModel, $rivalModel);
        if (! $matrixResult['ok']) {
            foreach ($matrixResult['blockers'] as $b) {
                $blockers[] = $b;
            }
        }

        // Cases / preset
        $cases = [];
        try {
            $cases = $this->cases->casesForPreset($preset);
        } catch (EmptyPresetIsFatalHarnessBug $e) {
            $blockers[] = 'zero_case_preset_fatal_harness_bug:'.$preset;
        } catch (\Throwable $e) {
            $blockers[] = 'preset_unknown:'.$preset;
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

    private function normalizeModel(string $model): string
    {
        $model = trim($model);

        return match ($model) {
            'sonnet' => 'claude_sonnet',
            'opus' => 'claude_opus',
            default => $model,
        };
    }

    private function legacyModelName(string $model): string
    {
        return match ($model) {
            'claude_sonnet' => 'sonnet',
            'claude_opus' => 'opus',
            default => $model,
        };
    }
}
