<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VaultHealthSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_date',
        'total_notes',
        'active_notes',
        'inbox_notes',
        'invalid_notes',
        'stale_notes',
        'notes_without_triggers',
        'notes_without_links',
        'activations_7d',
        'useful_activations_7d',
        'health_state',
        'recommendations',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'total_notes' => 'integer',
            'active_notes' => 'integer',
            'inbox_notes' => 'integer',
            'invalid_notes' => 'integer',
            'stale_notes' => 'integer',
            'notes_without_triggers' => 'integer',
            'notes_without_links' => 'integer',
            'activations_7d' => 'integer',
            'useful_activations_7d' => 'integer',
            'recommendations' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
