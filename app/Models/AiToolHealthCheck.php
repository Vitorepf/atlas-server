<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolHealthCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'tool_definition_id',
        'status',
        'checked_requirements',
        'missing_requirements',
        'output_summary',
        'checked_at',
        'health_hash',
    ];

    protected function casts(): array
    {
        return [
            'checked_requirements' => 'array',
            'missing_requirements' => 'array',
            'output_summary' => 'array',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AiToolDefinition::class, 'tool_definition_id');
    }
}
