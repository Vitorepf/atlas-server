<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasProgrammingLearningCandidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'candidate_hash',
        'status',
        'source_status',
        'promotion_allowed',
        'review_required',
        'evidence_refs_json',
        'payload_json',
        'promotion_gate_json',
        'rollback_json',
        'reviewed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'promotion_allowed' => 'boolean',
            'review_required' => 'boolean',
            'evidence_refs_json' => 'array',
            'payload_json' => 'array',
            'promotion_gate_json' => 'array',
            'rollback_json' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
