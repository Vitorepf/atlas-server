<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Dry-Run (v2 orchestration).
 *
 * Plans the case without dispatching any provider. Wraps the v1
 * `AtlasForgeNativeRivalsDryRunService` with the v2 mode/preset shape.
 */
final class AtlasForgeRivalsDryRunService
{
    public function __construct(
        private readonly AtlasForgeNativeRivalsDryRunService $protocolDryRun,
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
        $preset = trim((string) ($input['preset'] ?? 'smoke'));
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $atlasModel = $this->normalizeModel((string) ($input['atlas_model'] ?? $input['model'] ?? 'claude_sonnet'));
        $rivalModel = $this->normalizeModel((string) ($input['rival'] ?? $input['baseline_model'] ?? $atlasModel));
        [$workspace, $baselineWorkspace] = $this->resolveWorktrees($input);
        $blockers = [];
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

        $caseId = $cases[0]['id'] ?? null;

        $report = $this->protocolDryRun->dryRun([
            'case_id' => $caseId,
            'suite_id' => AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'workspace' => $workspace,
            'baseline_workspace' => $baselineWorkspace,
            'preset' => $preset,
            'atlas_model' => $this->legacyModelName($atlasModel),
            'baseline_model' => $this->legacyModelName($rivalModel),
        ]);

        $protocolStatus = (string) ($report['status'] ?? 'unknown');
        $passed = $protocolStatus === 'dry_run_passed';
        if (! $passed) {
            foreach ((array) ($report['blocking_reasons'] ?? []) as $r) {
                $blockers[] = 'protocol:'.(string) $r;
            }
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'mode' => trim((string) ($input['mode'] ?? 'diagnostic')),
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'case_set' => $presetCaseSet,
            'cases' => $cases,
            'cases_count' => count($cases),
            'planned_case_id' => $caseId,
            'protocol_status' => $protocolStatus,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'blockers' => $blockers,
            'dry_run_report' => $report,
            'next_command' => $blockers === []
                ? 'php artisan atlas:forge:rivals plan-real --mode='.($input['mode'] ?? 'fair').' --preset='.$preset
                    .($presetCaseSet !== null ? ' --case-set='.$presetCaseSet : '')
                    .($this->stringOrNull($input['run_id'] ?? null) !== null ? ' --run-id='.$this->stringOrNull($input['run_id']) : '')
                    .' --json'
                : 'fix blockers and re-run dry-run',
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
