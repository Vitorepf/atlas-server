<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFlowProfile extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'domain_id',
        'label',
        'status',
        'orchestrator',
        'runtime',
        'description',
        'autonomy',
        'background_allowed',
        'requires_human_approval_for_destructive',
        'model_policy',
        'context_policy',
        'skill_policy',
        'tool_policy',
        'memory_policy',
        'gate_policy',
        'execution_policy',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'background_allowed' => 'boolean',
            'requires_human_approval_for_destructive' => 'boolean',
            'model_policy' => 'array',
            'context_policy' => 'array',
            'skill_policy' => 'array',
            'tool_policy' => 'array',
            'memory_policy' => 'array',
            'gate_policy' => 'array',
            'execution_policy' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(AiDomainProfile::class, 'domain_id', 'id');
    }
}
