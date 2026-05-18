<?php

namespace App\Models;

use App\Support\TemporalTruth\HasTemporalTruth;
use App\Support\TemporalTruth\TemporalTruthCanon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCodebaseWorldModelEdge extends Model
{
    use HasTemporalTruth;
    use HasUuids;

    protected $fillable = [
        'world_model_id', 'from_node_id', 'to_node_id', 'edge_type', 'metadata',
        // TEOS-I1 temporal truth fields (all nullable).
        'valid_from', 'valid_until', 'observed_at', 'verified_at',
        'stale_after', 'source_hash', 'superseded_by', 'authority_level',
    ];

    protected function casts(): array
    {
        return array_merge(['metadata' => 'array'], TemporalTruthCanon::casts());
    }
}
