<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pure classify / missing-impl guess / worker-shape aggregation for give-back learning.
 *
 * No FS, DI, or I/O — used by
 * {@see \App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricGiveBackLearningIntegrator}
 * which keeps packet grouping, recommendation policy, chain hints and policy updates.
 */
final class TaskFabricGiveBackLearningSupport
{
    private function __construct()
    {
    }

    /**
     * Failure class from reason + blocking deficiencies (priority order baked into matchers).
     *
     * @param  list<string>  $defs
     */
    public static function classify(string $reason, array $defs): string
    {
        $haystack = strtolower($reason.' '.implode(' ', $defs));
        if (preg_match('/cli_clobber|forbidden_core|petreo|pétreo/i', $haystack)) {
            return 'cli_clobber_or_petreo';
        }
        if (preg_match('/contradict|goodhart|scalar_score|impossible_accept/i', $haystack)) {
            return 'contradictory_acceptance';
        }
        if (preg_match('/scope_repair|missing_impl|missing_file|allowed_files_missing/i', $haystack)) {
            return 'scope_repair_missing_impl';
        }

        return 'generic';
    }

    /**
     * First named missing-impl path found in blocking_deficiencies, if any.
     *
     * @param  array<string,mixed>  $event
     */
    public static function guessMissingImpl(array $event): ?string
    {
        $defs = is_array($event['blocking_deficiencies'] ?? null) ? array_map('strval', $event['blocking_deficiencies']) : [];
        foreach ($defs as $d) {
            if (preg_match('/missing_impl(?:_file)?:?\s*([^\s,]+)/i', $d, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Group events by (task_shape, worker_client_id) and emit deterministic learning facts.
     * Skips events where both task_shape and worker_client_id are absent.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array{task_shape:string, worker_client_id:string, give_back_count:int, quarantine_count:int, success_count:int}>
     */
    public static function buildWorkerShapeLearning(array $events, int $quarantineThreshold): array
    {
        $groups = [];
        foreach ($events as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $taskShape = (string) ($ev['task_shape'] ?? '');
            $workerId = (string) ($ev['worker_client_id'] ?? '');
            if ($taskShape === '' && $workerId === '') {
                continue;
            }
            $key = $taskShape.'||'.$workerId;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'task_shape'       => $taskShape,
                    'worker_client_id' => $workerId,
                    'give_back_count'  => 0,
                    'quarantine_count' => 0,
                    'success_count'    => 0,
                ];
            }
            $outcome = (string) ($ev['outcome'] ?? 'give_back');
            if ($outcome === 'success') {
                $groups[$key]['success_count']++;
            } else {
                $groups[$key]['give_back_count']++;
                if ((int) ($ev['give_back_count'] ?? 1) >= $quarantineThreshold) {
                    $groups[$key]['quarantine_count']++;
                }
            }
        }
        ksort($groups);

        return array_values($groups);
    }
}
