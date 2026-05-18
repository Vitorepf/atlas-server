<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAutonomousWorkCycle extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'cycle_id', 'cycle_index', 'status', 'flow_id', 'objective', 'next_action', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'cycle_index' => 'integer',
            'objective' => 'array',
            'next_action' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
