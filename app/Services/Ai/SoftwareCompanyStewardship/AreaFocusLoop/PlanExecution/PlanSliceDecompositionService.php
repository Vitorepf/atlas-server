<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicSemanticDecomposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicStep;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Pilar 1 · Plan Execution · large-slice atomic decomposition (FASE 1 wiring).
 *
 * Sits between PlanSliceSelectionService and the PlanSliceCycleExecutor: when the
 * next ready slice would, scored as a single unit, trip {@see RiskLevelScorer}
 * above R3 (>=6 explicit allowed_files OR >=3 real layers), this service asks the
 * {@see AtomicSemanticDecomposer} to break it into the canonical
 * contract -> skeleton -> behavior -> wiring -> test progression and returns the
 * FIRST atomic step as a self-contained <=R3 slice to execute THIS cycle.
 *
 * Additive, never destructive:
 *  - The original (parent) slice is preserved verbatim under `parent_slice` and
 *    its slice_id is unchanged, so the completion tracker still joins on it and
 *    later cycles continue decomposing the remaining work.
 *  - A slice already <= R3 is returned UNCHANGED (no decomposition, no noise).
 *  - File paths are never fabricated; only the slice's own `allowed_files` (and
 *    the planner-derived test paths it already carries) feed the decomposer.
 *
 * Risk thresholds and layer buckets are mirrored byte-for-byte from
 * {@see RiskLevelScorer} (R4 at >=6 files OR >=3 real layers) so a slice this
 * service passes through unchanged is genuinely <= R3, and every emitted step is
 * guaranteed <= R3 by {@see AtomicSemanticDecomposer::MAX_FILES_PER_STEP}.
 */
final class PlanSliceDecompositionService
{
    /** R4 trips at this many explicit allowed_files (mirrors RiskLevelScorer). */
    public const R4_FILE_THRESHOLD = 6;

    /** R4 trips at this many distinct real layers (mirrors RiskLevelScorer). */
    public const R4_LAYER_THRESHOLD = 3;

    /**
     * Real layer buckets, mirrored from RiskLevelScorer / AtomicSemanticDecomposer.
     * tests/ and docs/.md are intentionally EXCLUDED — they do not count toward the
     * real-layer limit (they are optional companions on any step).
     *
     * @var array<string, list<string>>
     */
    private const LAYER_BUCKETS = [
        'db' => ['app/models/', 'database/migrations/', '/migrations/'],
        'api' => ['app/http/controllers/', 'routes/', '/controllers/'],
        'ui' => ['atlas-desktop/', 'atlas-app/', 'resources/views/', 'resources/js/'],
        'service' => ['app/services/'],
    ];

    private AtomicSemanticDecomposer $decomposer;

    public function __construct(?AtomicSemanticDecomposer $decomposer = null)
    {
        $this->decomposer = $decomposer ?? new AtomicSemanticDecomposer;
    }

    /**
     * Resolve the slice to execute next.
     *
     * @param  array<string,mixed>  $slice  the selected decomposed_plan.v1 slice
     * @return array<string,mixed> either the slice unchanged (<=R3) or a
     *                             self-contained atomic <=R3 step slice
     */
    public function resolveExecutableSlice(array $slice): array
    {
        $allowedFiles = AreaFocusStringListNormalizer::trimmedUniqueStrings($slice['allowed_files'] ?? null);

        if (! $this->isR4($allowedFiles)) {
            // Already atomic enough — pass through, no decomposition (perf + no noise).
            return $slice;
        }

        $impl = [];
        $tests = [];
        foreach ($allowedFiles as $path) {
            if (str_contains(strtolower($path), 'tests/')) {
                $tests[] = $path;
            } else {
                $impl[] = $path;
            }
        }

        $intent = trim((string) ($slice['objective'] ?? ''));
        if ($intent === '') {
            $intent = trim((string) ($slice['delivery'] ?? (string) ($slice['slice_id'] ?? '')));
        }

        $steps = $this->decomposer->decompose($intent, array_values($impl), array_values($tests));
        if ($steps === []) {
            // Nothing decomposable (e.g. only test files) — pass through honestly.
            return $slice;
        }

        return $this->stepSlice($slice, $steps[0]);
    }

    /**
     * Whether a slice scored as a single unit would be R4 (mirrors RiskLevelScorer).
     *
     * @param  list<string>  $allowedFiles
     */
    public function isR4(array $allowedFiles): bool
    {
        if ($allowedFiles === []) {
            return false;
        }

        return count($allowedFiles) >= self::R4_FILE_THRESHOLD
            || $this->countRealLayers($allowedFiles) >= self::R4_LAYER_THRESHOLD;
    }

    /**
     * @param  list<string>  $paths
     */
    private function countRealLayers(array $paths): int
    {
        $matched = [];
        foreach ($paths as $path) {
            $needle = strtolower($path);
            foreach (self::LAYER_BUCKETS as $bucket => $needles) {
                foreach ($needles as $fragment) {
                    if (str_contains($needle, $fragment)) {
                        $matched[$bucket] = true;
                        break;
                    }
                }
            }
        }

        return count($matched);
    }

    /**
     * Build the atomic <=R3 slice the executor runs this cycle. The parent slice's
     * slice_id and finding_id are preserved so the completion tracker join is
     * unambiguous; the atomic step's scope/intent/acceptance REPLACE the parent's
     * for this cycle so the owner-flow gate scores it as <=R3.
     *
     * @param  array<string,mixed>  $parent
     * @return array<string,mixed>
     */
    private function stepSlice(array $parent, AtomicStep $step): array
    {
        $sliceId = (string) ($parent['slice_id'] ?? '');

        $atomic = $parent;
        $atomic['objective'] = $step->intent;
        $atomic['delivery'] = $step->intent;
        $atomic['acceptance_criteria'] = $step->acceptance;
        $atomic['allowed_files'] = array_values($step->allowedFiles);
        $atomic['atomic_step'] = $step->toArray();
        $atomic['atomic_step_of'] = $sliceId;
        $atomic['parent_slice'] = $parent;

        // Keep the nested finding's affected_files aligned with the atomic scope so
        // the executor derives the SAME <=R3 file set the gate will score.
        if (is_array($atomic['finding'] ?? null)) {
            $finding = $atomic['finding'];
            $finding['affected_files'] = array_values($step->allowedFiles);
            $atomic['finding'] = $finding;
        }

        return $atomic;
    }
}
