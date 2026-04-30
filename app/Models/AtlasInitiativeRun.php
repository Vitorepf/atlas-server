<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasInitiativeRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'kind',
        'status',
        'started_at',
        'finished_at',
        'scope',
        'findings',
        'emitted_inbox_item_ids',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'scope' => 'array',
            'findings' => 'array',
            'emitted_inbox_item_ids' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
