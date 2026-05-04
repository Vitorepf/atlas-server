<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDomainProfile extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'status',
        'default_flow',
        'orchestrator',
        'runtime_family',
        'description',
        'autonomy_default',
        'background_allowed',
        'model_policy',
        'context_policy',
        'skill_policy',
        'tool_policy',
        'memory_policy',
        'gate_policy',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'background_allowed' => 'boolean',
            'model_policy' => 'array',
            'context_policy' => 'array',
            'skill_policy' => 'array',
            'tool_policy' => 'array',
            'memory_policy' => 'array',
            'gate_policy' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function flows(): HasMany
    {
        return $this->hasMany(AiFlowProfile::class, 'domain_id', 'id');
    }
}
