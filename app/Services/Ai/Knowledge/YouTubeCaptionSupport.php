<?php

declare(strict_types=1);

namespace App\Services\Ai\Knowledge;

use Illuminate\Support\Str;

/**
 * Pure caption parse / track / chunk helpers (full-pass peel from YouTubeKnowledgeIngestionService).
 */
final class YouTubeCaptionSupport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parsePayload(string $body, string $ext): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, 'WEBVTT')) {
            return self::parseVttSegments($trimmed);
        }

        if ($ext === 'json3' || str_starts_with($trimmed, '{')) {
            $json = json_decode($trimmed, true);
            if (! is_array($json)) {
                return str_starts_with($trimmed, '<') ? self::parseXmlSegments($trimmed) : [];
            }

            return self::parseJson3Segments($json);
        }

        if (str_starts_with($trimmed, '<')) {
            return self::parseXmlSegments($trimmed);
        }

        return self::parseVttSegments($trimmed);
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    public static function chunkSegments(array $segments): array
    {
        $chunkSeconds = max(60, (int) config('atlas.youtube.chunk_seconds', 300));
        $maxChunks = max(1, (int) config('atlas.youtube.max_chunks', 80));
        $maxChars = max(4000, (int) config('atlas.youtube.max_transcript_chars', 120000));
        $maxChunkChars = max(1200, (int) config('atlas.youtube.max_chunk_chars', 5000));
        $chunks = [];
        $current = null;
        $totalChars = 0;

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $start = (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? $start);
            $shouldStart = ! is_array($current)
                || $start >= ((float) $current['start'] + $chunkSeconds)
                || mb_strlen((string) $current['text']) + mb_strlen($text) + 1 > $maxChunkChars;

            if ($shouldStart) {
                if (is_array($current)) {
                    $chunks[] = self::finalizeChunk($current, count($chunks) + 1);
                    if (count($chunks) >= $maxChunks || $totalChars >= $maxChars) {
                        break;
                    }
                }

                $current = [
                    'start' => $start,
                    'end' => $end,
                    'text' => $text,
                ];
            } else {
                $current['end'] = max((float) $current['end'], $end);
                $current['text'] = trim((string) $current['text'].' '.$text);
            }

            $totalChars += mb_strlen($text);
            if ($totalChars >= $maxChars) {
                break;
            }
        }

        if (is_array($current) && count($chunks) < $maxChunks) {
            $chunks[] = self::finalizeChunk($current, count($chunks) + 1);
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    public static function selectCaptionTrack(array $metadata): ?array
    {
        $preferred = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('atlas.youtube.preferred_caption_languages', 'pt-BR,pt,en,ja,zh-Hans,zh-Hant,zh')),
        )));
        $groups = [
            'manual' => is_array($metadata['subtitles'] ?? null) ? $metadata['subtitles'] : [],
            'automatic' => is_array($metadata['automatic_captions'] ?? null) ? $metadata['automatic_captions'] : [],
        ];

        foreach ($groups as $kind => $tracksByLanguage) {
            foreach ($preferred as $language) {
                $track = self::firstCaptionForLanguage($tracksByLanguage, $language);
                if ($track) {
                    return array_merge($track, [
                        'track_kind' => $kind,
                        'language' => $track['language'] ?? $language,
                    ]);
                }
            }
        }

        foreach ($groups as $kind => $tracksByLanguage) {
            foreach ($tracksByLanguage as $language => $tracks) {
                if (is_array($tracks) && isset($tracks[0]) && is_array($tracks[0])) {
                    return array_merge($tracks[0], [
                        'track_kind' => $kind,
                        'language' => is_string($language) ? $language : (string) ($tracks[0]['language'] ?? 'und'),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tracksByLanguage
     * @return array<string, mixed>|null
     */
    public static function firstCaptionForLanguage(array $tracksByLanguage, string $language): ?array
    {
        foreach ($tracksByLanguage as $key => $tracks) {
            if (! is_string($key) || ! is_array($tracks)) {
                continue;
            }

            $normalizedKey = Str::of($key)->lower()->replace('_', '-')->value();
            $normalizedLanguage = Str::of($language)->lower()->replace('_', '-')->value();
            if ($normalizedKey !== $normalizedLanguage && ! str_starts_with($normalizedKey, $normalizedLanguage.'-')) {
                continue;
            }

            foreach ($tracks as $track) {
                if (is_array($track) && is_string($track['url'] ?? null)) {
                    $track['language'] ??= $key;

                    return $track;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function captionDownloadUrls(string $url, string $ext): array
    {
        $formats = match ($ext) {
            'vtt' => ['vtt', 'json3'],
            'srv1', 'srv2', 'srv3', 'xml' => [$ext, 'json3', 'vtt'],
            default => ['json3', 'vtt'],
        };

        return collect($formats)
            ->map(fn (string $format): string => self::captionUrlWithFormat($url, $format))
            ->prepend($url)
            ->unique()
            ->values()
            ->all();
    }

    public static function captionUrlWithFormat(string $url, string $format): string
    {
        if (str_contains($url, 'fmt=')) {
            return (string) preg_replace('/([?&])fmt=[^&]*/', '$1fmt='.$format, $url);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'fmt='.$format;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function segmentsFromPlainTranscript(string $text, ?int $durationSeconds): array
    {
        $parts = preg_split('/(?<=[.!?。！？])\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return [];
        }

        $totalChars = max(1, collect($parts)->sum(fn (string $part): int => mb_strlen($part)));
        $duration = max(60, $durationSeconds ?: (int) ceil(count($parts) * 8));
        $segments = [];
        $cursor = 0.0;
        $buffer = '';

        foreach ($parts as $part) {
            $candidate = trim($buffer === '' ? $part : $buffer.' '.$part);
            if (mb_strlen($candidate) < 700) {
                $buffer = $candidate;

                continue;
            }

            $chars = mb_strlen($candidate);
            $segmentDuration = max(4.0, ($chars / $totalChars) * $duration);
            $segments[] = [
                'start' => $cursor,
                'end' => min($duration, $cursor + $segmentDuration),
                'text' => $candidate,
            ];
            $cursor += $segmentDuration;
            $buffer = '';
        }

        if ($buffer !== '') {
            $chars = mb_strlen($buffer);
            $segmentDuration = max(4.0, ($chars / $totalChars) * $duration);
            $segments[] = [
                'start' => $cursor,
                'end' => min($duration, $cursor + $segmentDuration),
                'text' => $buffer,
            ];
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    public static function parseJson3Segments(array $json): array
    {
        $segments = [];
        foreach ((array) ($json['events'] ?? []) as $event) {
            if (! is_array($event) || ! is_array($event['segs'] ?? null)) {
                continue;
            }

            $text = collect($event['segs'])
                ->map(fn (mixed $seg): string => is_array($seg) ? (string) ($seg['utf8'] ?? '') : '')
                ->implode('');
            $text = self::normalizeTranscriptText($text);
            if ($text === '') {
                continue;
            }

            $start = ((float) ($event['tStartMs'] ?? 0)) / 1000;
            $duration = ((float) ($event['dDurationMs'] ?? 0)) / 1000;
            $segments[] = [
                'start' => $start,
                'end' => $start + max(0.1, $duration),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parseVttSegments(string $body): array
    {
        $segments = [];
        $blocks = preg_split("/\R{2,}/", trim($body)) ?: [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []), fn (string $line): bool => $line !== ''));
            if ($lines === [] || str_starts_with($lines[0], 'WEBVTT')) {
                continue;
            }

            $timingIndex = null;
            foreach ($lines as $index => $line) {
                if (str_contains($line, '-->')) {
                    $timingIndex = $index;
                    break;
                }
            }
            if ($timingIndex === null) {
                continue;
            }

            [$startRaw, $endRaw] = array_map('trim', explode('-->', $lines[$timingIndex], 2));
            $endRaw = trim((string) preg_replace('/\s+.+$/', '', $endRaw));
            $text = self::normalizeTranscriptText(implode(' ', array_slice($lines, $timingIndex + 1)));
            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => YouTubeUrlSupport::secondsFromTimestamp($startRaw),
                'end' => YouTubeUrlSupport::secondsFromTimestamp($endRaw),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parseXmlSegments(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $xml) {
            return [];
        }

        $segments = [];
        foreach ($xml->xpath('//text') ?: [] as $node) {
            $attributes = $node->attributes();
            $start = (float) ($attributes['start'] ?? 0);
            $duration = (float) ($attributes['dur'] ?? 0);
            $text = self::normalizeTranscriptText((string) $node);
            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => $start,
                'end' => $start + max(0.1, $duration),
                'text' => $text,
            ];
        }

        foreach ($xml->xpath('//p') ?: [] as $node) {
            $attributes = $node->attributes();
            $text = self::normalizeTranscriptText((string) $node);
            if ($text === '') {
                continue;
            }

            $start = isset($attributes['t'])
                ? ((float) $attributes['t']) / 1000
                : (isset($attributes['start'])
                    ? (float) $attributes['start']
                    : (isset($attributes['begin']) ? YouTubeUrlSupport::secondsFromTimestamp((string) $attributes['begin']) : 0));
            $end = isset($attributes['d'])
                ? $start + (((float) $attributes['d']) / 1000)
                : (isset($attributes['dur'])
                    ? $start + (float) $attributes['dur']
                    : (isset($attributes['end']) ? YouTubeUrlSupport::secondsFromTimestamp((string) $attributes['end']) : $start + 0.1));

            $segments[] = [
                'start' => $start,
                'end' => max($start + 0.1, $end),
                'text' => $text,
            ];
        }

        return collect($segments)
            ->sortBy(fn (array $segment): float => (float) ($segment['start'] ?? 0))
            ->values()
            ->all();
    }

    public static function normalizeTranscriptText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $chunk
     * @return array<string, mixed>
     */
    public static function finalizeChunk(array $chunk, int $index): array
    {
        return [
            'index' => $index,
            'start' => round((float) ($chunk['start'] ?? 0), 2),
            'end' => round((float) ($chunk['end'] ?? 0), 2),
            'start_label' => self::timeLabel((float) ($chunk['start'] ?? 0)),
            'end_label' => self::timeLabel((float) ($chunk['end'] ?? 0)),
            'text' => trim((string) ($chunk['text'] ?? '')),
        ];
    }

    public static function timeLabel(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%02d:%02d', $minutes, $remaining);
    }
}
