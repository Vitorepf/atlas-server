<?php

declare(strict_types=1);

namespace App\Services\Ai\Knowledge;

use Illuminate\Support\Str;

/**
 * Pure metadata merge / chapter / public projection helpers (full-pass peel).
 */
final class YouTubeMetadataSupport
{
    /**
     * @param  array<string, mixed>  $official
     * @param  array<string, mixed>  $captionSource
     * @return array<string, mixed>
     */
    public static function mergeMetadata(array $official, array $captionSource): array
    {
        if ($official === []) {
            return $captionSource;
        }

        if ($captionSource === []) {
            return $official;
        }

        $merged = array_merge($captionSource, array_filter($official, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));
        $merged['subtitles'] = is_array($captionSource['subtitles'] ?? null) ? $captionSource['subtitles'] : (is_array($official['subtitles'] ?? null) ? $official['subtitles'] : []);
        $merged['automatic_captions'] = is_array($captionSource['automatic_captions'] ?? null) ? $captionSource['automatic_captions'] : (is_array($official['automatic_captions'] ?? null) ? $official['automatic_captions'] : []);
        $merged['metadata_source'] = ($official['metadata_source'] ?? null) === 'youtube_data_api'
            ? 'youtube_data_api+captions'
            : ($merged['metadata_source'] ?? 'unknown');

        return $merged;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function chaptersFromDescription(string $description): array
    {
        if (trim($description) === '') {
            return [];
        }

        preg_match_all('/(?:^|\n)\s*((?:\d{1,2}:)?\d{1,2}:\d{2})\s+(.+?)(?=\n|$)/u', $description, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match): array => [
                'start' => YouTubeUrlSupport::secondsFromTimestamp((string) $match[1]),
                'start_label' => (string) $match[1],
                'title' => trim((string) $match[2]),
            ])
            ->filter(fn (array $chapter): bool => $chapter['title'] !== '')
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function publicMetadata(array $metadata): array
    {
        return [
            'id' => $metadata['id'] ?? null,
            'title' => $metadata['title'] ?? null,
            'channel' => $metadata['channel'] ?? $metadata['uploader'] ?? null,
            'channel_id' => $metadata['channel_id'] ?? null,
            'duration_seconds' => isset($metadata['duration']) ? (int) $metadata['duration'] : null,
            'webpage_url' => $metadata['webpage_url'] ?? $metadata['original_url'] ?? null,
            'language' => $metadata['language'] ?? null,
            'published_at' => $metadata['published_at'] ?? $metadata['upload_date'] ?? null,
            'description_excerpt' => is_string($metadata['description'] ?? null)
                ? Str::limit(trim((string) $metadata['description']), 1200, '')
                : null,
            'chapters' => is_array($metadata['chapters'] ?? null) ? array_slice($metadata['chapters'], 0, 80) : [],
            'view_count' => isset($metadata['view_count']) ? (int) $metadata['view_count'] : null,
            'like_count' => isset($metadata['like_count']) ? (int) $metadata['like_count'] : null,
            'metadata_source' => $metadata['metadata_source'] ?? 'yt_dlp',
            'data_api_status' => $metadata['data_api_status'] ?? null,
            'data_api_quota_units' => $metadata['data_api_quota_units'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $caption
     * @return array<string, mixed>
     */
    public static function publicCaption(array $caption): array
    {
        return [
            'language' => $caption['language'] ?? null,
            'name' => $caption['name'] ?? null,
            'kind' => $caption['track_kind'] ?? $caption['kind'] ?? null,
            'ext' => $caption['ext'] ?? null,
        ];
    }

    public static function estimatedAudioFallbackSeconds(?int $durationSeconds): ?int
    {
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }

        $ratio = (float) config('atlas.youtube.audio_transcription_realtime_ratio', 0.65);
        $ratio = min(1.5, max(0.1, $ratio));

        return max(60, (int) ceil($durationSeconds * $ratio) + 45);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function processingDiagnostics(array $metadata, ?int $startedAt = null): array
    {
        $duration = isset($metadata['duration_seconds'])
            ? (int) $metadata['duration_seconds']
            : (isset($metadata['duration']) ? (int) $metadata['duration'] : null);
        $eta = self::estimatedAudioFallbackSeconds($duration);
        $elapsed = $startedAt ? max(0, time() - $startedAt) : 0;
        $progress = $eta > 0
            ? min(0.88, round($elapsed / $eta, 2))
            : null;

        return [
            'stage' => 'audio_transcription',
            'status' => 'processing',
            'progress' => $progress,
            'elapsed_seconds' => $elapsed,
            'estimated_total_seconds' => $eta,
            'estimated_remaining_seconds' => $eta > 0 ? max(30, $eta - $elapsed) : null,
            'message' => 'Transcrevendo o audio do YouTube em background.',
            'retry_after_seconds' => 45,
        ];
    }

    public static function extractInitialPlayerResponse(string $html): array
    {
        $needle = 'ytInitialPlayerResponse';
        $offset = strpos($html, $needle);
        if ($offset === false) {
            return [];
        }

        $start = strpos($html, '{', $offset);
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($html, $start, $i - $start + 1);
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : [];
                }
            }
        }

        return [];
    }


    public static function isRetryableDownloadError(string $reason): bool
    {
        $text = Str::of($reason)->lower()->value();

        return str_contains($text, 'http error 429')
            || str_contains($text, 'http error 403')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'temporarily unavailable')
            || str_contains($text, 'timed out')
            || str_contains($text, 'timeout');
    }

}
