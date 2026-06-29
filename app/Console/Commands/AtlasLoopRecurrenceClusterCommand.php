<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis\AtlasCortexInsightObserverAxisRecurrenceCluster;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasCortexInsightObserverAxisRecurrenceCluster::observe()} at the operator surface:
 * runs the structural recurrence-cluster observer over historical orphan snapshots and emits the recurring_units
 * — organs orphaned across 3+ snapshots (the chronic backlog) — as deterministic facts.
 *
 * Pure + read-only: it observes and reports; it mutates nothing. Nothing recurring ⇒ an empty observation.
 */
final class AtlasLoopRecurrenceClusterCommand extends Command
{
    protected $signature = 'atlas:loop:recurrence-cluster {--snapshots=} {--json}';

    protected $description = 'Read-only recurrence-cluster observation (organs orphaned across 3+ snapshots).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('snapshots'));
        if ($raw === '') {
            return $this->refuse('recurrence-cluster requires --snapshots=<JSON array of snapshots or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--snapshots must be a JSON array/object');
        }
        $snapshots = isset($decoded['history_orphan_snapshots']) && is_array($decoded['history_orphan_snapshots'])
            ? $decoded['history_orphan_snapshots']
            : (isset($decoded['snapshots']) && is_array($decoded['snapshots']) ? $decoded['snapshots'] : $decoded);
        if (! array_is_list($snapshots)) {
            return $this->refuse('--snapshots must be a JSON array of orphan snapshots');
        }

        $observation = app(AtlasCortexInsightObserverAxisRecurrenceCluster::class)
            ->observe(['history_orphan_snapshots' => array_values($snapshots)]);

        $facts = [
            'schema' => 'atlas.loop.recurrence_cluster.v1',
            'observed' => $observation !== [],
            'recurring_units' => array_values((array) ($observation['witnesses'] ?? [])),
            'observation' => $observation,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('observed: '.($facts['observed'] ? 'yes' : 'no').'  recurring_units: '.implode(', ', $facts['recurring_units']));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
