<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureDocumentationArtifact extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_documentation_artifacts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'venture_id',
        'doc_kind',
        'title',
        'relative_path',
        'written_to_disk',
        'sections',
        'content',
        'line_count',
        'content_hash',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'written_to_disk' => 'boolean',
            'sections' => 'array',
            'line_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
