<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRepairLoop extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'cycle_record_id', 'step_record_id', 'schema_version', 'repair_id', 'status', 'failure_class', 'failure', 'repair_steps', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'failure' => 'array',
            'repair_steps' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
