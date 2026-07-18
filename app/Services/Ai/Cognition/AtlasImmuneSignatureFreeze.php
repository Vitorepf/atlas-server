<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * MAXI-05 freeze payload (ELEV-03 — stamped before soak acceptance).
 */
final class AtlasImmuneSignatureFreeze
{
    public const FIELD_ACCEPTANCE = 'acceptance';
    public const FIELD_CELLS_WITH_HIT_COUNT_GTE_2 = 'cells_with_hit_count_gte_2';
    public const MEASURE_ID = ImmuneSignatureStore::MEASURE_ID;

    public const TTL_DAYS = 90;

    public const KIND_MEASURE_FREEZE = 'measure_freeze';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FAMILY_SCHEMA_VERSION = 'family_schema_version';
    public const FIELD_AUTHOR = 'author';
    public const FIELD_JUDGE = 'judge';
    public const FIELD_DECAY_DAYS = 'decay_days';
    public const FIELD_DEFAULT_MODE = 'default_mode';
    public const FIELD_DEPENDENCIES = 'dependencies';
    public const FIELD_KIND = 'kind';
    public const FIELD_MODE_CONFIG_KEY = 'mode_config_key';
    public const FIELD_PRIVACY = 'privacy';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_HYBRID_CLASSIFIER_CONSULT = 'hybrid_classifier_consult';
    public const FIELD_IMMUNE_VERDICT_LEDGER = 'immune_verdict_ledger';
    public const FIELD_MEMORY_REVERT_INGEST = 'memory_revert_ingest';
    public const FIELD_NO_RAW_POISON_TEXT_IN_STORE = 'no_raw_poison_text_in_store';
    public const FIELD_ASI_11 = 'ASI-11';
    public const FIELD_MAXI_03 = 'MAXI-03';
    public const FIELD_MAXI_04 = 'MAXI-04';
    public const FIELD_CODEX_IMMUNE_SIGNATURE_JUDGE = 'codex-immune-signature-judge';
    public const FIELD_CURSOR_ACOS_MAX_MAXI_05 = 'cursor-acos-max-maxi-05';
    public const INT_3 = 3;
    public const INT_90 = 90;

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_SCHEMA_VERSION => ImmuneSignatureStore::SCHEMA_VERSION,
            self::FIELD_FAMILY_SCHEMA_VERSION => ImmuneSignatureDeriver::SCHEMA_VERSION,
            self::FIELD_AUTHOR => self::FIELD_CURSOR_ACOS_MAX_MAXI_05,
            self::FIELD_JUDGE => self::FIELD_CODEX_IMMUNE_SIGNATURE_JUDGE,
            self::FIELD_MODE_CONFIG_KEY => ImmuneSignatureStore::MODE_CONFIG_KEY,
            self::FIELD_DEFAULT_MODE => ImmuneSignatureStore::DEFAULT_MODE,
            self::FIELD_DECAY_DAYS => self::INT_90,
            self::FIELD_ACCEPTANCE => [
                self::FIELD_CELLS_WITH_HIT_COUNT_GTE_2 => self::INT_3,
                self::FIELD_PRIVACY => self::FIELD_NO_RAW_POISON_TEXT_IN_STORE,
            ],
            self::FIELD_DEPENDENCIES => [
                self::FIELD_MAXI_03 => self::FIELD_IMMUNE_VERDICT_LEDGER,
                self::FIELD_MAXI_04 => self::FIELD_HYBRID_CLASSIFIER_CONSULT,
                self::FIELD_ASI_11 => self::FIELD_MEMORY_REVERT_INGEST,
            ],
            self::FIELD_TTL_DAYS => self::TTL_DAYS,
        ];
    }
}
