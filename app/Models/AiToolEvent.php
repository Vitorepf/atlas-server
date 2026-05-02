<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'event_key',
        'trace_id',
        'session_id',
        'thread_id',
        'tool',
        'risk',
        'permission_status',
        'approval_source',
        'input_summary',
        'output_summary',
        'changed_files',
        'checkpoint_id',
        'exit_code',
        'duration_ms',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'session_id' => 'string',
            'thread_id' => 'string',
            'input_summary' => 'array',
            'output_summary' => 'array',
            'changed_files' => 'array',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
