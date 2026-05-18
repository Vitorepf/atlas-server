<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationPlan extends Model
{
    use HasUuids;

    protected $table = 'ai_automation_plans';

    protected $fillable = [
        'schema_version',
        'uuid',
        'automation_run_id',
        'plan_type',
        'title',
        'summary',
        'payload',
        'safety_factors',
        'rollback',
        'status',
        'policy_decision',
        'plan_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'safety_factors' => 'array',
            'rollback' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAutomationRun::class, 'automation_run_id');
    }
}
