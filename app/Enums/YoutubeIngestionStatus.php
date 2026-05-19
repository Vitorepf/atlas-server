<?php

namespace App\Enums;

/**
 * Canonical YouTube ingestion status — mirrors `YoutubeIngestionStatus`
 * exported by `@atlas/rich-input-canon`. See
 * `docs/rich-input/youtube-canon.md` for the cross-stack contract.
 *
 * Single dimension: did we manage to fetch the video record + try to obtain
 * its captions? This does NOT describe whether we have transcript text or
 * whether translation is needed.
 */
enum YoutubeIngestionStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Map the legacy single-string `status` produced by
     * `YouTubeKnowledgeIngestionService` onto this canonical enum.
     */
    public static function fromLegacy(?string $legacy): self
    {
        return match (strtolower(trim((string) $legacy))) {
            'ready' => self::Ready,
            'queued' => self::Queued,
            'processing' => self::Processing,
            'caption_unavailable', 'transcript_empty', 'skipped_duration', 'disabled' => self::Ready,
            default => self::Failed,
        };
    }
}
