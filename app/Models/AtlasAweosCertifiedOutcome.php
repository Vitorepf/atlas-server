<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAweosCertifiedOutcome extends Model
{
    use HasUuids;

    protected $table = 'atlas_aweos_certified_outcomes';

    protected $fillable = [
        'execution_id',
        'schema_version',
        'status',
        'certification_level',
        'claim_hash',
        'claim',
        'patch_boundary',
        'command_ledger',
        'test_impact',
        'evidence_bundle',
        'replay_manifest',
        'learning_decision',
        'aemor_outcome',
        'areg_outcome',
        'aawr_outcome',
        'evidence_refs',
        'outcome_hash',
    ];

    protected function casts(): array
    {
        return [
            'patch_boundary' => 'array',
            'command_ledger' => 'array',
            'test_impact' => 'array',
            'evidence_bundle' => 'array',
            'replay_manifest' => 'array',
            'learning_decision' => 'array',
            'aemor_outcome' => 'array',
            'areg_outcome' => 'array',
            'aawr_outcome' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
