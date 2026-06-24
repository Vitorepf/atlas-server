<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal;

use Carbon\CarbonInterface;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class AtlasCortexTemporalAxisQueryService
{
    /**
     * @param  null|Closure():DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly object $symbolReporter,
        private readonly object $orphanReporter,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * @param  list<string>  $fqcns
     * @return list<array<string,mixed>>
     */
    public function symbolsTouchedWithin(DateInterval $period, array $fqcns): array
    {
        if ($fqcns === []) {
            return [];
        }

        $cutoff = $this->now()->sub($period);
        $rows = [];
        foreach ($this->sortedFacts($this->symbolFacts($fqcns)) as $fqcn => $row) {
            $lastModifiedAt = $this->timestamp((string) ($row['last_modified_at'] ?? ''));
            if ($lastModifiedAt === null || $lastModifiedAt < $cutoff) {
                continue;
            }

            $rows[] = $row + ['touched_within' => true];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $fqcns
     * @return list<array<string,mixed>>
     */
    public function symbolsUntouchedSince(CarbonInterface $cutoff, array $fqcns): array
    {
        if ($fqcns === []) {
            return [];
        }

        $limit = $this->timestamp($cutoff->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        $rows = [];
        foreach ($this->sortedFacts($this->symbolFacts($fqcns)) as $row) {
            $lastModifiedAt = $this->timestamp((string) ($row['last_modified_at'] ?? ''));
            if ($lastModifiedAt === null || $limit === null || $lastModifiedAt >= $limit) {
                continue;
            }

            $rows[] = $row + ['untouched_since' => true];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $fqcns
     * @return list<array<string,mixed>>
     */
    public function orphansOlderThan(int $days, array $fqcns): array
    {
        if ($fqcns === []) {
            return [];
        }

        $rows = [];
        foreach ($this->sortedFacts($this->orphanFacts($fqcns)) as $row) {
            if (($row['resolved'] ?? false) !== true) {
                continue;
            }

            if ((int) ($row['unwired_days'] ?? -1) <= $days) {
                continue;
            }

            $rows[] = $row + ['orphan_older_than_days' => true];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $fqcns
     * @return list<array<string,mixed>>
     */
    public function symbolsAddedBetween(CarbonInterface $from, CarbonInterface $to, array $fqcns): array
    {
        if ($fqcns === []) {
            return [];
        }

        $fromAt = $this->timestamp($from->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        $toAt = $this->timestamp($to->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        $rows = [];
        foreach ($this->sortedFacts($this->symbolFacts($fqcns)) as $row) {
            $firstSeenAt = $this->timestamp((string) ($row['first_seen_at'] ?? ''));
            if ($firstSeenAt === null || $fromAt === null || $toAt === null) {
                continue;
            }

            if ($firstSeenAt < $fromAt || $firstSeenAt > $toAt) {
                continue;
            }

            $rows[] = $row + ['added_between' => true];
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function modificationHistogramByDay(string $fqcn, int $windowDays): array
    {
        if ($fqcn === '' || $windowDays <= 0) {
            return [];
        }

        $facts = $this->symbolFacts([$fqcn]);
        $row = $facts[$fqcn] ?? null;
        if (! is_array($row) || ($row['resolved'] ?? false) !== true) {
            return [];
        }

        $lastModifiedAt = $this->timestamp((string) ($row['last_modified_at'] ?? ''));
        if ($lastModifiedAt === null) {
            return [];
        }

        $today = $this->now()->setTime(0, 0);
        $rows = [];
        for ($offset = $windowDays - 1; $offset >= 0; $offset--) {
            $day = $today->modify('-'.$offset.' days');
            $rows[] = [
                'fqcn' => $fqcn,
                'day' => $day->format('Y-m-d'),
                'modified_count' => $lastModifiedAt->format('Y-m-d') === $day->format('Y-m-d') ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $fqcns
     * @return array<string,array<string,mixed>>
     */
    private function symbolFacts(array $fqcns): array
    {
        return $this->report($this->symbolReporter, $fqcns);
    }

    /**
     * @param  list<string>  $fqcns
     * @return array<string,array<string,mixed>>
     */
    private function orphanFacts(array $fqcns): array
    {
        return $this->report($this->orphanReporter, $fqcns);
    }

    /**
     * @param  list<string>  $fqcns
     * @return array<string,array<string,mixed>>
     */
    private function report(object $reporter, array $fqcns): array
    {
        if (! method_exists($reporter, 'report')) {
            return [];
        }

        $rows = $reporter->report($fqcns);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @return array<string,array<string,mixed>>
     */
    private function sortedFacts(array $rows): array
    {
        ksort($rows, SORT_STRING);

        return $rows;
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function now(): DateTimeImmutable
    {
        if (is_callable($this->clock)) {
            return ($this->clock)();
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
