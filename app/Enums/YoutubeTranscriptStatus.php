<?php

namespace App\Enums;

/**
 * Canonical YouTube transcript status — mirrors `YoutubeTranscriptStatus`
 * from `@atlas/rich-input-canon`. Independent from ingestion and
 * translation; tells us whether we have transcript text to feed the model.
 */
enum YoutubeTranscriptStatus: string
{
    case Unavailable = 'unavailable';
    case Pending = 'pending';
    case OriginalReady = 'original_ready';
    case Failed = 'failed';

    public static function fromStoredStatus(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'ready' => self::OriginalReady,
            'queued', 'processing' => self::Pending,
            'caption_unavailable', 'transcript_empty', 'skipped_duration', 'disabled' => self::Unavailable,
            default => self::Failed,
        };
    }

    public static function fromLegacy(?string $status): self
    {
        return self::fromStoredStatus($status);
    }
}
