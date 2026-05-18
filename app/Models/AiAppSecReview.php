<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAppSecReview extends Model
{
    use HasUuids;

    protected $table = 'ai_appsec_reviews';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'review_id',
        'title',
        'target_kind',
        'target_ref',
        'owasp_categories',
        'findings',
        'recommendations',
        'artifact_refs',
        'risk_score',
        'status',
        'review_hash',
    ];

    protected function casts(): array
    {
        return [
            'owasp_categories' => 'array',
            'findings' => 'array',
            'recommendations' => 'array',
            'artifact_refs' => 'array',
            'risk_score' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
