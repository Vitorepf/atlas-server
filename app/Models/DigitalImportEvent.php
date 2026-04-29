<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DigitalImportEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'source',
        'source_event_id',
        'event_type',
        'received_at',
        'processed_at',
        'status',
        'error_message',
        'raw_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
