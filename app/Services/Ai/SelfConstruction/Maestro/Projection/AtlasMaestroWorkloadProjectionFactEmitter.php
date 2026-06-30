<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

use Carbon\CarbonImmutable;

final class AtlasMaestroWorkloadProjectionFactEmitter
{
    public const SCHEMA = 'atlas.maestro.projection.time_to_empty.v1';

    public const RISK_HEALTHY = 'healthy';
    public const RISK_LOW_BUFFER = 'low_buffer';
    public const RISK_REPLENISH_NOW = 'replenish_now';

    /** Queue drains in fewer than this many hours → replenish_now. */
    public const HORIZON_REPLENISH_HOURS = 1.0;
    /** Queue drains in fewer than this many hours → low_buffer. */
    public const HORIZON_LOW_BUFFER_HOURS = 4.0;

    /**
     * @param  array<string,mixed>  $consumptionRateFact
     * @param  array<string,mixed>  $registrySnapshot
     * @return array<string,mixed>
     */
    public function emit(array $consumptionRateFact, array $registrySnapshot, CarbonImmutable $now): array
    {
        $packets = array_values(array_filter((array) ($registrySnapshot['packets'] ?? []), 'is_array'));
        $queueRemainingCount = $this->countQueueRemaining($packets);
        $fleetRate = $this->fleetTasksPerHour($consumptionRateFact);

        $queueEmptyInHours = null;
        $queueEmptyAtIso = null;
        $reason = null;

        if ($fleetRate > 0.0) {
            $queueEmptyInHours = $queueRemainingCount / $fleetRate;
            $queueEmptyAtIso = $this->plusHours($now, $queueEmptyInHours)->toIso8601String();
        } else {
            $reason = 'insufficient_throughput';
        }

        $risk = $this->classifyRisk($queueEmptyInHours);
        $bottleneckReason = match (true) {
            $reason === 'insufficient_throughput' => 'queue_not_draining:throughput=zero',
            $risk === self::RISK_REPLENISH_NOW => sprintf('queue_exhausts_before_replenish_horizon:hours=%.4f,threshold=%.4f', $queueEmptyInHours, self::HORIZON_REPLENISH_HOURS),
            $risk === self::RISK_LOW_BUFFER => sprintf('queue_low_buffer:hours=%.4f,threshold=%.4f', $queueEmptyInHours, self::HORIZON_LOW_BUFFER_HOURS),
            default => null,
        };

        return [
            'schema' => self::SCHEMA,
            'observed_at_iso' => $now->toIso8601String(),
            'queue_remaining_count' => $queueRemainingCount,
            'queue_empty_in_hours' => $queueEmptyInHours,
            'queue_empty_at_iso' => $queueEmptyAtIso,
            'reason' => $reason,
            'risk' => $risk,
            'bottleneck_reason' => $bottleneckReason,
            'per_worker' => $this->perWorkerRows($consumptionRateFact, $packets, $now),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return list<array<string,mixed>>
     */
    private function perWorkerRows(array $consumptionRateFact, array $packets, CarbonImmutable $now): array
    {
        $rows = [];
        foreach (array_values(array_filter((array) ($consumptionRateFact['rows'] ?? []), 'is_array')) as $row) {
            $clientId = trim((string) ($row['client_id'] ?? ''));
            if ($clientId === '' || $clientId === 'fleet') {
                continue;
            }

            $tasksPerHour = (float) ($row['tasks_per_hour'] ?? 0.0);
            $remainingClaimable = $this->countClaimableForWorker($packets, $clientId);
            $idleInHours = null;
            $idleAtIso = null;

            if ($tasksPerHour > 0.0) {
                $idleInHours = $remainingClaimable / $tasksPerHour;
                $idleAtIso = $this->plusHours($now, $idleInHours)->toIso8601String();
            }

            $rows[] = [
                'client_id' => $clientId,
                'worker_idle_in_hours' => $idleInHours,
                'worker_idle_at_iso' => $idleAtIso,
                'risk' => $this->classifyRisk($idleInHours),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp((string) $left['client_id'], (string) $right['client_id'])
        );

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     */
    private function countQueueRemaining(array $packets): int
    {
        $count = 0;
        foreach ($packets as $packet) {
            $status = (string) ($packet['status'] ?? '');
            if (in_array($status, ['queued', 'claimable'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     */
    private function countClaimableForWorker(array $packets, string $clientId): int
    {
        $count = 0;
        foreach ($packets as $packet) {
            if ((string) ($packet['status'] ?? '') !== 'claimable') {
                continue;
            }

            if ((string) ($packet['metadata']['client_id'] ?? $packet['client_id'] ?? '') !== $clientId) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function fleetTasksPerHour(array $consumptionRateFact): float
    {
        foreach (array_values(array_filter((array) ($consumptionRateFact['rows'] ?? []), 'is_array')) as $row) {
            if ((string) ($row['client_id'] ?? '') === 'fleet') {
                return (float) ($row['tasks_per_hour'] ?? 0.0);
            }
        }

        return 0.0;
    }

    private function plusHours(CarbonImmutable $now, float $hours): CarbonImmutable
    {
        return $now->addSeconds((int) round($hours * 3600));
    }

    private function classifyRisk(?float $hoursUntilEmpty): string
    {
        if ($hoursUntilEmpty === null || $hoursUntilEmpty < self::HORIZON_REPLENISH_HOURS) {
            return self::RISK_REPLENISH_NOW;
        }
        if ($hoursUntilEmpty < self::HORIZON_LOW_BUFFER_HOURS) {
            return self::RISK_LOW_BUFFER;
        }

        return self::RISK_HEALTHY;
    }
}
