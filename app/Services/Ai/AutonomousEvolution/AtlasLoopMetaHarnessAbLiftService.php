<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * L6-1: A/B lift measurement for meta-harness work.
 *
 * This is a read model. It does not generate tasks, change harness files, loosen
 * gates, or promote a meta-loop change. Completion requires real cases in both
 * arms and positive certification-rate lift.
 */
final class AtlasLoopMetaHarnessAbLiftService
{
    public const SCHEMA_VERSION = 'atlas.loop.meta_harness_ab_lift.v1';

    public function __construct(
        private readonly AtlasLoopHarnessGuard $guard,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function measure(array $options = []): array
    {
        $cfg = (array) config('atlas.loop.meta_harness_ab_lift', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $windowHours = max(1, min(2160, (int) ($options['window_hours'] ?? $cfg['window_hours'] ?? 168)));
        $minCases = max(1, (int) ($options['min_cases_per_arm'] ?? $cfg['min_cases_per_arm'] ?? 3));
        $minLift = max(0.0, min(1.0, (float) ($options['min_lift'] ?? $cfg['min_lift'] ?? 0.01)));
        $maxTasks = max(10, min(5000, (int) ($options['max_tasks'] ?? $cfg['max_tasks'] ?? 1000)));

        if (! $enabled) {
            return $this->payload('disabled', [], [], [
                'meta_harness_ab_lift_disabled',
            ], $windowHours, $minCases, $minLift, $maxTasks);
        }

        if (! DatabaseTableAvailability::all(['atlas_loop_tasks', 'atlas_loop_proposals'])) {
            return $this->payload('blocked', [], [], ['loop_runtime_tables_missing'], $windowHours, $minCases, $minLift, $maxTasks);
        }

        try {
            $tasks = DB::table('atlas_loop_tasks')
                ->whereNotNull('target_path')
                ->where('updated_at', '>=', Carbon::now()->subHours($windowHours))
                ->orderByDesc('updated_at')
                ->limit($maxTasks)
                ->get(['id', 'status', 'source', 'target_path', 'result', 'updated_at']);
            $proposalCounts = DB::table('atlas_loop_proposals')
                ->selectRaw('task_id, count(*) as proposals')
                ->whereNotNull('task_id')
                ->groupBy('task_id')
                ->pluck('proposals', 'task_id')
                ->all();
        } catch (Throwable $e) {
            return $this->payload('blocked', [], [], ['query_failed:'.mb_substr($e->getMessage(), 0, 120)], $windowHours, $minCases, $minLift, $maxTasks);
        }

        $arms = [
            'meta_harness' => $this->emptyArm(),
            'ordinary' => $this->emptyArm(),
        ];
        $forbidden = [];
        foreach ($tasks as $task) {
            $path = ltrim(trim((string) ($task->target_path ?? '')), '/');
            if ($path === '') {
                continue;
            }
            if ($this->guard->isForbiddenSelfTarget($path)) {
                $forbidden[$path] = true;

                continue;
            }
            $arm = $this->guard->isHarnessTarget($path) ? 'meta_harness' : 'ordinary';
            $this->recordTask($arms[$arm], (string) $task->id, (string) $task->status, $path, $task->result ?? null, (int) ($proposalCounts[(string) $task->id] ?? 0));
        }

        $arms = array_map(fn (array $arm): array => $this->finalizeArm($arm), $arms);
        $blockers = [];
        foreach (['meta_harness', 'ordinary'] as $arm) {
            if ((int) $arms[$arm]['case_count'] < $minCases) {
                $blockers[] = $arm.'_cases_below_min';
            }
        }
        if ($forbidden !== []) {
            $blockers[] = 'forbidden_self_targets_seen_in_window';
        }

        $lift = null;
        if ($blockers === []) {
            $lift = round((float) $arms['meta_harness']['certification_rate'] - (float) $arms['ordinary']['certification_rate'], 4);
            if ($lift < $minLift) {
                $blockers[] = 'positive_lift_below_floor';
            }
        }

        $status = $blockers === []
            ? 'positive_lift'
            : 'insufficient_live_ab_evidence';

        return $this->payload($status, $arms, array_keys($forbidden), $blockers, $windowHours, $minCases, $minLift, $maxTasks, $lift);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyArm(): array
    {
        return [
            'case_count' => 0,
            'certified_count' => 0,
            'failed_count' => 0,
            'sample_targets' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $arm
     */
    private function recordTask(array &$arm, string $taskId, string $status, string $path, mixed $result, int $proposalCount): void
    {
        if (! in_array($status, ['done', 'failed', 'deferred'], true)) {
            return;
        }

        $result = is_string($result) ? json_decode($result, true) : $result;
        $result = is_array($result) ? $result : [];
        $certified = max(
            $proposalCount,
            (int) data_get($result, 'proposals_certified_for_review', 0),
            (int) data_get($result, 'semantic_implementation_certification.proposals_certified', 0),
        );

        $arm['case_count']++;
        if ($certified > 0) {
            $arm['certified_count']++;
        } else {
            $arm['failed_count']++;
        }
        if (count((array) $arm['sample_targets']) < 5) {
            $arm['sample_targets'][] = [
                'task_id' => $taskId,
                'target_path' => $path,
                'status' => $status,
                'certified' => $certified > 0,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $arm
     * @return array<string,mixed>
     */
    private function finalizeArm(array $arm): array
    {
        $cases = max(0, (int) $arm['case_count']);
        $certified = max(0, (int) $arm['certified_count']);

        return [
            ...$arm,
            'certification_rate' => $cases > 0 ? round($certified / $cases, 4) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $arms
     * @param  list<string>  $forbiddenTargets
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        array $arms,
        array $forbiddenTargets,
        array $blockers,
        int $windowHours,
        int $minCases,
        float $minLift,
        int $maxTasks,
        ?float $lift = null,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => [
                'hours' => $windowHours,
                'min_cases_per_arm' => $minCases,
                'min_lift' => $minLift,
                'max_tasks' => $maxTasks,
            ],
            'arms' => $arms,
            'lift' => [
                'certification_rate_delta' => $lift,
                'positive' => is_float($lift) && $lift >= $minLift,
            ],
            'forbidden_self_targets' => [
                'count' => count($forbiddenTargets),
                'targets' => array_slice($forbiddenTargets, 0, 10),
            ],
            'blockers' => $blockers,
            'completion_claim_allowed' => $status === 'positive_lift' && $blockers === [],
            'claim_policy' => [
                'read_only_measurement' => true,
                'provider_calls_made' => false,
                'workspace_mutated' => false,
                'frozen_judge_targets_allowed' => false,
                'forbidden_self_targets_allowed' => false,
                'requires_positive_live_ab_lift' => true,
            ],
        ];
    }
}
