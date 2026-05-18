<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAutonomousEngineeringGoal extends Model
{
    use HasUuids;

    protected $fillable = ['schema_version', 'goal_id', 'goal', 'status', 'intent_flow_id', 'promotion_target', 'evidence_refs', 'outcome_receipt_hash', 'certification_hash', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
