<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAttachmentIndexEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'ai_job_id',
        'thread_id',
        'attachment_id',
        'attachment_kind',
        'source_name',
        'mime_type',
        'unit_type',
        'unit_number',
        'title',
        'excerpt',
        'visual_caption',
        'metadata',
        'content_hash',
        'embedding',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'ai_job_id' => 'string',
            'thread_id' => 'string',
            'unit_number' => 'integer',
            'metadata' => 'array',
            'indexed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
