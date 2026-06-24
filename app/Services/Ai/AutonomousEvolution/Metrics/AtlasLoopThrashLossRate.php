<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Metrics;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class AtlasLoopThrashLossRate
{
    private const SCHEMA = 'atlas.loop.thrash_loss_rate.v1';

    private const WINDOW_DAYS = 14;

    private const THRASH_WINDOW_SECONDS = 86_400;

    private const PARKS_PER_THRASH = 3;

    private readonly string $projectionOutcomesDir;

    public function __construct(?string $projectionOutcomesDir = null)
    {
        $this->projectionOutcomesDir = $projectionOutcomesDir ?? storage_path('app/atlas/loop/projection-outcomes');
    }

    /**
     * @return array{
     *   schema:string,
     *   window_days:int,
     *   obras_observed:int,
     *   obras_thrashed:int,
     *   rate:float,
     *   top_dominant_reasons:list<array{reason:string,obras:int}>,
     *   computed_at:string
     * }
     */
    public function measure(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $now->sub(new DateInterval('P'.self::WINDOW_DAYS.'D'));

        $byTarget = [];
        foreach ($this->outcomes($cutoff, $now) as $outcome) {
            $target = $this->normaliseTarget($outcome['target']);
            if ($target === '') {
                continue;
            }

            $byTarget[$target][] = $outcome;
        }

        $dominantReasons = [];
        foreach ($byTarget as $outcomes) {
            $reason = $this->dominantThrashReason($outcomes);
            if ($reason === null) {
                continue;
            }

            $dominantReasons[$reason] = ($dominantReasons[$reason] ?? 0) + 1;
        }

        $obrasObserved = count($byTarget);
        $obrasThrashed = array_sum($dominantReasons);

        return [
            'schema' => self::SCHEMA,
            'window_days' => self::WINDOW_DAYS,
            'obras_observed' => $obrasObserved,
            'obras_thrashed' => $obrasThrashed,
            'rate' => $obrasObserved === 0 ? 0.0 : round($obrasThrashed / $obrasObserved, 3),
            'top_dominant_reasons' => $this->topDominantReasons($dominantReasons),
            'computed_at' => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @return list<array{target:mixed,status:mixed,reason:mixed,occurred_at:int}>
     */
    private function outcomes(DateTimeImmutable $cutoff, DateTimeImmutable $now): array
    {
        return array_merge(
            $this->projectionOutcomeFileRows($cutoff, $now),
            $this->originationAttemptRows($cutoff, $now),
        );
    }

    /**
     * @return list<array{target:mixed,status:mixed,reason:mixed,occurred_at:int}>
     */
    private function projectionOutcomeFileRows(DateTimeImmutable $cutoff, DateTimeImmutable $now): array
    {
        if (! is_dir($this->projectionOutcomesDir)) {
            return [];
        }

        try {
            $rows = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->projectionOutcomesDir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $decoded = json_decode((string) @file_get_contents($file->getPathname()), true);
                $outcomes = is_array($decoded) && is_array($decoded['outcomes'] ?? null) ? $decoded['outcomes'] : [];
                foreach ($outcomes as $outcome) {
                    if (! is_array($outcome)) {
                        continue;
                    }

                    $timestamp = $this->timestampFrom($outcome, $file->getMTime());
                    if (! $this->timestampInWindow($timestamp, $cutoff, $now)) {
                        continue;
                    }

                    $rows[] = [
                        'target' => $this->firstScalar($outcome, ['target', 'obra', 'target_path', 'path']),
                        'status' => $this->firstScalar($outcome, ['status', 'outcome', 'state']),
                        'reason' => $this->firstScalar($outcome, ['reason', 'failure_reason', 'terminal_reason']),
                        'occurred_at' => $timestamp,
                    ];
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{target:mixed,status:mixed,reason:mixed,occurred_at:int}>
     */
    private function originationAttemptRows(DateTimeImmutable $cutoff, DateTimeImmutable $now): array
    {
        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            return [];
        }

        $columns = Schema::getColumnListing('atlas_loop_origination_outcomes');
        if (! in_array('payload', $columns, true)) {
            return [];
        }

        $selected = array_values(array_intersect(['payload', 'target_path', 'created_at', 'updated_at'], $columns));
        if ($selected === []) {
            return [];
        }

        try {
            $rows = [];
            foreach (DB::table('atlas_loop_origination_outcomes')->get($selected) as $row) {
                $payload = $this->decodePayload($row->payload ?? null);
                if ($payload === []) {
                    continue;
                }

                $fallbackTarget = $this->firstScalar($payload, ['target', 'obra', 'target_path', 'path'])
                    ?: ($row->target_path ?? null);
                $fallbackTimestamp = $this->timestampFrom([
                    'created_at' => $row->created_at ?? null,
                    'updated_at' => $row->updated_at ?? null,
                ]);

                foreach (['attempt_history', 'rounds'] as $key) {
                    foreach ($this->outcomeList($payload[$key] ?? null) as $attempt) {
                        $timestamp = $this->timestampFrom($attempt, $fallbackTimestamp);
                        if (! $this->timestampInWindow($timestamp, $cutoff, $now)) {
                            continue;
                        }

                        $rows[] = [
                            'target' => $this->firstScalar($attempt, ['target', 'obra', 'target_path', 'path']) ?: $fallbackTarget,
                            'status' => $this->firstScalar($attempt, ['status', 'outcome', 'state', 'terminal_status']),
                            'reason' => $this->firstScalar($attempt, ['reason', 'failure_reason', 'terminal_reason']),
                            'occurred_at' => $timestamp,
                        ];
                    }
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{target:mixed,status:mixed,reason:mixed,occurred_at:int}>  $outcomes
     */
    private function dominantThrashReason(array $outcomes): ?string
    {
        $byReason = [];
        foreach ($outcomes as $outcome) {
            if ($this->normaliseReason($outcome['status']) !== 'parked') {
                continue;
            }

            $reason = $this->normaliseReason($outcome['reason']);
            if ($reason === '') {
                continue;
            }

            $byReason[$reason][] = $outcome['occurred_at'];
        }

        $triggered = [];
        foreach ($byReason as $reason => $timestamps) {
            sort($timestamps);
            if ($this->hasThreeParksInWindow($timestamps)) {
                $triggered[$reason] = count($timestamps);
            }
        }

        if ($triggered === []) {
            return null;
        }

        uksort($triggered, static fn (string $a, string $b): int => ($triggered[$b] <=> $triggered[$a]) ?: strcmp($a, $b));

        return (string) array_key_first($triggered);
    }

    /**
     * @param  list<int>  $timestamps
     */
    private function hasThreeParksInWindow(array $timestamps): bool
    {
        $left = 0;
        foreach ($timestamps as $right => $timestamp) {
            while ($left < $right && $timestamp - $timestamps[$left] > self::THRASH_WINDOW_SECONDS) {
                $left++;
            }

            if (($right - $left + 1) >= self::PARKS_PER_THRASH) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,int>  $dominantReasons
     * @return list<array{reason:string,obras:int}>
     */
    private function topDominantReasons(array $dominantReasons): array
    {
        $rows = [];
        foreach ($dominantReasons as $reason => $obras) {
            $rows[] = ['reason' => $reason, 'obras' => $obras];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['obras'] <=> $a['obras']) ?: strcmp($a['reason'], $b['reason']));

        return array_slice($rows, 0, 5);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function outcomeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            return $this->looksLikeOutcome($value) ? [$value] : [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_array($item) && $this->looksLikeOutcome($item)));
    }

    /**
     * Numeric/scalar `rounds` summaries are intentionally ignored: thrash must come from outcome rows.
     */
    private function looksLikeOutcome(array $value): bool
    {
        return $this->firstScalar($value, ['status', 'outcome', 'state', 'terminal_status']) !== null
            && $this->firstScalar($value, ['reason', 'failure_reason', 'terminal_reason']) !== null;
    }

    private function timestampFrom(array $value, ?int $fallback = null): ?int
    {
        foreach (['ts', 'at', 'occurred_at', 'created_at', 'updated_at', 'timestamp'] as $key) {
            $timestamp = $this->parseTimestamp($value[$key] ?? null);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return $fallback;
    }

    private function parseTimestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value) || is_float($value)) {
            $timestamp = (int) $value;

            return $timestamp > 9_999_999_999 ? (int) floor($timestamp / 1000) : $timestamp;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            $timestamp = (int) $value;

            return $timestamp > 9_999_999_999 ? (int) floor($timestamp / 1000) : $timestamp;
        }

        try {
            return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function timestampInWindow(?int $timestamp, DateTimeImmutable $cutoff, DateTimeImmutable $now): bool
    {
        return $timestamp !== null
            && $timestamp >= $cutoff->getTimestamp()
            && $timestamp <= $now->getTimestamp();
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstScalar(array $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && is_scalar($value[$key])) {
                return $value[$key];
            }
        }

        return null;
    }

    private function normaliseTarget(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return strtolower(ltrim(trim((string) $value), '/'));
    }

    private function normaliseReason(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return (string) preg_replace('/\s+/', ' ', strtolower(trim((string) $value)));
    }
}
