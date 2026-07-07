<?php

namespace App\Models;

use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Support\TemporalTruth\HasTemporalTruth;
use Illuminate\Database\Eloquent\Model;

/**
 * AURG Phase-2 fused-store edge. Kind comes from the canonical Builder taxonomy
 * ({@see AtlasRealityGraphSnapshotBuilderService} edge kinds);
 * `source` names the deterministic producer (ingest source or cite-or-omit linker);
 * `confidence` follows the documented deterministic ladder (1.0 exact id/path,
 * 0.7 derived path/name match) — never model output, never faked.
 *
 * SIS4 (Obra #20): carries TEOS Temporal Truth fields so `AtlasAurgEdge::current($at)`
 * answers "which relations held at instant T" in SQL (scopes from {@see HasTemporalTruth}).
 * `valid_from` is stamped on create (an edge's validity begins when it first appears);
 * existing rows were backfilled from `created_at`.
 */
class AtlasAurgEdge extends Model
{
    use HasTemporalTruth;

    protected $table = 'atlas_aurg_edges';

    protected $fillable = [
        'from_node_id',
        'to_node_id',
        'kind',
        'source',
        'confidence',
        'meta',
        'valid_from',
        'valid_until',
        'stale_after',
        'superseded_by',
        'authority_level',
    ];

    protected static function booted(): void
    {
        // SIS4: an edge's validity begins the moment it first appears.
        static::creating(function (self $edge): void {
            if ($edge->valid_from === null) {
                $edge->valid_from = $edge->freshTimestamp();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'stale_after' => 'immutable_datetime',
        ];
    }
}
