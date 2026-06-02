<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class HandoffArtifactGapDetector
{
    /**
     * Required artifact types that have no matching present type, returned in
     * required-set order and de-duplicated on the required side.
     *
     * Rules:
     *  1. Present entries are normalised: non-string and empty (after trim)
     *     entries are ignored.
     *  2. A required type is satisfied when a present type matches it exactly
     *     after trimming both sides.
     *  3. Only unsatisfied required types are reported, in their original
     *     required order.
     *  4. Duplicate required types collapse to a single entry.
     *  5. The result is reindexed via array_values.
     *
     * @param  list<mixed>  $present   present artifact type strings
     * @param  list<mixed>  $required  ordered required artifact type strings
     * @return list<string>
     */
    public function gaps(array $present, array $required): array
    {
        $presentTypes = [];
        foreach ($present as $type) {
            if (! is_string($type)) {
                continue;
            }

            $trimmed = trim($type);
            if ($trimmed === '') {
                continue;
            }

            $presentTypes[$trimmed] = true;
        }

        $gaps = [];
        $seen = [];
        foreach ($required as $type) {
            if (! is_string($type)) {
                continue;
            }

            $trimmed = trim($type);
            if ($trimmed === '') {
                continue;
            }

            if (isset($presentTypes[$trimmed]) || isset($seen[$trimmed])) {
                continue;
            }

            $seen[$trimmed] = true;
            $gaps[] = $trimmed;
        }

        return array_values($gaps);
    }
}
