<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AURG Phase-2 fused-store edge. Kind comes from the canonical Builder taxonomy
 * ({@see \App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService} edge kinds);
 * `source` names the deterministic producer (ingest source or cite-or-omit linker);
 * `confidence` follows the documented deterministic ladder (1.0 exact id/path,
 * 0.7 derived path/name match) — never model output, never faked.
 */
class AtlasAurgEdge extends Model
{
    protected $table = 'atlas_aurg_edges';

    protected $fillable = [
        'from_node_id',
        'to_node_id',
        'kind',
        'source',
        'confidence',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
