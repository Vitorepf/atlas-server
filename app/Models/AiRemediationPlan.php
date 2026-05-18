<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRemediationPlan extends Model
{
    use HasUuids;

    protected $table = 'ai_remediation_plans';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'appsec_review_id',
        'plan_id',
        'title',
        'severity',
        'findings_refs',
        'actions',
        'owners',
        'timeline',
        'rollback_plan',
        'status',
        'plan_hash',
    ];

    protected function casts(): array
    {
        return [
            'findings_refs' => 'array',
            'actions' => 'array',
            'owners' => 'array',
            'timeline' => 'array',
            'rollback_plan' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
