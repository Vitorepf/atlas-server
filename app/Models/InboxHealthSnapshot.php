<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InboxHealthSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_date',
        'domain',
        'open_count',
        'no_destination_count',
        'failed_count',
        'curation_candidate_count',
        'average_age_hours',
        'oldest_capture_at',
        'metrics',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'open_count' => 'integer',
            'no_destination_count' => 'integer',
            'failed_count' => 'integer',
            'curation_candidate_count' => 'integer',
            'average_age_hours' => 'float',
            'oldest_capture_at' => 'immutable_datetime',
            'metrics' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
