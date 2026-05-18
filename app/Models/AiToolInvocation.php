<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiToolInvocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'tool_definition_id',
        'capability_id',
        'mission_id',
        'work_order_id',
        'invocation_status',
        'input_hash',
        'output_hash',
        'policy_decision_ref',
        'evidence_refs',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AiToolDefinition::class, 'tool_definition_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AiToolReceipt::class, 'tool_invocation_id');
    }
}
