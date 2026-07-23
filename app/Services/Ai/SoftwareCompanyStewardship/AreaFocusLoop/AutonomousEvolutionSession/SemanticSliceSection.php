<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;

/**
 * AP-806 semantic slice-progression section, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. Decides whether a
 * finding's slice plan carries ordered SEMANTIC steps (contract -> skeleton ->
 * first_behavior), returns the first PENDING step, tracks already-merged /
 * already-materialized steps, and narrows the finding the owner runtime sees to
 * one bounded step. Pure read + projection: no provider, no merge. Back-calls to
 * the parent's shared session-record primitives (recordPath / sessionRecordLines)
 * go through {@see AutonomousEvolutionSessionService}; taxonomy classes are
 * referenced qualified.
 */
final class SemanticSliceSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * AP-806: the first ordered SEMANTIC step of a decomposed finding (contract
     * → skeleton → behavior). Null when the plan is a plain file-group slice (no
     * semantic decomposition), so the existing path is unchanged.
     *
     * @param  array<string,mixed>  $slicePlan
     * @return array<string,mixed>|null
     */
    /**
     * AP-806 slice-progression: return the first PENDING semantic step — the first
     * slice (in depends_on order) that has not already merged. Completed slice_ids
     * are skipped so successive cycles advance contract -> skeleton -> first_behavior
     * instead of re-doing step 1; a slice that FAILED (not in $completedSliceIds) is
     * retried, never skipped, so ordering is never violated.
     *
     * @param  array<string,mixed>  $slicePlan
     * @param  array<string,true>  $completedSliceIds
     * @return array<string,mixed>|null
     */
    /**
     * Whether the plan decomposed the finding into ordered SEMANTIC steps
     * (contract/skeleton/first_behavior) — as opposed to a plain file_group split
     * that carries no step progression.
     *
     * @param  array<string,mixed>  $slicePlan
     */
    public function planHasSemanticSlices(array $slicePlan): bool
    {
        if ((string) ($slicePlan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
            return false;
        }
        foreach ((array) ($slicePlan['slices'] ?? []) as $slice) {
            if (is_array($slice) && str_starts_with((string) ($slice['decomposition'] ?? ''), 'semantic_step:')) {
                return true;
            }
        }

        return false;
    }

    public function firstSemanticSlice(array $slicePlan, array $completedSliceIds = []): ?array
    {
        if ((string) ($slicePlan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
            return null;
        }
        foreach ((array) ($slicePlan['slices'] ?? []) as $slice) {
            if (is_array($slice)
                && str_starts_with((string) ($slice['decomposition'] ?? ''), 'semantic_step:')
                && ! isset($completedSliceIds[(string) ($slice['slice_id'] ?? '')])
                && ! $this->semanticContractSliceAlreadyMaterialized($slice)) {
                return $slice;
            }
        }

        return null;
    }

    /**
     * A contract slice may have been materialized by a previous guarded repair or
     * manual salvage before the loop wrote a completed slice receipt. In that case
     * re-running the same contract burns provider on already-present PSR-4 files;
     * let the loop advance to the next semantic step and let validation catch any
     * incomplete contract at the dependent slice.
     *
     * @param  array<string,mixed>  $slice
     */
    private function semanticContractSliceAlreadyMaterialized(array $slice): bool
    {
        if ((string) ($slice['decomposition'] ?? '') !== 'semantic_step:contract') {
            return false;
        }

        $files = AreaFocusStringListNormalizer::preserveNonBlankStrings($slice['allowed_files'] ?? []);
        $sourceFiles = array_values(array_filter($files, static fn (string $file): bool => str_starts_with($file, 'app/') && str_ends_with($file, '.php')));
        $testFiles = array_values(array_filter($files, static fn (string $file): bool => str_starts_with($file, 'tests/') && str_ends_with($file, '.php')));
        if ($sourceFiles === [] || $testFiles === []) {
            return false;
        }

        foreach (array_merge($sourceFiles, $testFiles) as $file) {
            $absolute = function_exists('base_path') ? base_path($file) : $file;
            if (! is_file($absolute)) {
                return false;
            }
        }

        return true;
    }

    /**
     * AP-806 slice-progression: slice_ids the loop already MERGED (cycle_completed),
     * read from the durable session record so the next cycle on the same parent
     * finding advances to the next pending slice. Only merged slices count (a failed
     * slice stays pending and is retried). Mirrors reviewLockedFindingKeys' scan.
     *
     * @return array<string,true>
     */
    public function completedSemanticSliceIds(string $areaId): array
    {
        $path = $this->parent->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }
        $completed = [];
        foreach ($this->parent->sessionRecordLines($path) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }
            foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                if (! is_array($cycle)) {
                    continue;
                }
                if ((string) ($cycle['final_status'] ?? '') !== 'cycle_completed') {
                    continue;
                }
                $sliceId = (string) data_get($cycle, 'selected_finding.active_slice_id', '');
                if ($sliceId !== '') {
                    $completed[$sliceId] = true;
                }
            }
        }

        return $completed;
    }

    /**
     * Rewrite the finding the owner runtime sees so the provider implements ONLY
     * this bounded step (not the whole roadmap item). Identity (finding_id/hash)
     * is preserved; objective/scope are narrowed to the slice.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    public function applySemanticSliceToFinding(array $finding, array $slice): array
    {
        $objective = trim((string) ($slice['objective'] ?? ''));
        if ($objective === '') {
            return $finding;
        }
        $kind = str_replace('semantic_step:', '', (string) ($slice['decomposition'] ?? ''));
        $finding['title'] = sprintf('Bounded step %s (%s) — execute ONLY this step', (string) ($slice['sequence'] ?? 1), $kind ?: 'step');
        $finding['detail'] = $objective;
        $finding['why_it_matters'] = $objective;
        $finding['proposed_next_action'] = '';
        $finding['affected_files'] = AreaFocusStringListNormalizer::preserveNonBlankStrings($slice['allowed_files'] ?? ($finding['affected_files'] ?? []));
        $finding['active_slice_id'] = (string) ($slice['slice_id'] ?? '');
        $finding['active_slice_kind'] = $kind;

        return $finding;
    }
}
