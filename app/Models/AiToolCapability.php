<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolCapability extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'capability_id',
        'tool_definition_id',
        'name',
        'description',
        'input_schema',
        'output_schema',
        'required_policy_gates',
        'required_evidence',
        'maturity_level',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'required_policy_gates' => 'array',
            'required_evidence' => 'array',
            'maturity_level' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AiToolDefinition::class, 'tool_definition_id');
    }
}
