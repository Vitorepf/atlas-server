<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionTestRun extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'patch_run_record_id', 'schema_version', 'test_run_id', 'status', 'selected_tests', 'impact_reasoning', 'exit_code', 'output_excerpt', 'evidence_refs', 'receipt', 'test_hash'];

    protected function casts(): array
    {
        return [
            'selected_tests' => 'array',
            'impact_reasoning' => 'array',
            'exit_code' => 'integer',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
