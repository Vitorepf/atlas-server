<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasToolDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'type',
        'category',
        'description',
        'homepage',
        'license_posture',
        'cost_posture',
        'default_enabled',
        'default_timeout_seconds',
        'default_failure_policy',
        'risk_level',
        'status',
        'execution_tier',
        'expected_cost',
        'default_trigger',
        'authority_role',
        'authority_group',
        'detected_version',
        'capabilities_json',
        'runtime_json',
        'detect_json',
        'outputs_json',
        'risks_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
            'default_timeout_seconds' => 'integer',
            'capabilities_json' => 'array',
            'runtime_json' => 'array',
            'detect_json' => 'array',
            'outputs_json' => 'array',
            'risks_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function installations(): HasMany
    {
        return $this->hasMany(AtlasToolInstallation::class, 'tool_definition_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AtlasToolRun::class, 'tool_definition_id');
    }
}
