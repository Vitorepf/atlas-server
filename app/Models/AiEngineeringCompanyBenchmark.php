<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyBenchmark extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'benchmark_id', 'status', 'protocol', 'evidence_refs', 'receipt', 'benchmark_hash'];

    protected function casts(): array
    {
        return [
            'protocol' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
