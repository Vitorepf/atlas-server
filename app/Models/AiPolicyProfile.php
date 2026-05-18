<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPolicyProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'policy_id',
        'name',
        'scope_type',
        'scope_ref',
        'autonomy_level',
        'risk_tolerance',
        'approval_rules',
        'tool_permissions',
        'provider_permissions',
        'data_permissions',
        'budget_defaults',
        'forbidden_actions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'approval_rules' => 'array',
            'tool_permissions' => 'array',
            'provider_permissions' => 'array',
            'data_permissions' => 'array',
            'budget_defaults' => 'array',
            'forbidden_actions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function forbiddenActions(): HasMany
    {
        return $this->hasMany(AiForbiddenAction::class, 'policy_profile_id');
    }

    public function safetyDecisions(): HasMany
    {
        return $this->hasMany(AiSafetyDecision::class, 'policy_profile_id');
    }
}
