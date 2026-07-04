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

    /** @var list<string> */
    private const REQUIRED_FACT_ROW_FIELDS = ['claimable_depth', 'active_workers', 'telemetry_confidence'];

    private ?string $factLogPathOverride = null;

    public function setFactLogPathForTesting(?string $path): void
    {
        $this->factLogPathOverride = $path;
    }

    public function factLogPath(): string
    {
        return $this->factLogPathOverride
            ?? storage_path('app/atlas/self-construction/agent-control-plane/maestro-workload-projection-facts.jsonl');
    }

    /**
     * Append-only, history-ready workload projection fact row — no overwrite, ever. Each row is
     * a FACT only: claimable_depth, active_workers, telemetry_confidence and generated_at. Rejects
     * (without appending) when any required field is missing, so atlas:task:maestro-projection
     * history never gains a row it can't trust.
     *
     * @param  array{claimable_depth?: int, active_workers?: int, telemetry_confidence?: float}  $input
     * @return array{schema:string, accepted:bool, missing_fields:list<string>, row:?array<string,mixed>}
     */
    public function emitFactRow(array $input): array
    {
        $missingFields = array_values(array_filter(
            self::REQUIRED_FACT_ROW_FIELDS,
            static fn (string $field): bool => ! array_key_exists($field, $input) || $input[$field] === null,
        ));

        if ($missingFields !== []) {
            return [
                'schema' => self::SCHEMA,
                'accepted' => false,
                'missing_fields' => $missingFields,
                'row' => null,
            ];
        }

        $row = [
            'schema' => self::SCHEMA,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'claimable_depth' => (int) $input['claimable_depth'],
            'active_workers' => (int) $input['active_workers'],
            'telemetry_confidence' => (float) $input['telemetry_confidence'],
        ];

        try {
            $path = $this->factLogPath();
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Append-only fact emission must never break the projection caller; a failed write is
            // itself a (silent) signal, not a reason to throw.
        }

        return [
            'schema' => self::SCHEMA,
            'accepted' => true,
            'missing_fields' => [],
            'row' => $row,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function factHistory(int $tail = 200): array
    {
        $path = $this->factLogPath();
        if (! is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -max(1, $tail));
        $out = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

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

    /**
     * Non-vanity projection: separates actionable drain/quality signals from vanity volume metrics.
     *
     * Actionable facts: drain_rate, give_back_rate, starvation_horizon, quality_confidence.
     * Vanity metrics: raw task count, queue depth — unless paired with outcome or risk context.
     *
     * @param  array{drain_rate?:float, give_back_rate?:float, starvation_horizon_hours?:float, quality_confidence?:float, raw_task_count?:int, queue_depth?:int, outcome_context?:bool, risk_context?:bool}  $input
     * @return array{projection_facts:array<string,mixed>, actionable_fact_keys:list<string>, skipped_vanity_metrics:list<string>, freshness_status:string}
     */
    public function nonVanityProjection(array $input): array
    {
        $projectionFacts = [];
        $actionableFactKeys = [];
        $skippedVanityMetrics = [];

        // Actionable facts — always included
        $actionableKeys = ['drain_rate', 'give_back_rate', 'starvation_horizon_hours', 'quality_confidence'];
        foreach ($actionableKeys as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $projectionFacts[$key] = $input[$key];
                $actionableFactKeys[] = $key;
            }
        }

        // Vanity metrics — only included if paired with outcome or risk context
        $vanityKeys = ['raw_task_count', 'queue_depth'];
        $hasOutcomeContext = (bool) ($input['outcome_context'] ?? false);
        $hasRiskContext = (bool) ($input['risk_context'] ?? false);

        foreach ($vanityKeys as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                if ($hasOutcomeContext || $hasRiskContext) {
                    $projectionFacts[$key] = $input[$key];
                    $actionableFactKeys[] = $key;
                } else {
                    $skippedVanityMetrics[] = $key;
                }
            }
        }

        // Determine freshness status
        if (count($actionableFactKeys) >= 3) {
            $freshnessStatus = 'fresh';
        } elseif (count($actionableFactKeys) >= 1) {
            $freshnessStatus = 'partial';
        } else {
            $freshnessStatus = 'stale';
        }

        return [
            'projection_facts' => $projectionFacts,
            'actionable_fact_keys' => $actionableFactKeys,
            'skipped_vanity_metrics' => $skippedVanityMetrics,
            'freshness_status' => $freshnessStatus,
        ];
    }
}
