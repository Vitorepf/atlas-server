<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAverRepairCycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id',
        'schema_version',
        'status',
        'attempt',
        'failure_packet',
        'repair_plan',
        'evidence_refs',
        'repair_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'failure_packet' => 'array',
            'repair_plan' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
