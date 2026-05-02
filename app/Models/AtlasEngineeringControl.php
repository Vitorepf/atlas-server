<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringControl extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'direction',
        'execution_type',
        'regulation_category',
        'timing',
        'required',
        'risk_level',
        'applies_when_json',
        'failure_policy',
        'command',
        'skill_slug',
        'metadata',
        'definition_hash',
        'version',
        'versioned_at',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'applies_when_json' => 'array',
            'metadata' => 'array',
            'version' => 'integer',
            'versioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(AtlasEngineeringControlResult::class, 'control_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AtlasEngineeringControlRevision::class, 'control_id');
    }
}
