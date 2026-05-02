<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasToolInstallation extends Model
{
    use HasUuids;

    protected $fillable = [
        'tool_definition_id',
        'workspace_hash',
        'execution_layer',
        'status',
        'version',
        'binary_path_hash',
        'node_modules_path_hash',
        'detected_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AtlasToolDefinition::class, 'tool_definition_id');
    }
}
