<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HermesProcedureCandidate extends Model
{
    use HasUuids;

    protected $table = 'hermes_procedure_candidates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'candidate_hash',
        'status',
        'source_status',
        'risk_level',
        'promotion_allowed',
        'review_required',
        'payload_json',
        'evidence_refs_json',
        'promotion_gate_json',
        'reviewed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'promotion_allowed' => 'boolean',
            'review_required' => 'boolean',
            'payload_json' => 'array',
            'evidence_refs_json' => 'array',
            'promotion_gate_json' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
