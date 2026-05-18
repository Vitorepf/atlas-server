<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyRoleRun extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'cycle_record_id', 'schema_version', 'role_run_id', 'role_id', 'status', 'responsibilities', 'output', 'evidence_refs', 'receipt', 'role_hash'];

    protected function casts(): array
    {
        return [
            'responsibilities' => 'array',
            'output' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
