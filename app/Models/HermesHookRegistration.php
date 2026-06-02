<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HermesHookRegistration extends Model
{
    use HasUuids;

    protected $table = 'hermes_hook_registrations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'trace_id',
        'mission_hash',
        'events_json',
        'consent_basis',
        'hermes_home_hash',
        'receipt_hash',
        'active',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'events_json' => 'array',
            'active' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
