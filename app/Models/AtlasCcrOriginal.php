<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable, content-addressed store of an ORIGINAL block that the compression layer
 * (AP-813) replaced with a compressed form + retrieval marker.
 *
 * This is what makes Atlas CCR "lossless-by-governance": unlike headroom's TTL/LRU
 * cache, the original is persisted here (and cross-linked to an append-only Evidence
 * Ledger event) and is NEVER auto-deleted. The provider sees the compressed form;
 * the original is always retrievable by `original_hash` via the governed
 * `atlas_ccr_retrieve` MCP tool.
 */
class AtlasCcrOriginal extends Model
{
    protected $table = 'atlas_ccr_originals';

    protected $fillable = [
        'original_hash',
        'content_type',
        'codec',
        'original_bytes',
        'compressed_bytes',
        'compressed_blob',
        'privacy_class',
        'ledger_event_id',
        'scope_type',
        'scope_id',
        'recorded_by',
        'retrieved_count',
        'last_retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'original_bytes' => 'integer',
            'compressed_bytes' => 'integer',
            'retrieved_count' => 'integer',
            'last_retrieved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
