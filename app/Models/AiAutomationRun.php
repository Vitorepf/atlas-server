<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAutomationRun extends Model
{
    use HasUuids;

    protected $table = 'ai_automation_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'run_kind',
        'status',
        'objective',
        'inputs',
        'outputs',
        'blockers',
        'next_action',
        'receipt_hash',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'outputs' => 'array',
            'blockers' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(AiAutomationPlan::class, 'automation_run_id');
    }

    public function toolDecisions(): HasMany
    {
        return $this->hasMany(AiAutomationToolDecision::class, 'automation_run_id');
    }

    public function evolutionEvents(): HasMany
    {
        return $this->hasMany(AiAutomationEvolutionEvent::class, 'automation_run_id');
    }
}
