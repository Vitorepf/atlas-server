<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasProjectBlocker extends Model
{
    use HasUuids;

    public const STATUSES = ['open', 'resolved', 'cancelled'];

    public const SEVERITIES = ['low', 'medium', 'high'];

    public const REASON_CODES = [
        'unclear',
        'too_large',
        'boring',
        'waiting_external',
        'missing_resource',
        'fear',
        'energy',
        'technical_unknown',
        'decision_needed',
        'other',
    ];

    protected $fillable = [
        'project_id',
        'task_id',
        'project_step_id',
        'unblock_task_id',
        'status',
        'severity',
        'reason_code',
        'description',
        'unblock_next_action',
        'waiting_on',
        'due_at',
        'resolved_at',
        'resolution_note',
        'created_from_event_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'project_step_id');
    }

    public function unblockTask(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'unblock_task_id');
    }

    public function createdFromEvent(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectEvent::class, 'created_from_event_id');
    }
}
