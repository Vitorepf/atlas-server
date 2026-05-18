<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolValidationRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'tool_definition_id',
        'validation_type',
        'status',
        'input_fixture_ref',
        'output_ref',
        'evidence_refs',
        'validation_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AiToolDefinition::class, 'tool_definition_id');
    }
}
