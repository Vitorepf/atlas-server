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
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionSimplificationCapabilityParityMatrix
{
    public const SCHEMA = 'atlas.self_construction.simplification_capability_parity_matrix.v1';

    public const STATUS_FULL_PARITY = 'full_parity';

    public const STATUS_PARTIAL_PARITY = 'partial_parity';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NO_REQUIREMENT = 'no_requirement';

    /** @var list<string> */
    private const DIMENSIONS = [
        'behavior_claims',
        'consumer_contracts',
        'failure_modes',
        'input_contract',
        'output_fields',
        'proof_refs',
    ];

    /**
     * @param  array{
     *   old_organ?: array<string, list<string>>,
     *   new_organ?: array<string, list<string>>,
     *   old_helper_count?: int,
     *   new_helper_count?: int,
     * }  $organs
     * @return array{schema:string, replacement_allowed:bool, parity:bool, rows:list<array<string,mixed>>, missing_capabilities:list<string>, simplification_gain:int}
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
        $simplificationGain = $replacementAllowed ? max(0, $oldHelperCount - $newHelperCount) : 0;

        return [
            'schema' => self::SCHEMA,
            'replacement_allowed' => $replacementAllowed,
            'parity' => $replacementAllowed,
            'rows' => $rows,
            'missing_capabilities' => $missingCapabilities,
            'simplification_gain' => $simplificationGain,
        ];
    }
}
