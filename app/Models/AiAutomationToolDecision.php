<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationToolDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_automation_tool_decisions';

    protected $fillable = [
        'schema_version',
        'uuid',
        'automation_run_id',
        'need',
        'decision_kind',
        'selected_tool_id',
        'alternatives',
        'safety_factors',
        'score',
        'justification',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'alternatives' => 'array',
            'safety_factors' => 'array',
            'score' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAutomationRun::class, 'automation_run_id');
    }
}
