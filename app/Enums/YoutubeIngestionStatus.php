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

    public static function fromStoredStatus(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'ready' => self::Ready,
            'queued' => self::Queued,
            'processing' => self::Processing,
            'caption_unavailable', 'transcript_empty', 'skipped_duration', 'disabled' => self::Ready,
            default => self::Failed,
        };
    }

    public static function fromLegacy(?string $status): self
    {
        return self::fromStoredStatus($status);
    }
}
