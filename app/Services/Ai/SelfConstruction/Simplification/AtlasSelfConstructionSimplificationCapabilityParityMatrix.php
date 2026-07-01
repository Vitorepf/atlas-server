<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure matrix: proves (or refuses) that a proposed circuit collapse preserves every
 * capability of the organ(s) it replaces across five dimensions — behavior claims,
 * input contracts, output fields, failure modes, and proof references.
 *
 * replacement_allowed is true only when every dimension of the old organ is fully
 * covered by the new organ. Any missing item on any dimension refuses the
 * replacement — a circuit collapse must never silently drop a capability.
 *
 * deletion_roi ranks how much a FULL-PARITY replacement is actually worth: the sum of helper
 * reduction, line reduction, duplicate-cluster reduction, and dependency reduction. It is ALWAYS
 * zero when replacement_allowed is false — ROI is never computed for a replacement that drops a
 * capability, since that replacement can never happen regardless of how much code it would save.
 *
 * simplification_recommendation is deterministic:
 *   hold_for_missing_parity — replacement_allowed=false (a capability would be dropped).
 *   low_roi_hold            — full parity, but deletion_roi is below the ROI floor: not worth it yet.
 *   replace                 — full parity AND deletion_roi at or above the ROI floor.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionSimplificationCapabilityParityMatrix
{
    public const SCHEMA = 'atlas.self_construction.simplification_capability_parity_matrix.v1';

    public const STATUS_FULL_PARITY = 'full_parity';

    public const STATUS_PARTIAL_PARITY = 'partial_parity';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NO_REQUIREMENT = 'no_requirement';

    public const RECOMMEND_REPLACE = 'replace';

    public const RECOMMEND_HOLD_FOR_MISSING_PARITY = 'hold_for_missing_parity';

    public const RECOMMEND_LOW_ROI_HOLD = 'low_roi_hold';

    /** Minimum deletion_roi (helper+line+duplicate+dependency reduction) worth acting on now. */
    private const ROI_FLOOR = 5;

    /** @var list<string> */
    private const DIMENSIONS = [
        'behavior_claims',
        'failure_modes',
        'input_contract',
        'output_fields',
        'proof_refs',
        'public_command_contracts',
        'runtime_contracts',
    ];

    /**
     * @param  array{
     *   old_organ?: array<string, list<string>>,
     *   new_organ?: array<string, list<string>>,
     *   old_helper_count?: int,
     *   new_helper_count?: int,
     *   line_reduction?: int,
     *   duplicate_cluster_reduction?: int,
     *   dependency_reduction?: int,
     * }  $organs
     * @return array{schema:string, replacement_allowed:bool, parity:bool, rows:list<array<string,mixed>>, missing_capabilities:list<string>, simplification_gain:int, deletion_roi:int, simplification_recommendation:string}
     */
    public function compare(array $organs): array
    {
        $oldOrgan = (array) ($organs['old_organ'] ?? []);
        $newOrgan = (array) ($organs['new_organ'] ?? []);

        $rows = [];
        $replacementAllowed = true;
        $missingCapabilities = [];

        // AC4: capability rows are sorted deterministically by capability id.
        $dimensions = self::DIMENSIONS;
        sort($dimensions, SORT_STRING);

        foreach ($dimensions as $dimension) {
            $required = array_values(array_unique((array) ($oldOrgan[$dimension] ?? [])));
            $present = array_values(array_unique((array) ($newOrgan[$dimension] ?? [])));
            $missing = array_values(array_diff($required, $present));
            sort($missing);

            if ($required === []) {
                $status = self::STATUS_NO_REQUIREMENT;
            } elseif ($missing === []) {
                $status = self::STATUS_FULL_PARITY;
            } elseif (count($missing) < count($required)) {
                $status = self::STATUS_PARTIAL_PARITY;
            } else {
                $status = self::STATUS_MISSING;
            }

            if ($missing !== []) {
                $replacementAllowed = false;
                $missingCapabilities[] = $dimension;
            }

            $rows[] = [
                'capability' => $dimension,
                'status' => $status,
                'missing_fields' => $missing,
                'missing_capability' => $missing !== [],
                'recommended_fix' => $missing === []
                    ? null
                    : sprintf('add missing %s to the new organ: %s', $dimension, implode(', ', $missing)),
            ];
        }

        $oldHelperCount = max(0, (int) ($organs['old_helper_count'] ?? 0));
        $newHelperCount = max(0, (int) ($organs['new_helper_count'] ?? 0));
        $helperReduction = max(0, $oldHelperCount - $newHelperCount);
        $simplificationGain = $replacementAllowed ? $helperReduction : 0;

        $lineReduction = max(0, (int) ($organs['line_reduction'] ?? 0));
        $duplicateClusterReduction = max(0, (int) ($organs['duplicate_cluster_reduction'] ?? 0));
        $dependencyReduction = max(0, (int) ($organs['dependency_reduction'] ?? 0));
        $deletionRoi = $replacementAllowed
            ? $helperReduction + $lineReduction + $duplicateClusterReduction + $dependencyReduction
            : 0;

        $simplificationRecommendation = match (true) {
            ! $replacementAllowed => self::RECOMMEND_HOLD_FOR_MISSING_PARITY,
            $deletionRoi < self::ROI_FLOOR => self::RECOMMEND_LOW_ROI_HOLD,
            default => self::RECOMMEND_REPLACE,
        };

        return [
            'schema' => self::SCHEMA,
            'replacement_allowed' => $replacementAllowed,
            'parity' => $replacementAllowed,
            'rows' => $rows,
            'missing_capabilities' => $missingCapabilities,
            'simplification_gain' => $simplificationGain,
            'deletion_roi' => $deletionRoi,
            'simplification_recommendation' => $simplificationRecommendation,
        ];
    }
}
