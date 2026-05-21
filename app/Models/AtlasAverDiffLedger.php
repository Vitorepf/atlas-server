<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAverDiffLedger extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id',
        'schema_version',
        'status',
        'changed_files',
        'action_manifests',
        'patch_verifier_report',
        'rollback_plan',
        'evidence_refs',
        'diff_hash',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'action_manifests' => 'array',
            'patch_verifier_report' => 'array',
            'rollback_plan' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
