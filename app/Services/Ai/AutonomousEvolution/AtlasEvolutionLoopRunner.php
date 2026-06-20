<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use Throwable;

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
     * @param  array{max_tasks?: int, max_seconds?: int, scenarios_per_task?: int, propose_only?: bool, progress_callback?: callable}  $options
     * @return array<string,mixed>  atlas.evolution.loop_run.v1
     */
    public function run(array $tasks, array $options = []): array
    {
        $proposeOnly = (bool) ($options['propose_only'] ?? config('atlas.loop.propose_only', true));
        $maxTasks = max(1, (int) ($options['max_tasks'] ?? (count($tasks) ?: 1)));
        $maxSeconds = max(0, (int) ($options['max_seconds'] ?? 0)); // 0 = no time cap
        $scenarios = isset($options['scenarios_per_task']) ? max(1, (int) $options['scenarios_per_task']) : null;
        $onProgress = is_callable($options['progress_callback'] ?? null) ? $options['progress_callback'] : null;

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

            $taskPayload = is_array($task) ? $task : [];
            $this->emitProgress($onProgress, 'runner_task_start', ['index' => count($explorations)]);
            $exploration = $this->explorer->explore($taskPayload, $scenarios, $onProgress);
            $this->emitProgress($onProgress, 'runner_task_end', ['index' => count($explorations)]);
            $explorations[] = $this->summariseExploration($exploration);

            if (is_array($exploration['winner'] ?? null)) {
                $acceptance = is_array($taskPayload['acceptance'] ?? null) ? $taskPayload['acceptance'] : [];
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
     * @param  array<string,mixed>  $context
     */
    private function emitProgress(?callable $onProgress, string $stage, array $context = []): void
    {
        if ($onProgress === null) {
            return;
        }

        try {
            $onProgress(['stage' => $stage] + $context);
        } catch (Throwable) {
            // Liveness callbacks are advisory; they must never change loop correctness.
        }
    }

    /**
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>
     */
    private function summariseExploration(array $exploration): array
    {
        $attempts = is_array($exploration['attempts'] ?? null) ? $exploration['attempts'] : [];
        $attempts = array_values(array_filter($attempts, static fn ($attempt): bool => is_array($attempt)));
        $rejected = array_filter($attempts, static fn (array $a): bool => ! (bool) ($a['verdict']['passed'] ?? false));
        $reasons = AiStringListNormalizer::uniqueMappedStrings(
            $rejected,
            static fn (array $a): string => (string) ($a['verdict']['details']['reason'] ?? ''),
        );
        $costSamples = array_values(array_filter(
            array_map(static fn (array $a): ?float => is_numeric($a['cost_estimate_usd'] ?? null) ? (float) $a['cost_estimate_usd'] : null, $attempts),
            static fn (?float $cost): bool => $cost !== null && $cost > 0.0,
        ));
        $tokensUsed = array_values(array_filter(
            array_map(static fn (array $a): ?int => is_numeric($a['tokens_used'] ?? null) ? (int) $a['tokens_used'] : null, $attempts),
            static fn (?int $tokens): bool => $tokens !== null && $tokens > 0,
        ));

        return [
            'objective' => (string) ($exploration['objective'] ?? ''),
            'provider' => (string) ($exploration['provider'] ?? ''),
            'scenarios_explored' => (int) ($exploration['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
            'has_winner' => is_array($exploration['winner'] ?? null),
            'rejected_reasons' => $reasons,
            'cost_estimate_usd' => $costSamples !== [] ? round(array_sum($costSamples), 6) : null,
            'cost_sample_count' => count($costSamples),
            'tokens_used' => $tokensUsed !== [] ? array_sum($tokensUsed) : null,
            'token_sample_count' => count($tokensUsed),
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
     * @return list<array<string,mixed>>
     */
    private function attemptMetrics(array $attempts): array
    {
        $metrics = [];
        foreach (array_slice(array_values($attempts), 0, self::ATTEMPT_METRICS_CAP) as $index => $attempt) {
            if (! is_array($attempt)) {
                continue;
            }
            $metrics[] = $this->buildAttemptMetricRecord($attempt, $index);
        }

        return $metrics;
    }

    /**
     * Per-attempt metric record orchestrator — drives the foreach + the
     * helper dispatch. The 2 ternaries below are MOVED out of the original
     * inline body (verdict/diff_size shape checks); the 11 null-coalesces
     * inside the array are MOVED into the per-slice helpers, not duplicated,
     * so the file's total decision/branch count falls, not grows.
     *
     * @param  array<string,mixed>  $attempt
     * @param  int  $index
     * @return array<string,mixed>
     */
    private function buildAttemptMetricRecord(array $attempt, int $index): array
    {
        $verdict = is_array($attempt['verdict'] ?? null) ? $attempt['verdict'] : [];
        $diffSize = is_array($attempt['diff_size'] ?? null) ? $attempt['diff_size'] : [];

        return [
            ...$this->verdictSlice($attempt, $verdict, $index),
            ...$this->budgetSlice($attempt, $diffSize),
            ...$this->judgeDiagnosticSlice($verdict),
        ];
    }

    /**
     * Verdict-shaped slice of a per-attempt metric record (scenario + strategy
     * identity + the judge verdict fields). Pulled out of the inline array
     * literal to distribute branches.
     *
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>  $verdict  the normalised verdict array
     * @param  int  $index
     * @return array<string,mixed>
     */
    private function verdictSlice(array $attempt, array $verdict, int $index): array
    {
        return [
            'scenario' => $this->attemptScenario($attempt['scenario_id'] ?? null, $index),
            'strategy_key' => $this->attemptStrategyKey($attempt['strategy_key'] ?? null),
            'strategy' => $this->attemptStrategy($attempt['strategy'] ?? null),
            'passed' => (bool) ($verdict['passed'] ?? false),
            'metric' => $this->attemptMetric($verdict['metric'] ?? null),
            'metric_finite' => (bool) ($verdict['metric_finite'] ?? true),
        ];
    }

    /**
     * Budget-shaped slice of a per-attempt metric record (provider-invocation
     * signal + tokens/cost/diff-size). Pulled out of the inline array literal
     * to distribute branches. The provider-invocation flag is the strategy
     * bandit's signal for "this row really called a provider" — a row with a
     * fabricated tokens_used but no real provider call (provider_invoked
     * false/absent) is rejected.
     *
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>  $diffSize  the normalised diff_size array
     * @return array<string,mixed>
     */
    private function budgetSlice(array $attempt, array $diffSize): array
    {
        return [
            'provider_invoked' => (bool) ($attempt['provider_invoked'] ?? false),
            'tokens_used' => $this->attemptTokens($attempt['tokens_used'] ?? null),
            'cost_estimate_usd' => $this->attemptCost($attempt['cost_estimate_usd'] ?? null),
            'diff_files' => $this->attemptDiffFiles($diffSize['files'] ?? null),
            'diff_lines' => $this->attemptDiffLines($diffSize['lines'] ?? null),
            ...$this->providerDiagnosticSlice($attempt),
        ];
    }

    /**
     * Safe provider diagnostics for babysitting live soaks. This carries only bounded
     * scalars; raw stdout/stderr/diff text stay out of the app-read table.
     *
     * @param  array<string,mixed>  $attempt
     * @return array<string,mixed>
     */
    private function providerDiagnosticSlice(array $attempt): array
    {
        $diagnostics = [];
        foreach ([
            'edits_applied_from_text',
            'zero_diff_retry',
            'provider_output_present',
            'provider_error_present',
            'provider_projection_noise_reset',
        ] as $key) {
            if (array_key_exists($key, $attempt)) {
                $diagnostics[$key] = (bool) $attempt[$key];
            }
        }
        foreach ([
            'provider_exit_code',
            'provider_output_bytes',
            'provider_error_bytes',
        ] as $key) {
            if (array_key_exists($key, $attempt)) {
                $diagnostics[$key] = $this->attemptNonNegativeInt($attempt[$key]);
            }
        }
        foreach ([
            'edit_apply_status' => 80,
            'provider_failure_type' => 120,
            'provider_note' => 160,
        ] as $key => $limit) {
            if (array_key_exists($key, $attempt)) {
                $diagnostics[$key] = $this->attemptBoundedString($attempt[$key], $limit);
            }
        }
        if (array_key_exists('provider_projection_noise_files', $attempt)) {
            $diagnostics['provider_projection_noise_files'] = $this->attemptPathList($attempt['provider_projection_noise_files']);
        }

        return $diagnostics;
    }

    /**
     * Bounded judge rejection details. Paths are relative repo paths from the
     * frozen judge; include them only when a candidate was actually rejected.
     *
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function judgeDiagnosticSlice(array $verdict): array
    {
        $details = is_array($verdict['details'] ?? null) ? $verdict['details'] : [];
        if (($details['rejected'] ?? false) !== true) {
            return [];
        }

        return array_filter([
            'judge_reason' => $this->attemptBoundedString($details['reason'] ?? null, 120),
            'changed_files' => $this->attemptPathList($details['changed_files'] ?? null),
            'out_of_scope_files' => $this->attemptPathList($details['out_of_scope_files'] ?? null),
            'tampered_files' => $this->attemptPathList($details['tampered_files'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  mixed  $scenario
     * @param  int  $index
     * @return string|int
     */
    private function attemptScenario(mixed $scenario, int $index): string|int
    {
        return is_string($scenario) && trim($scenario) !== '' ? $scenario : $index + 1;
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private function attemptStrategyKey(mixed $value): ?string
    {
        return is_string($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private function attemptStrategy(mixed $value): ?string
    {
        return is_string($value) ? trim((string) $value) : null;
    }

    /**
     * @param  mixed  $value
     * @return float|null
     */
    private function attemptMetric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function attemptTokens(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function attemptNonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /**
     * @param  mixed  $value
     * @return float|null
     */
    private function attemptCost(mixed $value): ?float
    {
        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function attemptDiffFiles(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function attemptDiffLines(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private function attemptBoundedString(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, max(1, $limit));
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function attemptPathList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            $path = $this->attemptBoundedString($path, 180);
            if ($path === null || str_contains($path, "\n") || str_contains($path, "\r")) {
                continue;
            }
            $paths[] = $path;
            if (count($paths) >= 8) {
                break;
            }
        }

        return array_values(array_unique($paths));
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
