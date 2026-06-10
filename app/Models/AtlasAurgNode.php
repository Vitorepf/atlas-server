<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AURG Phase-2 fused-store node — a compact, provider-safe REF into one of the 5
 * source read-models (memory / code / domains / evidence / strategic). The brain
 * never copies source payloads; intra-layer detail stays in the source.
 *
 * `id` is the deterministic node key "<source_kind>:<kind>:<source_id>" built by
 * {@see \App\Services\Ai\Reality\AtlasRealityGraphIngestionService}.
 */
class AtlasAurgNode extends Model
{
    protected $table = 'atlas_aurg_nodes';

    public $incrementing = false;

    protected $keyType = 'string';

    public const SOURCE_KINDS = [
        'memory',
        'code',
        'domain',
        'evidence',
        'strategic',
    ];

    protected $fillable = [
        'id',
        'kind',
        'source_kind',
        'source_id',
        'label',
        'workspace_id',
        'provider_safe',
        'sensitive',
        'meta',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'provider_safe' => 'boolean',
            'sensitive' => 'boolean',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
