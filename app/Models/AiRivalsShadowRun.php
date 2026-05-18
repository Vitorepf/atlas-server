<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRivalsShadowRun extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'cycle_record_id', 'schema_version', 'shadow_run_id', 'status', 'rivals', 'comparison_plan', 'false_claim_blocked', 'benchmark_candidate', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'rivals' => 'array',
            'comparison_plan' => 'array',
            'false_claim_blocked' => 'boolean',
            'benchmark_candidate' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
