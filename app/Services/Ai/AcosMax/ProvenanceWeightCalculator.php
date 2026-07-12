<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

final class ProvenanceWeightCalculator
{
    public const SCHEMA_VERSION = 'atlas.memory.provenance_weight.v1';

    public const FLOOR = 0.5;

    /**
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $verifiedRefs
     * @return array<string,mixed>
     */
    public static function calculate(array $evidenceRefs, array $verifiedRefs): array
    {
        $verified = array_fill_keys($verifiedRefs, true);
        $resolved = [];
        $dead = [];
        foreach (array_values(array_unique($evidenceRefs)) as $ref) {
            if (isset($verified[$ref])) {
                $resolved[] = $ref;
            } else {
                $dead[] = $ref;
            }
        }

        $multiplier = min(1.0, self::FLOOR + (count($resolved) * 0.1));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'resolved_count' => count($resolved),
            'dead_refs' => $dead,
            'multiplier' => round($multiplier, 2),
            'source' => [
                'dead_ref_counts_as_weight' => false,
                'floor' => self::FLOOR,
                'hot_path_ledger_lookup' => false,
            ],
        ];
    }
}
