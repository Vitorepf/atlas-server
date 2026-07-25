<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Support;

use Illuminate\Support\Carbon;

/**
 * Pure command-outcome scoring, duration buckets, effectiveness stats, and
 * cache-backed hash-ref normalization (full-pass peel from AWTR section).
 */
final class CommandOutcomeScoringSupport
{
    /**
     * @var list<string>
     */
    public const EFFECTIVENESS_COUNTER_KEYS = [
        'success_count',
        'failure_count',
        'neutral_count',
        'total_count',
        'score',
    ];

    /**
     * @param  array<int,mixed>  $samples
     * @return list<int>
     */
    public static function cappedDurationSamples(array $samples, int $durationMs, int $limit = 24): array
    {
        $samples = array_values(array_filter(array_map(
            static fn (mixed $sample): int => is_numeric($sample) ? max(1, (int) $sample) : 0,
            $samples,
        ), static fn (int $sample): bool => $sample > 0));
        $samples[] = max(1, $durationMs);

        return array_slice($samples, max(0, count($samples) - $limit));
    }

    /**
     * @param  array<int,mixed>  $samples
     */
    public static function durationPercentile(array $samples, float $percentile): ?int
    {
        $samples = array_values(array_filter(array_map(
            static fn (mixed $sample): int => is_numeric($sample) ? max(1, (int) $sample) : 0,
            $samples,
        ), static fn (int $sample): bool => $sample > 0));
        if ($samples === []) {
            return null;
        }

        sort($samples);
        $index = (int) ceil(max(0.0, min(1.0, $percentile)) * count($samples)) - 1;

        return $samples[max(0, min(count($samples) - 1, $index))];
    }

