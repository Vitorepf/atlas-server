<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiToolDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'tool_id',
        'name',
        'description',
        'tool_type',
        'authority_group',
        'risk_level',
        'input_schema',
        'output_schema',
        'auth_requirements',
        'cost_profile',
        'side_effects',
        'evidence_emitted',
        'health_status',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'auth_requirements' => 'array',
            'cost_profile' => 'array',
            'side_effects' => 'array',
            'evidence_emitted' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(AiToolCapability::class, 'tool_definition_id');
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(AiToolInvocation::class, 'tool_definition_id');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(AiToolHealthCheck::class, 'tool_definition_id');
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(AiToolValidationRun::class, 'tool_definition_id');
    }
}
