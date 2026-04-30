<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiContextBundle extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'purpose',
        'title',
        'summary',
        'body_for_thread',
        'source_refs',
        'trace_refs',
        'job_refs',
        'metric_refs',
        'file_refs',
        'diff_refs',
        'raw_payload',
        'redaction_status',
        'token_estimate',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'source_refs' => 'array',
            'trace_refs' => 'array',
            'job_refs' => 'array',
            'metric_refs' => 'array',
            'file_refs' => 'array',
            'diff_refs' => 'array',
            'raw_payload' => 'array',
            'token_estimate' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function inboxItems(): HasMany
    {
        return $this->hasMany(AiInboxItem::class, 'context_bundle_id');
    }
}
