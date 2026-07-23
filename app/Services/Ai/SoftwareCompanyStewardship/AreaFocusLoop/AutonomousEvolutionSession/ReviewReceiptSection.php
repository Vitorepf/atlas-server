<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusProviderNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;

/**
 * AP-786 provider-fallback + retained-receipt review section, extracted VERBATIM
 * from AutonomousEvolutionSessionService by the GOD-DEBULK split. Decides whether
 * a stale provider lock may retry an operator-authored plan slice (narrow, review-
 * artifact-gated exception) and whether a senior-loop cycle is missing its retained
 * Atlas-Dev receipts. Pure read of the durable session record + retained-receipt
 * disk; no provider, no merge. The review-lock HEAD (reviewLockedFindingKeys) and
 * the AP-806 honest-stop stay on the parent, which reaches these helpers through
 * two thin delegators; back-calls to shared parent predicates go through
 * {@see AutonomousEvolutionSessionService}. Taxonomy classes are referenced qualified.
 */
final class ReviewReceiptSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * A stale provider lock protects the loop from repeating the same bad worker
     * attempt. It must not permanently starve an operator-authored atomic plan
     * slice after the branch/worktree was cleaned up and the operator routes the
     * slice to a different provider. The exception is intentionally narrow: plan
     * slices only, known provider-quality/runtime blockers, no live review
     * artifact, and never for the same provider that already failed.
     *
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $blockers
     */
    public function providerFallbackMayRetryPlanSlice(array $cycle, array $blockers, string $repoRoot, string $provider): bool
    {
        $requestedProvider = AreaFocusProviderNormalizer::providerId($provider, includeMinimaxM3: true);
        if ($requestedProvider === '') {
            return false;
        }
        $hasDiffQualityBlocker = $this->parent->hasProviderDiffQualityBlocker($blockers);
        if (! $hasDiffQualityBlocker && ! $this->hasProviderFallbackRuntimeRetryBlocker($blockers)) {
            return false;
        }
        if (! $this->parent->cycleLooksOperatorPlanSlice($cycle)) {
            return false;
        }
        if ($this->parent->cycleHasLiveReviewArtifact($repoRoot, $cycle)) {
            return false;
        }

        $previousProvider = $this->parent->cycleProviderId($cycle);
        if ($previousProvider === '') {
            return $this->providerFallbackCanRetryUnknownLegacyProvider($requestedProvider);
        }

        return $previousProvider !== $requestedProvider;
    }

    private function providerFallbackCanRetryUnknownLegacyProvider(string $requestedProvider): bool
    {
        return in_array($requestedProvider, [
            'claude_cli',
            'codex_cli',
            'gemini_cli',
            'minimax_m3_cli',
        ], true);
    }

    /** @param list<string> $blockers */
    private function hasProviderFallbackRuntimeRetryBlocker(array $blockers): bool
    {
        foreach ([
            'owner_runtime_repeated_repair_no_progress',
            'owner_runtime_minimax_codex_review_not_passed',
        ] as $blocker) {
            if (in_array($blocker, $blockers, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A pre-retention senior-loop failure can leave only an AP-759 owner run in
     * the ledger while the Atlas Dev receipt directory was deleted with the
     * sandbox. That historical state must not permanently starve an operator
     * plan slice after receipt retention has been fixed. The unlock is narrow:
     * plan slices only, no live review artifact, and only when the referenced
     * Atlas Dev run cannot be audited in the retained receipt root. A fresh
     * retry that fails again will persist receipts and become review-locked.
     *
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $blockers
     */
    public function seniorLoopMissingRetainedReceiptsMayRetryPlanSlice(
        string $areaId,
        array $cycle,
        array $blockers,
        string $repoRoot,
    ): bool {
        if (! in_array('owner_runtime_senior_loop_execution_not_passed', $blockers, true)) {
            return false;
        }
        if (! $this->parent->cycleLooksOperatorPlanSlice($cycle)) {
            return false;
        }
        if ($this->parent->cycleHasLiveReviewArtifact($repoRoot, $cycle)) {
            return false;
        }

        $ownerRunIds = $this->cycleOwnerSandboxRunIds($cycle);
        if ($ownerRunIds === []) {
            return false;
        }

        $atlasDevRunIds = [];
        foreach ($ownerRunIds as $ownerRunId) {
            foreach ($this->ownerSandboxAtlasDevRunIds($areaId, $repoRoot, $ownerRunId) as $atlasDevRunId) {
                $atlasDevRunIds[$atlasDevRunId] = true;
            }
        }

        if ($atlasDevRunIds === []) {
            return ! is_dir($this->retainedAtlasDevReceiptsRoot($repoRoot));
        }

        foreach (array_keys($atlasDevRunIds) as $atlasDevRunId) {
            if (! $this->retainedAtlasDevReceiptRunExists($repoRoot, $atlasDevRunId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    private function cycleOwnerSandboxRunIds(array $cycle): array
    {
        $ids = [];
        foreach ([
            data_get($cycle, 'loop_receipt.evidence_refs.owner_sandbox_run_id', ''),
            data_get($cycle, 'evidence_refs.owner_sandbox_run_id', ''),
            data_get($cycle, 'owner_flow.owner_sandbox_run_id', ''),
        ] as $value) {
            if (is_string($value) && preg_match('/^afrun_[A-Za-z0-9]+$/', $value) === 1) {
                $ids[$value] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function ownerSandboxAtlasDevRunIds(string $areaId, string $repoRoot, string $ownerRunId): array
    {
        $path = rtrim($repoRoot, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'atlas'
            .DIRECTORY_SEPARATOR.'software_company_stewardship'
            .DIRECTORY_SEPARATOR.'owner_sandbox_runtime_runs'
            .DIRECTORY_SEPARATOR.$areaId.'.jsonl';
        if (! is_file($path)) {
            return [];
        }

        $ids = [];
        foreach ($this->parent->sessionRecordLines($path) as $line) {
            if (! str_contains($line, $ownerRunId)) {
                continue;
            }
            $record = json_decode($line, true);
            if (! is_array($record) || (string) ($record['owner_sandbox_run_id'] ?? '') !== $ownerRunId) {
                continue;
            }
            foreach ([
                data_get($record, 'command_result.stdout_excerpt', ''),
                data_get($record, 'owner_result.runtime_invocation.command_result.stdout_excerpt', ''),
                data_get($record, 'owner_result.evidence_pack.stdout_excerpt', ''),
            ] as $text) {
                foreach ($this->atlasDevRunIdsInText((string) $text) as $runId) {
                    $ids[$runId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function atlasDevRunIdsInText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\b(dev-[0-9]{10,}-[A-Za-z0-9._-]+)\b/', $text, $matches);

        return AreaFocusStringListNormalizer::uniqueStringValues($matches[1] ?? []);
    }

    private function retainedAtlasDevReceiptRunExists(string $repoRoot, string $runId): bool
    {
        $dir = $this->retainedAtlasDevReceiptsRoot($repoRoot).DIRECTORY_SEPARATOR.$runId;
        if (! is_dir($dir)) {
            return false;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && str_ends_with($entry, '.json') && is_file($dir.DIRECTORY_SEPARATOR.$entry)) {
                return true;
            }
        }

        return false;
    }

    private function retainedAtlasDevReceiptsRoot(string $repoRoot): string
    {
        return rtrim($repoRoot, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'atlas'
            .DIRECTORY_SEPARATOR.'software_company_stewardship'
            .DIRECTORY_SEPARATOR.'owner_sandbox_runtime_runs'
            .DIRECTORY_SEPARATOR.'atlas_dev_receipts';
    }
}
