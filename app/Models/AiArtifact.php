<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiArtifact extends Model
{
    use HasUuids;

    protected $table = 'ai_artifacts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'artifact_type',
        'name',
        'path_or_ref',
        'content_hash',
        'metadata',
        'status',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
