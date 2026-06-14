<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The SCENARIO EXPLORER — the senior-vs-junior engine.
 *
 * A junior explores 20 options to discover that 19 fail and 1 is right; a senior
 * already knows. This explorer makes the loop behave like the junior who keeps
 * searching: for ONE task it spins up N isolated candidate workspaces, attempts a
 * different approach in each (through the provider-agnostic execution
 * abstraction), and lets the FROZEN JUDGE pick the single best — the rest are
 * recorded as explored-and-rejected (the loop's growing experience).
 *
 * PROVIDER-AGNOSTIC BY CONSTRUCTION. This class never names a provider. Execution
 * goes through {@see SeniorEngineerLoopExecutor}, which resolves the provider from
 * the `provider_choice` surface hint (config-driven default, per-task overridable)
 * via the AiProviderManager / providerLock abstraction. An empty choice lets the
 * senior-loop / Atlas Decide pick. Remove any provider and the loop still runs.
 */
final class AtlasEvolutionScenarioExplorer
{
    public const SCHEMA = 'atlas.evolution.scenario_exploration.v1';

    /** Strategy nudges that diversify the N attempts (the "explore 20 options"). */
    private const DEFAULT_STRATEGIES = [
        '',
        'Prefer the smallest, most surgical change that satisfies the objective.',
        'If the obvious fix is fragile, consider a cleaner alternative — but stay strictly in scope.',
        'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.',
        'Favor deleting/simplifying over adding, if it still satisfies the objective.',
    ];

    public function __construct(
        private readonly LoopExecutionDriver $driver,
        private readonly AtlasEvolutionFrozenJudge $judge,
    ) {}

    /**
     * Explore N scenarios for a single task and let the frozen judge pick the best.
     *
     * @param  array{
     *     objective: string,
     *     base_workspace: string,
     *     acceptance: array<string,mixed>,
     *     provider?: ?string,
     *     allowed_files?: list<string>,
     *     validation_commands?: list<string>,
     *     scenario_strategies?: list<string>,
     *     scenario_clone_mode?: string,
     *     surface_id?: string,
     *     keep_workspaces?: bool
     * }  $task
     * @return array<string,mixed>  atlas.evolution.scenario_exploration.v1
     */
    public function explore(array $task, ?int $scenarios = null): array
    {
        $objective = trim((string) ($task['objective'] ?? ''));
        $baseWorkspace = (string) ($task['base_workspace'] ?? '');
        $acceptance = is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [];
        $metricKind = (string) ($acceptance['metric_kind'] ?? AtlasEvolutionFrozenJudge::METRIC_GATE);
        $surfaceId = trim((string) ($task['surface_id'] ?? 'atlas_evolution_loop')) ?: 'atlas_evolution_loop';
        $keepWorkspaces = (bool) ($task['keep_workspaces'] ?? false);

        $provider = $this->resolveProvider($task);                 // provider-agnostic
        $userConstraints = $this->userConstraints($task);
        $surfaceHints = $this->surfaceHints($provider);

        if ($objective === '' || ! is_dir($baseWorkspace) || ($acceptance['commands'] ?? []) === []) {
            return $this->result($objective, $provider, $metricKind, null, [], [
                'blocked' => true,
                'reason' => 'invalid_task (objective/base_workspace/acceptance.commands required)',
            ]);
        }

        // DEEP SEARCH: keep exploring NEW scenarios while they keep improving the
        // best candidate — up to a hard cap / time budget, stopping early once it
        // converges (patience). This is the junior who explores many options until
        // the best one is found. Fixed-N (min=max=N) when $scenarios is explicit.
        [$min, $max, $patience, $timeBudget] = $this->searchParams($task, $scenarios);
        $workspaceRoot = $this->scenarioRoot($task); // honor a per-worker root; defaults to sys_get_temp_dir
        $attempts = [];
        $best = null;
        $noImprove = 0;
        $start = microtime(true);

        for ($i = 0; $i < $max; $i++) {
            if ($i >= $min && $best !== null && $noImprove >= $patience) {
                break; // converged: a winner exists and the last $patience scenarios didn't beat it
            }
            if ($timeBudget > 0 && (microtime(true) - $start) >= $timeBudget) {
                break; // search time budget reached
            }

            $strategy = $this->strategyFor($task, $i);
            $attempt = $this->runScenario($i, $objective, $strategy['text'], $strategy['key'], $baseWorkspace, $acceptance, $surfaceId, $userConstraints, $surfaceHints, $provider, $keepWorkspaces, $workspaceRoot, $this->scenarioCloneMode($task));
            $attempts[] = $attempt;

            if ($this->improvesBest($attempt, $best, $metricKind)) {
                $best = $attempt;
                $noImprove = 0;
            } else {
                $noImprove++;
            }
        }

        $winner = $this->pickWinner($attempts, $metricKind);

        return $this->result($objective, $provider, $metricKind, $winner, $attempts, [
            'blocked' => false,
            'reason' => $winner !== null ? 'winner_selected' : 'no_passing_candidate',
            'scenarios_cap' => $max,
            'converged' => $best !== null && $noImprove >= $patience,
        ]);
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array<string,mixed>
     */
    private function runScenario(int $index, string $objective, string $strategy, string $strategyKey, string $baseWorkspace, array $acceptance, string $surfaceId, array $userConstraints, array $surfaceHints, string $provider, bool $keepWorkspaces, string $workspaceRoot = '', string $cloneMode = 'copy'): array
    {
        $scenarioId = 'scn-'.($index + 1);
        $workspace = null;
        try {
            $workspace = $this->prepareScenarioWorkspace($baseWorkspace, $index, $workspaceRoot, $cloneMode);
            $intent = $strategy === '' ? $objective : $objective."\n\nApproach hint: ".$strategy;

            $loopSummary = $this->driver->attempt(
                surfaceId: $surfaceId,
                workspace: $workspace,
                intent: $intent,
                userConstraints: $userConstraints,
                surfaceHints: $surfaceHints,
            );

            // The judge is AUTHORITATIVE — it re-proves independently in the workspace,
            // never trusting the loop's self-reported verification (Goodhart-guard #3).
            $verdict = $this->judge->score($workspace, $acceptance);
            $diffSize = $this->diffSize($workspace);
            // Capture the candidate's diff BEFORE the workspace is torn down so the
            // winner survives as a self-contained, reviewable, propose-only artifact.
            $diffText = $this->diffText($workspace);

            return [
                'scenario_id' => $scenarioId,
                'strategy_key' => $strategyKey,
                'strategy' => $strategy,
                'provider' => $provider,
                'loop_status' => (string) ($loopSummary['status'] ?? 'unknown'),
                'cost_estimate_usd' => $this->positiveFloat($loopSummary['cost_estimate_usd'] ?? $loopSummary['cost_usd'] ?? data_get($loopSummary, 'provider_usage.cost_usd')),
                'tokens_used' => $this->positiveInt($loopSummary['tokens_used'] ?? data_get($loopSummary, 'provider_usage.tokens_used')),
                'verdict' => $verdict,
                'diff_size' => $diffSize,
                'diff_text' => $diffText,
                'workspace' => $keepWorkspaces ? $workspace : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'scenario_id' => $scenarioId,
                'strategy_key' => $strategyKey,
                'strategy' => $strategy,
                'provider' => $provider,
                'loop_status' => 'errored',
                'cost_estimate_usd' => null,
                'tokens_used' => null,
                'verdict' => ['passed' => false, 'metric' => 0.0, 'details' => ['reason' => 'scenario_threw']],
                'diff_size' => ['files' => 0, 'lines' => 0],
                'workspace' => $keepWorkspaces ? $workspace : null,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        } finally {
            if (! $keepWorkspaces && $workspace !== null && is_dir($workspace)) {
                $this->removeScenarioWorkspace($baseWorkspace, $workspace, $cloneMode);
            }
        }
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float > 0.0 ? $float : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * Pick the single best attempt: passing beats failing; among passes, the
     * strictly-better metric wins; ties (pure gate) break to the SMALLEST diff
     * (the simplicity criterion — simpler is better, all else equal).
     *
     * @param  list<array<string,mixed>>  $attempts
     * @return array<string,mixed>|null
     */
    private function pickWinner(array $attempts, string $metricKind): ?array
    {
        $best = null;
        foreach ($attempts as $attempt) {
            if (! (bool) ($attempt['verdict']['passed'] ?? false)) {
                continue;
            }
            // A clamped non-finite metric is not a real score — it can never win a numeric task.
            if ($metricKind !== AtlasEvolutionFrozenJudge::METRIC_GATE && ($attempt['verdict']['metric_finite'] ?? true) === false) {
                continue;
            }
            if ($best === null) {
                $best = $attempt;

                continue;
            }
            if ($this->judge->isStrictlyBetter($attempt['verdict'], $best['verdict'], $metricKind)) {
                $best = $attempt;
            } elseif (! $this->judge->isStrictlyBetter($best['verdict'], $attempt['verdict'], $metricKind)
                && $this->isSmallerDiff($attempt['diff_size'], $best['diff_size'])) {
                // tie on metric -> prefer the smaller diff
                $best = $attempt;
            }
        }

        return $best;
    }

    /**
     * Provider-agnostic resolution. Order: per-task override -> loop config default
     * (which itself inherits the global default) -> '' (let the senior-loop /
     * Atlas Decide pick). Never a hard-coded provider name.
     *
     * @param  array<string,mixed>  $task
     */
    private function resolveProvider(array $task): string
    {
        $override = trim((string) ($task['provider'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        return trim((string) config('atlas.loop.default_provider', ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceHints(string $provider): array
    {
        $hints = [
            'composer_mode' => 'programming',
            'composer_task' => 'repair',
            'thread_id' => 'atlas-evolution-loop',
        ];
        if ($provider !== '') {
            $hints['provider_choice'] = $provider;
        }

        return $hints;
    }

    /**
     * @param  array<string,mixed>  $task
     * @return list<string>
     */
    private function userConstraints(array $task): array
    {
        $allowedFiles = array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($task['allowed_files'] ?? null) ? $task['allowed_files'] : [],
        )));
        $validationCommands = array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($task['validation_commands'] ?? null) ? $task['validation_commands'] : [],
        )));

        $constraints = [];
        if ($allowedFiles !== []) {
            $constraints[] = 'allowed_files='.implode(',', $allowedFiles);
        }
        foreach ($validationCommands as $command) {
            $constraints[] = 'validation_command='.$command;
        }

        return $constraints;
    }

    /**
     * @param  array<string,mixed>  $task
     */
    /**
     * @param  array<string,mixed>  $task
     * @return array{key:string,text:string}
     */
    private function strategyFor(array $task, int $i): array
    {
        $provided = array_values(array_filter(
            is_array($task['scenario_strategies'] ?? null) ? $task['scenario_strategies'] : [],
            static fn (mixed $v): bool => is_string($v),
        ));
        $keys = array_values(array_filter(
            is_array($task['scenario_strategy_keys'] ?? null) ? $task['scenario_strategy_keys'] : [],
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        ));
        $pool = $provided !== [] ? $provided : self::DEFAULT_STRATEGIES;
        $text = (string) $pool[$i % count($pool)];
        $key = $keys !== [] ? (string) $keys[$i % count($keys)] : $this->defaultStrategyKey($text, $i);

        return ['key' => $key, 'text' => $text];
    }

    private function defaultStrategyKey(string $strategy, int $i): string
    {
        if ($strategy === '') {
            return 'baseline';
        }

        return match ($strategy) {
            self::DEFAULT_STRATEGIES[1] => 'surgical',
            self::DEFAULT_STRATEGIES[2] => 'clean_alternative',
            self::DEFAULT_STRATEGIES[3] => 'root_cause',
            self::DEFAULT_STRATEGIES[4] => 'simplify',
            default => 'custom_'.substr(hash('sha256', $strategy.'|'.$i), 0, 12),
        };
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array{0:int,1:int,2:int,3:int}  [min, max, patience, time_budget_seconds]
     */
    private function searchParams(array $task, ?int $scenarios): array
    {
        if ($scenarios !== null) {
            $n = max(1, $scenarios);

            return [$n, $n, PHP_INT_MAX, 0]; // fixed-N: explore exactly N
        }
        $min = max(1, (int) ($task['min_scenarios'] ?? config('atlas.loop.scenarios_per_task', 3)));
        $max = max($min, (int) ($task['max_scenarios'] ?? config('atlas.loop.max_scenarios_per_task', 12)));
        $patience = max(1, (int) ($task['search_patience'] ?? config('atlas.loop.search_patience', 3)));
        $timeBudget = max(0, (int) ($task['search_time_budget_seconds'] ?? 0));

        return [$min, $max, $patience, $timeBudget];
    }

    /**
     * Did $attempt improve on the running $best? Same rule as pickWinner: passing
     * beats failing; strictly-better metric wins; gate ties break to smaller diff.
     *
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>|null  $best
     */
    private function improvesBest(array $attempt, ?array $best, string $metricKind): bool
    {
        if (! (bool) ($attempt['verdict']['passed'] ?? false)) {
            return false;
        }
        // metric_finite guard: a candidate whose metric was clamped from ±INF can never
        // win a minimize/maximize task (inert for GATE, where the metric is always finite).
        if ($metricKind !== AtlasEvolutionFrozenJudge::METRIC_GATE && ($attempt['verdict']['metric_finite'] ?? true) === false) {
            return false;
        }
        if ($best === null) {
            return true;
        }
        if ($this->judge->isStrictlyBetter($attempt['verdict'], $best['verdict'], $metricKind)) {
            return true;
        }

        return ! $this->judge->isStrictlyBetter($best['verdict'], $attempt['verdict'], $metricKind)
            && $this->isSmallerDiff($attempt['diff_size'], $best['diff_size']);
    }

    /**
     * Where scenario workspaces are created. A parallel worker passes a per-campaign /
     * per-worker `workspace_root` (so disk usage is namespaced + reapable); absent, this
     * is exactly the prior behaviour (sys_get_temp_dir).
     *
     * @param  array<string,mixed>  $task
     */
    private function scenarioRoot(array $task): string
    {
        return trim((string) ($task['workspace_root'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function scenarioCloneMode(array $task): string
    {
        return trim((string) ($task['scenario_clone_mode'] ?? 'copy')) === 'worktree' ? 'worktree' : 'copy';
    }

    private function prepareScenarioWorkspace(string $base, int $index, string $root = '', string $cloneMode = 'copy'): string
    {
        $root = $root !== '' ? rtrim($root, '/') : sys_get_temp_dir();
        if (! is_dir($root)) {
            @mkdir($root, 0o755, true);
        }
        $target = $root.'/atlas-loop-scn-'.bin2hex(random_bytes(4)).'-'.$index;
        if ($cloneMode === 'worktree') {
            return $this->prepareWorktreeScenarioWorkspace($base, $target);
        }

        mkdir($target, 0o755, true);
        // copy the base CONTENTS into the isolated scenario workspace
        (new Process(['bash', '-lc', 'cp -R '.escapeshellarg(rtrim($base, '/').'/.').' '.escapeshellarg($target)]))->run();

        // establish a clean git baseline so the judge diffs only the candidate's edits
        $git = static function (array $argv) use ($target): void {
            (new Process($argv, $target, null, null, 60.0))->run();
        };
        if (! is_dir($target.'/.git')) {
            $git(['git', 'init', '-q']);
        }
        $git(['git', 'add', '-A']);
        $git(['git', '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'scenario baseline']);

        return $target;
    }

    private function prepareWorktreeScenarioWorkspace(string $base, string $target): string
    {
        $this->mustRun(['git', '-C', $base, 'rev-parse', '--is-inside-work-tree'], 'base_is_not_git_worktree', 30.0);
        $this->mustRun(['git', '-C', $base, 'worktree', 'add', '--detach', $target, 'HEAD'], 'scenario_worktree_add_failed', 120.0);
        $this->copyWorkspaceLocalSupport($base, $target);

        return $target;
    }

    private function copyWorkspaceLocalSupport(string $base, string $target): void
    {
        foreach (['vendor'] as $dir) {
            if (is_dir($base.'/'.$dir)) {
                $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($base.'/'.$dir).' '.escapeshellarg($target.'/'.$dir)], 'scenario_support_copy_failed_'.$dir, 180.0);
            }
        }
        foreach (['.env', '.env.testing'] as $file) {
            if (is_file($base.'/'.$file)) {
                copy($base.'/'.$file, $target.'/'.$file);
            }
        }
        foreach ([
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relative) {
            $dir = $target.'/'.$relative;
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    private function removeScenarioWorkspace(string $base, string $workspace, string $cloneMode): void
    {
        if ($cloneMode === 'worktree') {
            (new Process(['git', '-C', $base, 'worktree', 'remove', '--force', $workspace], null, null, null, 60.0))->run();
            (new Process(['git', '-C', $base, 'worktree', 'prune'], null, null, null, 30.0))->run();
        }
        if (is_dir($workspace)) {
            (new Process(['rm', '-rf', $workspace]))->run();
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function mustRun(array $argv, string $stage, float $timeout): void
    {
        $process = new Process($argv, null, null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($stage.': '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), -240));
        }
    }

    /**
     * @return array{files: int, lines: int}
     */
    private function diffSize(string $workspace): array
    {
        $stat = new Process(['git', 'diff', '--numstat', '--no-ext-diff'], $workspace, null, null, 30.0);
        $stat->run();
        $files = 0;
        $lines = 0;
        foreach (preg_split('/\R/', trim((string) $stat->getOutput())) ?: [] as $row) {
            if (preg_match('/^(\d+|-)\s+(\d+|-)\s+/', $row, $m) === 1) {
                $files++;
                $lines += (is_numeric($m[1]) ? (int) $m[1] : 0) + (is_numeric($m[2]) ? (int) $m[2] : 0);
            }
        }
        // include untracked additions in the file and line count
        $others = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $workspace, null, null, 30.0);
        $others->run();
        foreach (preg_split('/\R/', trim((string) $others->getOutput())) ?: [] as $row) {
            if (trim($row) !== '') {
                $files++;
                $contents = (string) file_get_contents($workspace.'/'.trim($row));
                if ($contents !== '') {
                    $lines += substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
                }
            }
        }

        return ['files' => $files, 'lines' => $lines];
    }

    /**
     * The candidate's full diff (tracked edits + untracked additions) as a
     * self-contained, reviewable artifact — the body of a propose-only proposal.
     */
    private function diffText(string $workspace, int $max = 20000): string
    {
        $diff = new Process(['git', 'diff', '--no-ext-diff'], $workspace, null, null, 30.0);
        $diff->run();
        $text = (string) $diff->getOutput();

        $others = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $workspace, null, null, 30.0);
        $others->run();
        foreach (preg_split('/\R/', trim((string) $others->getOutput())) ?: [] as $path) {
            $path = trim($path);
            if ($path === '' || ! is_file($workspace.'/'.$path)) {
                continue;
            }
            $text .= "\n--- /dev/null\n+++ b/".$path."\n".(string) file_get_contents($workspace.'/'.$path);
        }

        return strlen($text) <= $max ? $text : substr($text, 0, $max).'…';
    }

    /**
     * @param  array{files:int,lines:int}  $a
     * @param  array{files:int,lines:int}  $b
     */
    private function isSmallerDiff(array $a, array $b): bool
    {
        if ($a['files'] !== $b['files']) {
            return $a['files'] < $b['files'];
        }

        return $a['lines'] < $b['lines'];
    }

    /**
     * @param  array<string,mixed>|null  $winner
     * @param  list<array<string,mixed>>  $attempts
     * @param  array<string,mixed>  $status
     * @return array<string,mixed>
     */
    private function result(string $objective, string $provider, string $metricKind, ?array $winner, array $attempts, array $status): array
    {
        $accepted = array_values(array_filter($attempts, static fn (array $a): bool => (bool) ($a['verdict']['passed'] ?? false)));

        return [
            'schema_version' => self::SCHEMA,
            'objective' => $objective,
            'provider' => $provider === '' ? '(loop_default)' : $provider,
            'metric_kind' => $metricKind,
            'scenarios_explored' => count($attempts),
            'scenarios_accepted' => count($accepted),
            'winner' => $winner,
            'attempts' => $attempts,
            'status' => $status,
        ];
    }
}
