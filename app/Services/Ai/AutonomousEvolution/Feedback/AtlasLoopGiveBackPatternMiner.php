<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Feedback;

final class AtlasLoopGiveBackPatternMiner
{
    public function __construct(
        private readonly object $reader = new AtlasLoopGiveBackReader,
    ) {}

    /**
     * @return array{
     *   top_reasons:list<array{reason:string,count:int}>,
     *   give_back_rate_by_class:array<string,float>,
     *   top_reason_by_class:array<string,string>,
     *   worker_concentration:array<string,int>
     * }
     */
    public function mine(int $limit = 200): array
    {
        $records = $this->recentOutcomes($limit);
        $reasonCounts = [];
        $classTotals = [];
        $classGiveBacks = [];
        $classReasonCounts = [];
        $workerCounts = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $packetClass = trim((string) ($record['packet_class'] ?? ''));
            $outcome = trim((string) ($record['outcome'] ?? ''));
            $reason = trim((string) ($record['reason'] ?? ''));
            $worker = trim((string) ($record['worker'] ?? ''));

            if ($packetClass === '' || $outcome === '') {
                continue;
            }

            $classTotals[$packetClass] = ($classTotals[$packetClass] ?? 0) + 1;

            if ($outcome !== 'give_back') {
                continue;
            }

            $reason = $reason !== '' ? $reason : 'unknown';
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $classGiveBacks[$packetClass] = ($classGiveBacks[$packetClass] ?? 0) + 1;
            $classReasonCounts[$packetClass][$reason] = ($classReasonCounts[$packetClass][$reason] ?? 0) + 1;

            if ($worker !== '') {
                $workerCounts[$worker] = ($workerCounts[$worker] ?? 0) + 1;
            }
        }

        ksort($classTotals);
        ksort($classReasonCounts);

        $topReasons = $this->sortedReasonFacts($reasonCounts);
        $giveBackRateByClass = [];
        foreach ($classTotals as $packetClass => $total) {
            $giveBackRateByClass[$packetClass] = $total > 0
                ? round((float) (($classGiveBacks[$packetClass] ?? 0) / $total), 6)
                : 0.0;
        }

        $topReasonByClass = [];
        foreach ($classReasonCounts as $packetClass => $counts) {
            $topReasonByClass[$packetClass] = $this->topReason($counts);
        }

        $workerConcentration = $this->sortedWorkerCounts($workerCounts);

        return [
            'top_reasons' => $topReasons,
            'give_back_rate_by_class' => $giveBackRateByClass,
            'top_reason_by_class' => $topReasonByClass,
            'worker_concentration' => $workerConcentration,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOutcomes(int $limit): array
    {
        if (method_exists($this->reader, 'recentOutcomes')) {
            $result = $this->reader->recentOutcomes($limit);

            return is_array($result) ? array_values($result) : [];
        }

        if (method_exists($this->reader, 'recent')) {
            $result = $this->reader->recent($limit);

            return is_array($result) ? array_values($result) : [];
        }

        if (method_exists($this->reader, 'read')) {
            $result = $this->reader->read($limit);

            return is_array($result) ? array_values($result) : [];
        }

        return [];
    }

    /**
     * @param  array<string,int>  $reasonCounts
     * @return list<array{reason:string,count:int}>
     */
    private function sortedReasonFacts(array $reasonCounts): array
    {
        $rows = [];
        foreach ($reasonCounts as $reason => $count) {
            $rows[] = [
                'reason' => $reason,
                'count' => $count,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $countOrder = $right['count'] <=> $left['count'];
            if ($countOrder !== 0) {
                return $countOrder;
            }

            return strcmp($left['reason'], $right['reason']);
        });

        return $rows;
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function topReason(array $counts): string
    {
        $rows = $this->sortedReasonFacts($counts);

        return (string) ($rows[0]['reason'] ?? '');
    }

    /**
     * @param  array<string,int>  $workerCounts
     * @return array<string,int>
     */
    private function sortedWorkerCounts(array $workerCounts): array
    {
        $rows = [];
        foreach ($workerCounts as $worker => $count) {
            $rows[] = [
                'worker' => $worker,
                'count' => $count,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $countOrder = $right['count'] <=> $left['count'];
            if ($countOrder !== 0) {
                return $countOrder;
            }

            return strcmp($left['worker'], $right['worker']);
        });

        $sorted = [];
        foreach ($rows as $row) {
            $sorted[$row['worker']] = $row['count'];
        }

        return $sorted;
    }
}
