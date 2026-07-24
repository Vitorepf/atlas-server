<?php

declare(strict_types=1);

namespace App\Services\Ai\Knowledge;

use Throwable;

/**
 * Pure YouTube URL / duration helpers (full-pass density peel from ingestion service).
 */
final class YouTubeUrlSupport
{
    public static function canonicalUrl(string $url): string
    {
        $videoId = self::videoIdFromUrl($url);
        if ($videoId === null) {
            return $url;
        }

        return 'https://www.youtube.com/watch?v='.$videoId;
    }

    public static function videoIdFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (str_contains($host, 'youtu.be') && $path !== '') {
            return strtok($path, '/') ?: null;
        }

        if (isset($query['v']) && is_string($query['v']) && $query['v'] !== '') {
            return $query['v'];
        }

        foreach (['shorts/', 'live/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $id = substr($path, strlen($prefix));

                return strtok($id, '/') ?: null;
            }
        }

        return null;
    }

    public static function secondsFromIso8601Duration(string $duration): ?int
    {
        if ($duration === '') {
            return null;
        }

        try {
            $interval = new \DateInterval($duration);
        } catch (Throwable) {
            return null;
        }

        return ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }

    public static function secondsFromTimestamp(string $timestamp): float
    {
        $parts = array_map('floatval', explode(':', str_replace(',', '.', $timestamp)));
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        return (float) ($parts[0] ?? 0);
    }
}
