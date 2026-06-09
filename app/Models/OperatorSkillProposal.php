<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OperatorSkillProposal extends Model
{
    use HasUuids;

    public const STATUS_STAGED = 'staged';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'operator_id', 'pattern_id', 'detection_id', 'slug', 'title', 'description',
        'status', 'staging_path', 'promoted_path', 'confidence', 'privacy_class',
        'evidence', 'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'evidence' => 'array',
            'promoted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
