<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAgenticWorkcellOrgPattern extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'domain',
        'flow_id',
        'topology',
        'pattern',
        'quality_stats',
        'evidence_refs',
        'pattern_hash',
    ];

    protected function casts(): array
    {
        return [
            'pattern' => 'array',
            'quality_stats' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
