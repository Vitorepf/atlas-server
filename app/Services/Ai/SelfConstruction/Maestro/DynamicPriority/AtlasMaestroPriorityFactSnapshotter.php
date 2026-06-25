<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\DynamicPriority;

/**
 * FACT-only, provider-free priority-driver snapshotter.
 *
 * Reads three signals via INJECTED callables:
 *   - pendingPacketsSource(): list<{task_packet_id, tags[], depends_on[]}>
 *   - leaseHistorySource(): list<{started_at_ms, completed_at_ms, worker_id}>
 *   - currentInFlightSource(): list<{started_at_ms, worker_id}>
 *
 * Emits one append-only JSONL row per snapshot into the configured path. Gated by
 * `config('atlas.loop.master_enabled')`: OFF ⇒ byte-identical no-op (no writes, no reads).
 *
 * NO scoring, NO floats-as-weights — only counts and integers.
 */
final class AtlasMaestroPriorityFactSnapshotter
{
    public const SCHEMA = 'atlas.maestro.priority_fact_snapshot.v1';

    /** @var callable(): list<array<string,mixed>> */
    private $pendingPacketsSource;

    /** @var callable(): list<array<string,mixed>> */
    private $leaseHistorySource;

    /** @var callable(): list<array<string,mixed>> */
    private $currentInFlightSource;

    /** @var callable(): int  nanosecond clock */
    private $clockNs;

    public function __construct(
        callable $pendingPacketsSource,
        callable $leaseHistorySource,
        callable $currentInFlightSource,
        private readonly string $snapshotsPath,
        ?callable $clockNs = null,
    ) {
        $this->pendingPacketsSource = $pendingPacketsSource;
        $this->leaseHistorySource = $leaseHistorySource;
        $this->currentInFlightSource = $currentInFlightSource;
        $this->clockNs = $clockNs ?? static fn (): int => hrtime(true);

        $dir = dirname($this->snapshotsPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        if (! $this->masterSwitchOn()) {
            return [
                'schema' => self::SCHEMA,
                'disabled' => true,
                'reason' => 'master_switch_off',
            ];
        }

        $packets = ($this->pendingPacketsSource)();
        $leases = ($this->leaseHistorySource)();
        $inFlight = ($this->currentInFlightSource)();

        $queueDepthByTag = $this->countByTag($packets);
        $idlePredictionMs = $this->predictIdleMs($leases, $inFlight);
        $criticality = $this->dependencyCriticality($packets);

        $row = [
            'schema' => self::SCHEMA,
            'taken_at_ns' => (int) ($this->clockNs)(),
            'facts' => [
                'queue_depth_by_tag' => $queueDepthByTag,
                'worker_idle_prediction_ms' => $idlePredictionMs,
                'dependency_criticality_by_task_id' => $criticality,
            ],
        ];

        $this->appendRow($row);

        return $row;
    }

    private function masterSwitchOn(): bool
    {
        if (function_exists('config')) {
            $val = config('atlas.loop.master_enabled');
            if ($val !== null) {
                return (bool) $val;
            }
        }
        $env = getenv('ATLAS_LOOP_MASTER_ENABLED');

        return $env === false ? true : in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,int>
     */
    private function countByTag(array $packets): array
    {
        $counts = [];
        foreach ($packets as $p) {
            foreach ((array) ($p['tags'] ?? []) as $tag) {
                $tag = (string) $tag;
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Simple integer-only prediction:
     *   median(completed_lease_durations) − median(in_flight_age) clipped at 0.
     *
     * @param  list<array<string,mixed>>  $leases
     * @param  list<array<string,mixed>>  $inFlight
     */
    private function predictIdleMs(array $leases, array $inFlight): int
    {
        if ($leases === []) {
            return 0;
        }
        $durations = [];
        foreach ($leases as $l) {
            $started = (int) ($l['started_at_ms'] ?? 0);
            $completed = (int) ($l['completed_at_ms'] ?? 0);
            if ($completed > $started) {
                $durations[] = $completed - $started;
            }
        }
        if ($durations === []) {
            return 0;
        }
        sort($durations, SORT_NUMERIC);
        $medianDuration = $durations[(int) (count($durations) / 2)];

        if ($inFlight === []) {
            return (int) $medianDuration;
        }
        $now = max(array_column($leases, 'completed_at_ms') ?: [0]);
        $ages = [];
        foreach ($inFlight as $row) {
            $age = (int) $now - (int) ($row['started_at_ms'] ?? 0);
            $ages[] = max(0, $age);
        }
        sort($ages, SORT_NUMERIC);
        $medianAge = $ages[(int) (count($ages) / 2)];

        return (int) max(0, $medianDuration - $medianAge);
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,int>  packet_id => number of pending packets that depend on it
     */
    private function dependencyCriticality(array $packets): array
    {
        $criticality = [];
        foreach ($packets as $p) {
            $id = (string) ($p['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $criticality[$id] = $criticality[$id] ?? 0;
        }
        foreach ($packets as $p) {
            foreach ((array) ($p['depends_on'] ?? []) as $dep) {
                $dep = (string) $dep;
                if ($dep === '') {
                    continue;
                }
                $criticality[$dep] = ($criticality[$dep] ?? 0) + 1;
            }
        }
        ksort($criticality, SORT_STRING);

        return $criticality;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendRow(array $row): void
    {
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->snapshotsPath, 'a');
        if ($handle === false) {
            return;
        }
        try {
            fwrite($handle, $line."\n");
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }
}
