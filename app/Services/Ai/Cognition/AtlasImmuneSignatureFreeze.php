<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * MAXI-05 freeze payload (ELEV-03 — stamped before soak acceptance).
 */
final class AtlasImmuneSignatureFreeze
{
    public const MEASURE_ID = ImmuneSignatureStore::MEASURE_ID;

    public const TTL_DAYS = 90;

    public const KIND_MEASURE_FREEZE = 'measure_freeze';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FAMILY_SCHEMA_VERSION = 'family_schema_version';

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            'schema_version' => ImmuneSignatureStore::SCHEMA_VERSION,
            self::FIELD_FAMILY_SCHEMA_VERSION => ImmuneSignatureDeriver::SCHEMA_VERSION,
            'author' => 'cursor-acos-max-maxi-05',
            'judge' => 'codex-immune-signature-judge',
            'mode_config_key' => ImmuneSignatureStore::MODE_CONFIG_KEY,
            'default_mode' => ImmuneSignatureStore::DEFAULT_MODE,
            'decay_days' => 90,
            'acceptance' => [
                'cells_with_hit_count_gte_2' => 3,
                'privacy' => 'no_raw_poison_text_in_store',
            ],
            'dependencies' => [
                'MAXI-03' => 'immune_verdict_ledger',
                'MAXI-04' => 'hybrid_classifier_consult',
                'ASI-11' => 'memory_revert_ingest',
            ],
            'ttl_days' => self::TTL_DAYS,
        ];
    }
}
