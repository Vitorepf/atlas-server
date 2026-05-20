<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasExecutiveBriefing extends Model
{
    use HasUuids;

    protected $fillable = [
        'strategic_decision_id',
        'schema_version',
        'status',
        'briefing_type',
        'sections',
        'evidence_refs',
        'briefing_hash',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
