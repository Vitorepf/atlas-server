<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HermesCapabilityCandidate extends Model
{
    use HasUuids;

    protected $table = 'hermes_capability_candidates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'capability_hash',
        'capability_class',
        'capability_key',
        'status',
        'gate_status',
        'risk_level',
        'enabled',
        'review_required',
        'payload_json',
        'evidence_refs_json',
        'gate_json',
        'reviewed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'review_required' => 'boolean',
            'payload_json' => 'array',
            'evidence_refs_json' => 'array',
            'gate_json' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
