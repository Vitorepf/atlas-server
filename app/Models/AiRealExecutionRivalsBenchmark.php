<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionRivalsBenchmark extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'benchmark_id', 'status', 'rivals', 'comparison_protocol', 'false_claim_blocked', 'benchmark_candidate', 'evidence_refs', 'receipt', 'benchmark_hash'];

    protected function casts(): array
    {
        return [
            'rivals' => 'array',
            'comparison_protocol' => 'array',
            'false_claim_blocked' => 'boolean',
            'benchmark_candidate' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
