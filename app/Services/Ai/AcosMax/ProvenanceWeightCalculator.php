<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ProvenanceWeightCalculator
{
    public const SCHEMA_VERSION = 'atlas.memory.provenance_weight.v1';

    public const FLOOR = 0.5;
    public const FIELD_DEAD_REF_COUNTS_AS_WEIGHT = 'dead_ref_counts_as_weight';
    public const FIELD_DEAD_REFS = 'dead_refs';
    public const FIELD_RESOLVED_COUNT = 'resolved_count';
    public const FIELD_MULTIPLIER = 'multiplier';

    /**
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $verifiedRefs
     * @return array<string,mixed>
     */
    public static function calculate(array $evidenceRefs, array $verifiedRefs): array
    {
        $verified = [];
        foreach ($verifiedRefs as $ref) {
            if (($trimmed = AiValueNormalizer::trimmedStringOrNull($ref)) !== null) {
                $verified[$trimmed] = true;
            }
        }
        $resolved = [];
        $dead = [];
        $seen = [];
        foreach ($evidenceRefs as $ref) {
            $trimmed = AiValueNormalizer::trimmedStringOrNull($ref);
            if ($trimmed === null || isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            if (isset($verified[$trimmed])) {
                $resolved[] = $trimmed;
            } else {
                $dead[] = $trimmed;
            }
        }

        $multiplier = min(1.0, self::FLOOR + (count($resolved) * 0.1));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            self::FIELD_RESOLVED_COUNT => count($resolved),
            self::FIELD_DEAD_REFS => $dead,
            self::FIELD_MULTIPLIER => round($multiplier, 2),
            'source' => [
                self::FIELD_DEAD_REF_COUNTS_AS_WEIGHT => false,
                'floor' => self::FLOOR,
                'hot_path_ledger_lookup' => false,
            ],
        ];
    }
}
