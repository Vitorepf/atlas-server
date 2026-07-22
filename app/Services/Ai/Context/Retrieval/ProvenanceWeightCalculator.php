<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Retrieval;

use App\Services\Ai\Support\AiValueNormalizer;

final class ProvenanceWeightCalculator
{
    public const SCHEMA_VERSION = 'atlas.memory.provenance_weight.v1';

    public const FLOOR = 0.5;
    public const FIELD_DEAD_REF_COUNTS_AS_WEIGHT = 'dead_ref_counts_as_weight';
    public const FIELD_DEAD_REFS = 'dead_refs';
    public const FIELD_RESOLVED_COUNT = 'resolved_count';
    public const FIELD_MULTIPLIER = 'multiplier';
    public const FIELD_SOURCE = 'source';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_FLOOR = 'floor';
    public const FIELD_HOT_PATH_LEDGER_LOOKUP = 'hot_path_ledger_lookup';

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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_RESOLVED_COUNT => count($resolved),
            self::FIELD_DEAD_REFS => $dead,
            self::FIELD_MULTIPLIER => round($multiplier, 2),
            self::FIELD_SOURCE => [
                self::FIELD_DEAD_REF_COUNTS_AS_WEIGHT => false,
                self::FIELD_FLOOR => self::FLOOR,
                self::FIELD_HOT_PATH_LEDGER_LOOKUP => false,
            ],
        ];
    }
}
