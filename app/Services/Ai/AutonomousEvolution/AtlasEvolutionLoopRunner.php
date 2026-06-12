<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * The LOOP RUNNER — the governed autoresearch loop over a queue of tasks.
 *
 * For each metric-shaped task it runs the {@see AtlasEvolutionScenarioExplorer}
 * (explore N candidates, the frozen judge picks the best), and accumulates the
 * winners as PROPOSE-ONLY proposals: it NEVER merges to main. You wake up to a
 * stack of certified-for-review changes, each with its diff, its frozen-judge
 * verdict, and the record of the rejected explorations (the "19 that didn't
 * work"). Hard time/task caps apply the autoresearch fixed-budget discipline.
 *
 * Provider-agnostic — all execution flows through the explorer's
 * {@see LoopExecutionDriver} abstraction; no provider is named here.
 */
final class AtlasEvolutionLoopRunner
{
    public const SCHEMA = 'atlas.evolution.loop_run.v1';

    public const PROPOSAL_SCHEMA = 'atlas.evolution.proposal.v1';

    /** Hard cap on persisted per-attempt records — the audit stays lean by construction. */
    public const ATTEMPT_METRICS_CAP = 24;

    public function __construct(
        private readonly AtlasEvolutionScenarioExplorer $explorer,
    ) {}

    /**
     * Run the loop over a queue of metric-shaped tasks.
     *
     * @param  list<array<string,mixed>>  $tasks
     * @param  array{max_tasks?: int, max_seconds?: int, scenarios_per_task?: int, propose_only?: bool}  $options
     * @return array<string,mixed>  atlas.evolution.loop_run.v1
     */
    public function run(array $tasks, array $options = []): array
    {
        $proposeOnly = (bool) ($options['propose_only'] ?? config('atlas.loop.propose_only', true));
        $maxTasks = max(1, (int) ($options['max_tasks'] ?? (count($tasks) ?: 1)));
        $maxSeconds = max(0, (int) ($options['max_seconds'] ?? 0)); // 0 = no time cap
        $scenarios = isset($options['scenarios_per_task']) ? max(1, (int) $options['scenarios_per_task']) : null;

        $proposals = [];
        $explorations = [];
        $start = microtime(true);
        $stopReason = 'queue_exhausted';

        foreach ($tasks as $task) {
            if (count($explorations) >= $maxTasks) {
                $stopReason = 'max_tasks_reached';
                break;
            }
            if ($maxSeconds > 0 && (microtime(true) - $start) >= $maxSeconds) {
                $stopReason = 'time_budget_reached';
                break;
            }

            $exploration = $this->explorer->explore(is_array($task) ? $task : [], $scenarios);
            $explorations[] = $this->summariseExploration($exploration);

            if (($exploration['winner'] ?? null) !== null) {
                $acceptance = is_array($task) && is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [];
                $proposals[] = $this->toProposal($exploration, $acceptance);
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'propose_only' => $proposeOnly,
            // HARD INVARIANT: the loop never merges to main. It only proposes.
            'merged_to_main' => false,
            'tasks_processed' => count($explorations),
            'proposals_certified_for_review' => count($proposals),
            'stop_reason' => $stopReason,
            'elapsed_seconds' => round(microtime(true) - $start, 1),
            'proposals' => $proposals,
            'explorations' => $explorations,
        ];
    }

    /**
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>
     */
    private function summariseExploration(array $exploration): array
    {
        $attempts = is_array($exploration['attempts'] ?? null) ? $exploration['attempts'] : [];
        $rejected = array_filter($attempts, static fn (array $a): bool => ! (bool) ($a['verdict']['passed'] ?? false));
        $reasons = AiStringListNormalizer::uniqueMappedStrings(
            $rejected,
            static fn (array $a): string => (string) ($a['verdict']['details']['reason'] ?? ''),
        );

        return [
            'objective' => (string) ($exploration['objective'] ?? ''),
            'provider' => (string) ($exploration['provider'] ?? ''),
            'scenarios_explored' => (int) ($exploration['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
            'has_winner' => ($exploration['winner'] ?? null) !== null,
            'rejected_reasons' => $reasons,
            'attempt_metrics' => $this->attemptMetrics($attempts),
        ];
    }

    /**
     * LEAN per-attempt metric records — what each attempt actually scored, not just
     * the counts (AP-820 S3). Deliberately NEVER carries stdout/stderr/diff_text:
     * those are provider-shaped bulk that must not leak into an app-read table.
     * Capped at {@see self::ATTEMPT_METRICS_CAP} entries.
     *
     * @param  list<array<string,mixed>>  $attempts
     * @return list<array{scenario:int|string, passed:bool, metric:float|null, metric_finite:bool, diff_files:int|null, diff_lines:int|null}>
     */
    private function attemptMetrics(array $attempts): array
    {
        $metrics = [];
        foreach (array_slice(array_values($attempts), 0, self::ATTEMPT_METRICS_CAP) as $index => $attempt) {
            if (! is_array($attempt)) {
                continue;
            }
            $verdict = is_array($attempt['verdict'] ?? null) ? $attempt['verdict'] : [];
            $diffSize = is_array($attempt['diff_size'] ?? null) ? $attempt['diff_size'] : [];
            $scenario = $attempt['scenario_id'] ?? null;

            $metrics[] = [
                'scenario' => is_string($scenario) && trim($scenario) !== '' ? $scenario : $index + 1,
                'passed' => (bool) ($verdict['passed'] ?? false),
                'metric' => is_numeric($verdict['metric'] ?? null) ? (float) $verdict['metric'] : null,
                'metric_finite' => (bool) ($verdict['metric_finite'] ?? true),
                'diff_files' => is_numeric($diffSize['files'] ?? null) ? (int) $diffSize['files'] : null,
                'diff_lines' => is_numeric($diffSize['lines'] ?? null) ? (int) $diffSize['lines'] : null,
            ];
        }

        return $metrics;
    }

    /**
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>  atlas.evolution.proposal.v1 (certified_for_review, never merged)
     */
    /**
     * @param  array<string,mixed>  $exploration
     * @param  array<string,mixed>  $acceptance  the FROZEN acceptance contract of the task
     * @return array<string,mixed>
     */
    private function toProposal(array $exploration, array $acceptance = []): array
    {
        $winner = is_array($exploration['winner'] ?? null) ? $exploration['winner'] : [];
        $verdict = is_array($winner['verdict'] ?? null) ? $winner['verdict'] : [];

        $proposal = [
            'schema_version' => self::PROPOSAL_SCHEMA,
            'status' => 'certified_for_review',
            'objective' => (string) ($exploration['objective'] ?? ''),
            'provider' => (string) ($exploration['provider'] ?? ''),
            'winning_scenario' => (string) ($winner['scenario_id'] ?? ''),
            'metric_kind' => (string) ($exploration['metric_kind'] ?? ''),
            'metric' => $verdict['metric'] ?? null,
            'diff_size' => $winner['diff_size'] ?? null,
            'diff_text' => (string) ($winner['diff_text'] ?? ''),
            'scenarios_explored' => (int) ($exploration['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
            'acceptance_hash' => (string) ($verdict['acceptance_hash'] ?? ''),
            // O-3: the FULL frozen acceptance contract travels WITH the proposal so the
            // promotion gate can re-run the real test before merge (the metric column is
            // a numeric verdict, not a re-runnable contract — that was the O-1 #1/#2 gap).
            'acceptance_contract' => $acceptance,
        ];
        $proposal['proposal_hash'] = hash('sha256', (string) json_encode([
            'objective' => $proposal['objective'],
            'diff_text' => $proposal['diff_text'],
            'acceptance_hash' => $proposal['acceptance_hash'],
        ], JSON_THROW_ON_ERROR));

        return $proposal;
    }
}
