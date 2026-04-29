<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiProviderHealthSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'provider',
        'status',
        'checked_at',
        'last_success_at',
        'last_failure_at',
        'total_jobs_24h',
        'failed_jobs_24h',
        'p50_latency_ms',
        'operational_pain_score',
        'message',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'total_jobs_24h' => 'integer',
            'failed_jobs_24h' => 'integer',
            'p50_latency_ms' => 'integer',
            'operational_pain_score' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
