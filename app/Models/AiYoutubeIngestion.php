<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiYoutubeIngestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_id',
        'url',
        'title',
        'channel',
        'status',
        'reason',
        'metadata_source',
        'caption_kind',
        'caption_language',
        'audio_fallback_status',
        'chunk_count',
        'transcript_chars',
        'ingestion_ms',
        'cache_hit',
        'metadata',
        'caption',
        'chunks',
        'diagnostics',
        'last_ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'transcript_chars' => 'integer',
            'ingestion_ms' => 'integer',
            'cache_hit' => 'boolean',
            'metadata' => 'array',
            'caption' => 'array',
            'chunks' => 'array',
            'diagnostics' => 'array',
            'last_ingested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
