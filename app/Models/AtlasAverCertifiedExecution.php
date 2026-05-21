<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAverCertifiedExecution extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id',
        'schema_version',
        'status',
        'certification_level',
        'command_summary',
        'diff_summary',
        'test_summary',
        'repair_summary',
        'evidence_bundle',
        'claim_policy',
        'evidence_refs',
        'certification_hash',
    ];

    protected function casts(): array
    {
        return [
            'command_summary' => 'array',
            'diff_summary' => 'array',
            'test_summary' => 'array',
            'repair_summary' => 'array',
            'evidence_bundle' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
