<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OperatorPatternDetection extends Model
{
    use HasUuids;

    public const STATUS_DETECTED = 'detected';
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_PROMOTED = 'promoted';

    protected $fillable = [
        'operator_id',
        'pattern_id',
        'kind',
        'taxonomy_item_id',
        'summary',
        'signature',
        'occurrence_count',
        'window_days',
        'confidence',
        'privacy_class',
        'proposal_target',
        'cadence',
        'evidence',
        'status',
        'proposed_skill_task_id',
        'proposed_mission_id',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'window_days' => 'integer',
            'confidence' => 'float',
            'cadence' => 'array',
            'evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
