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

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'schema_version' => ImmuneSignatureStore::SCHEMA_VERSION,
            'family_schema_version' => ImmuneSignatureDeriver::SCHEMA_VERSION,
            'author' => 'cursor-acos-max-maxi-05',
            'judge' => 'codex-immune-signature-judge',
            'mode_config_key' => 'atlas.aaeos.immune_signature.mode',
            'default_mode' => 'observe',
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
