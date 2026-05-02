<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasMemoryProviderProjectionAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'action',
        'target',
        'workspace',
        'initiator',
        'confirmation_mode',
        'status',
        'ok',
        'summary_json',
        'applied_json',
        'blocked_json',
        'failed_json',
        'review_summary_json',
        'metadata',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'summary_json' => 'array',
            'applied_json' => 'array',
            'blocked_json' => 'array',
            'failed_json' => 'array',
            'review_summary_json' => 'array',
            'metadata' => 'array',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
