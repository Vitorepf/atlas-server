<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasPowerSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'host_key',
        'kind',
        'status',
        'reason',
        'source',
        'created_by_device_id',
        'ai_job_id',
        'caffeinate_pid',
        'started_at',
        'expires_at',
        'stopped_at',
        'stop_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'created_by_device_id' => 'string',
            'ai_job_id' => 'string',
            'caffeinate_pid' => 'integer',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }
}
