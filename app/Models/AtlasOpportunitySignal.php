<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasOpportunitySignal extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'opportunity_type',
        'domain',
        'summary',
        'leverage_score',
        'evidence_refs',
        'metadata',
        'opportunity_hash',
    ];

    protected function casts(): array
    {
        return [
            'leverage_score' => 'integer',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
