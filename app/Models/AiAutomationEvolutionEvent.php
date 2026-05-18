<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationEvolutionEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_automation_evolution_events';

    protected $fillable = [
        'schema_version',
        'uuid',
        'automation_run_id',
        'tool_id',
        'event_kind',
        'observation',
        'recommendation',
        'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'observation' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAutomationRun::class, 'automation_run_id');
    }
}
