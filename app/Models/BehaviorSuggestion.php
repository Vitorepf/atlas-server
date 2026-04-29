<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BehaviorSuggestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'suggested_name',
        'source_capture_ids',
        'detection_score',
        'status',
        'shown_count',
        'last_shown_at',
        'metadata',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'source_capture_ids' => 'array',
            'detection_score' => 'float',
            'shown_count' => 'integer',
            'last_shown_at' => 'immutable_datetime',
            'metadata' => 'array',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
