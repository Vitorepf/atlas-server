<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasPriorityRanking extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'scope_type',
        'scope_id',
        'criteria',
        'ranked_options',
        'evidence_refs',
        'ranking_hash',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'ranked_options' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
