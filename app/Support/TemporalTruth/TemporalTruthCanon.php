<?php

namespace App\Support\TemporalTruth;

/**
 * Canonical enum + helpers for TEOS-I1 Temporal Truth Fields (M2).
 *
 * Three target tables (`atlas_decision_receipts`, `atlas_memory_entries`,
 * `ai_codebase_world_model_edges`) gain 8 nullable temporal fields:
 *
 *   - `valid_from`       (timestamp) — when this truth claim starts being valid.
 *   - `valid_until`      (timestamp) — last instant it is still valid.
 *   - `observed_at`      (timestamp) — when the underlying observation occurred.
 *   - `verified_at`      (timestamp) — when an operator/process re-verified it.
 *   - `stale_after`      (timestamp) — soft TTL; consumers must consult the
 *                                       freshness gate after this point.
 *   - `source_hash`      (string 64) — sha256 of the originating source payload.
 *   - `superseded_by`    (uuid)     — id of the record that replaces this one.
 *   - `authority_level`  (string)   — canonical level enum (below).
 *
 * Fields are NULLABLE; legacy rows remain visible to every scope. This canon
 * has no DB rows of its own — it documents shape and authority enum.
 */
final class TemporalTruthCanon
{
    public const FIELDS = [
        'valid_from',
        'valid_until',
        'observed_at',
        'verified_at',
        'stale_after',
        'source_hash',
        'superseded_by',
        'authority_level',
    ];

    /**
     * Datetime fields cast as `immutable_datetime` per project convention.
     *
     * @var array<int,string>
     */
    public const DATETIME_FIELDS = [
        'valid_from',
        'valid_until',
        'observed_at',
        'verified_at',
        'stale_after',
    ];

    public const AUTHORITY_SYSTEM = 'system';

    public const AUTHORITY_OPERATOR = 'operator';

    public const AUTHORITY_AUTOMATION = 'automation';

    public const AUTHORITY_INFERRED = 'inferred';

    public const AUTHORITY_EXTERNAL_PROVIDER = 'external_provider';

    /**
     * Canonical authority levels, ordered most → least trusted.
     *
     * @var array<int,string>
     */
    public const AUTHORITY_LEVELS = [
        self::AUTHORITY_OPERATOR,
        self::AUTHORITY_SYSTEM,
        self::AUTHORITY_AUTOMATION,
        self::AUTHORITY_EXTERNAL_PROVIDER,
        self::AUTHORITY_INFERRED,
    ];

    /**
     * @return array<string,string>
     */
    public static function casts(): array
    {
        $casts = [
            'source_hash' => 'string',
            'authority_level' => 'string',
        ];
        foreach (self::DATETIME_FIELDS as $field) {
            $casts[$field] = 'immutable_datetime';
        }

        return $casts;
    }
}
