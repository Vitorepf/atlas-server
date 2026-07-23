<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * I/O allowed: builds world snapshot fail-open (safe defaults if data missing).
 */
final class AaeosWorldSnapshotBuilder
{
    /**
     * @param  array<string,mixed>  $overrides
     */
    public function build(array $overrides = []): AaeosWorldSnapshot
    {
        $config = [];
        try {
            if (function_exists('config')) {
                $config = (array) config('atlas.aaeos.world', []);
            }
        } catch (Throwable) {
            $config = [];
        }

        $base = [
            'incident_open' => (bool) ($config['incident_open'] ?? false),
            'queue_depth' => (int) ($config['queue_depth'] ?? 0),
            'budget_pressure' => (float) ($config['budget_pressure'] ?? 0.0),
            'recent_failure_count' => (int) ($config['recent_failure_count'] ?? 0),
            'world_source' => 'config_defaults',
            'autonomos_queue_healthy' => true,
        ];

        $live = $this->probeLiveSignals();
        $merged = array_merge($base, $live, $overrides);
        if (($live['world_source'] ?? null) !== null && ! array_key_exists('world_source', $overrides)) {
            $merged['world_source'] = $live['world_source'];
        }

        return AaeosWorldSnapshot::fromArray($merged);
    }

    /**
     * @return array<string,mixed>
     */
    private function probeLiveSignals(): array
    {
        $out = [];
        $sources = [];

        try {
            if (! class_exists(Schema::class) || ! Schema::hasTable('atlas_task_packets') && ! Schema::hasTable('atlas_self_construction_tasks')) {
                // try common serving tables fail-open
            }

            $queueDepth = $this->countQueueDepth();
            if ($queueDepth !== null) {
                $out['queue_depth'] = $queueDepth;
                $sources[] = 'task_queue';
            }

            $failures = $this->countRecentFailures();
            if ($failures !== null) {
                $out['recent_failure_count'] = $failures;
                $sources[] = 'landings_or_jobs';
            }
        } catch (Throwable) {
            $sources[] = 'probe_error_fail_open';
        }

        $out['world_source'] = $sources === [] ? 'config_defaults' : implode('+', $sources);
        $out['autonomos_queue_healthy'] = (($out['queue_depth'] ?? 0) < 500);

        return $out;
    }

    private function countQueueDepth(): ?int
    {
        try {
            if (! class_exists(Schema::class)) {
                return null;
            }
            foreach ([
                ['atlas_task_packets', "status in ('ready','queued','claimable','open')"],
                ['atlas_self_construction_task_packets', "status in ('ready','queued','claimable')"],
                ['ai_jobs', "status in ('pending','queued')"],
            ] as [$table, $where]) {
                if (Schema::hasTable($table)) {
                    try {
                        return (int) DB::table($table)->whereRaw($where)->count();
                    } catch (Throwable) {
                        return (int) DB::table($table)->count();
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function countRecentFailures(): ?int
    {
        try {
            if (! class_exists(Schema::class)) {
                return null;
            }
            $since = now()->subHours(24);
            foreach ([
                ['atlas_task_landings', 'status', ['failed', 'failure', 'error']],
                ['ai_jobs', 'status', ['failed', 'error']],
            ] as [$table, $col, $vals]) {
                if (Schema::hasTable($table)) {
                    $q = DB::table($table)->whereIn($col, $vals);
                    if (Schema::hasColumn($table, 'updated_at')) {
                        $q->where('updated_at', '>=', $since);
                    } elseif (Schema::hasColumn($table, 'created_at')) {
                        $q->where('created_at', '>=', $since);
                    }

                    return (int) $q->count();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
