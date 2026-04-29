<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DigitalSession extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'source',
        'source_event_id',
        'source_identifier',
        'source_name',
        'source_kind',
        'category_class_at_time',
        'category_label_at_time',
        'intentionality',
        'started_at',
        'ended_at',
        'duration_seconds',
        'recorded_timezone',
        'focus_mode_active',
        'project_name',
        'task_name',
        'url_domain',
        'productivity_score',
        'linked_capture_id',
        'linked_decision_id',
        'raw_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'category_class_at_time' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'productivity_score' => 'float',
            'raw_payload' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function linkedCapture(): BelongsTo
    {
        return $this->belongsTo(Capture::class, 'linked_capture_id');
    }
}
