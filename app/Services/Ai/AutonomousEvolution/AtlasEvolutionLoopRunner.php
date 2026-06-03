<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

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
                $proposals[] = $this->toProposal($exploration);
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
        $reasons = array_values(array_unique(array_filter(array_map(
            static fn (array $a): string => (string) ($a['verdict']['details']['reason'] ?? ''),
            $rejected,
        ), static fn (string $r): bool => $r !== '')));

        return [
            'objective' => (string) ($exploration['objective'] ?? ''),
            'provider' => (string) ($exploration['provider'] ?? ''),
            'scenarios_explored' => (int) ($exploration['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
            'has_winner' => ($exploration['winner'] ?? null) !== null,
            'rejected_reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>  atlas.evolution.proposal.v1 (certified_for_review, never merged)
     */
    private function toProposal(array $exploration): array
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
        ];
        $proposal['proposal_hash'] = hash('sha256', (string) json_encode([
            'objective' => $proposal['objective'],
            'diff_text' => $proposal['diff_text'],
            'acceptance_hash' => $proposal['acceptance_hash'],
        ], JSON_THROW_ON_ERROR));

        return $proposal;
    }
}
