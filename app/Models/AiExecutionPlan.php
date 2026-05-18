<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiExecutionPlan extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'cycle_record_id', 'rag_gate_id', 'world_model_id', 'schema_version', 'plan_id', 'status', 'target_flow_id', 'steps', 'expected_files', 'expected_tests', 'risks', 'rollback_plan', 'compounding_memories', 'receipt', 'plan_hash'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'expected_files' => 'array',
            'expected_tests' => 'array',
            'risks' => 'array',
            'rollback_plan' => 'array',
            'compounding_memories' => 'array',
            'receipt' => 'array',
        ];
    }
}
