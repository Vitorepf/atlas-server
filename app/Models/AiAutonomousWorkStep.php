<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAutonomousWorkStep extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'cycle_record_id', 'schema_version', 'step_id', 'step_index', 'status', 'action_type', 'execution_mode', 'expected_files', 'expected_tests', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'expected_files' => 'array',
            'expected_tests' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
