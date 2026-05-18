<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCyberScopeRules extends Model
{
    use HasUuids;

    protected $table = 'ai_cyber_scope_rules';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'scope_id',
        'in_scope_targets',
        'out_of_scope_targets',
        'allowed_techniques',
        'forbidden_techniques',
        'rate_limits',
        'time_windows',
        'legal_constraints',
        'privacy_constraints',
        'escalation_contacts',
        'status',
        'rules_hash',
    ];

    protected function casts(): array
    {
        return [
            'in_scope_targets' => 'array',
            'out_of_scope_targets' => 'array',
            'allowed_techniques' => 'array',
            'forbidden_techniques' => 'array',
            'rate_limits' => 'array',
            'time_windows' => 'array',
            'legal_constraints' => 'array',
            'privacy_constraints' => 'array',
            'escalation_contacts' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
