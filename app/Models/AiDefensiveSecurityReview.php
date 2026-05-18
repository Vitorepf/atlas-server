<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiDefensiveSecurityReview extends Model
{
    use HasUuids;

    protected $table = 'ai_defensive_security_reviews';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'review_id',
        'review_kind',
        'title',
        'scope',
        'controls_inspected',
        'findings',
        'recommendations',
        'artifact_refs',
        'status',
        'review_hash',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'controls_inspected' => 'array',
            'findings' => 'array',
            'recommendations' => 'array',
            'artifact_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
