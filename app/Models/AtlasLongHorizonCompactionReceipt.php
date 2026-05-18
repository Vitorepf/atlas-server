<?php

namespace App\Models;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `atlas.long_horizon.compaction_receipt.v1` rows.
 *
 * Persistence only. The compactor (AiCompactionService + future
 * CompactionReceiptComposer) is what *composes* the payload; this model
 * stores and replays it with a deterministic receipt_hash.
 */
class AtlasLongHorizonCompactionReceipt extends Model
{
    use HasUuids;

    protected $table = 'atlas_long_horizon_compaction_receipts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'scope_type',
        'scope_id',
        'source_context_refs',
        'retained_items',
        'discarded_items',
        'discarded_reason',
        'must_keep_items',
        'must_keep_coverage',
        'unresolved_loss',
        'loss_risk',
        'recovery_queries',
        'evidence_refs',
        'summary_hash',
        'quality_score',
        'detected_contradictions',
        'stale_risks',
        'receipt_hash',
    ];

    protected $attributes = [
        'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
        'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_LOW,
    ];

    protected function casts(): array
    {
        return [
            'source_context_refs' => 'array',
            'retained_items' => 'array',
            'discarded_items' => 'array',
            'must_keep_items' => 'array',
            'unresolved_loss' => 'array',
            'recovery_queries' => 'array',
            'evidence_refs' => 'array',
            'detected_contradictions' => 'array',
            'stale_risks' => 'array',
            'must_keep_coverage' => 'float',
            'quality_score' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Deterministic receipt_hash over the canonical payload. Excludes
     * volatile `id`, `receipt_hash` and timestamps so re-emitting the same
     * compaction outcome yields the same hash.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalReceiptHash(array $payload): string
    {
        $payload['schema_version'] = AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION;
        unset($payload['id'], $payload['receipt_hash'], $payload['created_at'], $payload['updated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
