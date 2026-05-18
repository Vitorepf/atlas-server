<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionRepairAttempt extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'patch_run_record_id', 'test_run_record_id', 'schema_version', 'repair_attempt_id', 'status', 'failure_class', 'failure', 'repair_plan', 'changed_files', 'evidence_refs', 'receipt', 'repair_hash'];

    protected function casts(): array
    {
        return [
            'failure' => 'array',
            'repair_plan' => 'array',
            'changed_files' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