    public static function durationBucket(int $durationMs): string
    {
        return match (true) {
            $durationMs <= 10_000 => 'under_10s',
            $durationMs <= 60_000 => '10s_to_60s',
            $durationMs <= 300_000 => '1m_to_5m',
            $durationMs <= 900_000 => '5m_to_15m',
            default => 'over_15m',
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function emptyPerformanceProfile(string $key): array
    {
        return [
            'key' => $key,
            'observed_count' => 0,
            'duration_ms_total' => 0,
            'duration_ms_samples' => [],
            'duration_ms_avg' => null,
            'duration_ms_min' => null,
            'duration_ms_max' => null,
            'duration_ms_p95' => null,
            'duration_bucket_counts' => [
                'under_10s' => 0,
                '10s_to_60s' => 0,
                '1m_to_5m' => 0,
                '5m_to_15m' => 0,
                'over_15m' => 0,
            ],
            'performance_grade' => 'unknown',
        ];
    }

    /**
     * @param  array<string,mixed>  $profiles
     */
    public static function recordPerformanceProfile(array &$profiles, string $key, int $durationMs): void
    {
        $key = trim($key);
        if ($key === '' || $durationMs <= 0) {
            return;
        }

        $profiles[$key] ??= self::emptyPerformanceProfile($key);
        $profiles[$key]['observed_count'] = (int) $profiles[$key]['observed_count'] + 1;
        $profiles[$key]['duration_ms_total'] = (int) $profiles[$key]['duration_ms_total'] + $durationMs;
        $profiles[$key]['duration_ms_samples'] = self::cappedDurationSamples(
            (array) ($profiles[$key]['duration_ms_samples'] ?? []),
            $durationMs,
        );
        $profiles[$key]['duration_ms_avg'] = (int) round(
            (int) $profiles[$key]['duration_ms_total'] / max((int) $profiles[$key]['observed_count'], 1),
        );
        $profiles[$key]['duration_ms_min'] = $profiles[$key]['duration_ms_min'] === null
            ? $durationMs
            : min((int) $profiles[$key]['duration_ms_min'], $durationMs);
        $profiles[$key]['duration_ms_max'] = $profiles[$key]['duration_ms_max'] === null
            ? $durationMs
            : max((int) $profiles[$key]['duration_ms_max'], $durationMs);
        $profiles[$key]['duration_ms_p95'] = self::durationPercentile((array) $profiles[$key]['duration_ms_samples'], 0.95);
        $bucket = self::durationBucket($durationMs);
        $profiles[$key]['duration_bucket_counts'][$bucket] = (int) ($profiles[$key]['duration_bucket_counts'][$bucket] ?? 0) + 1;
        $profiles[$key]['performance_grade'] = self::commandPerformanceGrade([
            'duration_ms_p95' => $profiles[$key]['duration_ms_p95'],
            'duration_ms_avg' => $profiles[$key]['duration_ms_avg'],
        ]);
    }

    /**
     * Normalize a single cache-backed or canonical hash ref.
     * Empty string means the ref is not a valid cache-backed hash for the prefix.
     */
    public static function normalizeCacheBackedHashRef(mixed $ref, string $canonicalPrefix): string
    {
        $cachePrefix = 'awis_cache:'.$canonicalPrefix.':';
        $canonicalPattern = '/^'.preg_quote($canonicalPrefix, '/').':[a-f0-9]{64}$/';
        $ref = trim((string) $ref);
        if (preg_match($canonicalPattern, $ref) === 1) {
            return $ref;
        }
        if (! str_starts_with($ref, $cachePrefix)) {
            return '';
        }

        $hash = substr($ref, strlen($cachePrefix));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $canonicalPrefix.':'.$hash : '';
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    public static function commandPerformanceScore(array $stats): int
    {
        $duration = $stats['duration_ms_p95'] ?? $stats['duration_ms_avg'] ?? null;
        if (! is_numeric($duration) || (int) $duration <= 0) {
            return 0;
        }

        $duration = (int) $duration;

        return match (true) {
            $duration <= 10_000 => 2,
            $duration <= 60_000 => 1,
            $duration <= 300_000 => 0,
            $duration <= 900_000 => -1,
            default => -3,
        };
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    public static function commandPerformanceGrade(array $stats): string
    {
        $duration = $stats['duration_ms_p95'] ?? $stats['duration_ms_avg'] ?? null;
        if (! is_numeric($duration) || (int) $duration <= 0) {
            return 'unknown';
        }

        $duration = (int) $duration;

        return match (true) {
            $duration <= 10_000 => 'fast',
            $duration <= 60_000 => 'normal',
            $duration <= 300_000 => 'heavy',
            default => 'slow',
        };
    }

    public static function commandRecencyScore(string $observedAt): int
    {
        if ($observedAt === '') {
            return 0;
        }

        try {
            $days = Carbon::parse($observedAt)->diffInDays(Carbon::now());
        } catch (\Throwable) {
            return 0;
        }

        return match (true) {
            $days <= 2 => 2,
            $days <= 14 => 1,
            $days >= 90 => -1,
            default => 0,
        };
    }

    public static function outcomePolarity(string $status): int
    {
        $status = mb_strtolower(trim($status));
        if (in_array($status, ['success', 'succeeded', 'passed', 'completed', 'approved', 'healthy', 'ready'], true)) {
            return 1;
        }
        if (in_array($status, ['failed', 'failure', 'blocked', 'error', 'rejected', 'cancelled', 'canceled'], true)) {
            return -1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function emptyEffectivenessStats(string $refKey, string $ref, array $extra = []): array
    {
        return [
            $refKey => $ref,
            ...$extra,
            'success_count' => 0,
            'failure_count' => 0,
            'neutral_count' => 0,
            'total_count' => 0,
            'score' => 0,
            'commands' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $bucket
     * @param  array<string,mixed>  $stats
     */
    public static function accumulateEffectivenessStats(array &$bucket, array $stats): void
    {
        foreach (self::EFFECTIVENESS_COUNTER_KEYS as $key) {
            $bucket[$key] = (int) $bucket[$key] + (int) ($stats[$key] ?? 0);
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $items
     * @return array<string,array<string,mixed>>
     */
    public static function finalizeEffectivenessStats(array $items): array
    {
        foreach ($items as $key => $item) {
            $total = max((int) ($item['total_count'] ?? 0), 1);
            $items[$key]['success_rate'] = round((int) ($item['success_count'] ?? 0) / $total, 2);
            $items[$key]['effectiveness'] = match (true) {
                (int) ($item['failure_count'] ?? 0) > 0 && (int) ($item['success_count'] ?? 0) > 0 => 'mixed',
                (int) ($item['failure_count'] ?? 0) > 0 => 'failing',
                (int) ($item['success_count'] ?? 0) > 0 => 'effective',
                default => 'unknown',
            };
        }

        return $items;
    }
}
