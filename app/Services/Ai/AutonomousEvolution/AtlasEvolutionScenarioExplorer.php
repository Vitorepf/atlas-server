<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcherContract;
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

    /**
     * Strategy mandates that DECORRELATE the N attempts. On a single weak engine (MiniMax via
     * Hermes — no per-call temperature/seed available), mild nudges collapse into near-identical
     * diffs and best-of-N buys almost nothing. These are deliberately STRUCTURALLY DISTINCT and
     * forceful so even a weak model produces genuinely different candidates the frozen judge can
     * choose between. Index→key mapping is preserved in {@see defaultStrategyKey}. [0] is the
     * unconstrained baseline (kept for natural-solution diversity).
     */
    private const DEFAULT_STRATEGIES = [
        '',
        'STRATEGY A — MINIMAL SURGICAL: change the fewest lines possible, in exactly one method/branch; introduce no new abstraction and do NOT widen any signature. The tightest diff that satisfies the objective generally.',
        'STRATEGY B — CLEAN REDESIGN: assume the obvious one-line patch is the wrong shape; restructure the responsible unit properly (extract/guard/rename) so the bug class cannot recur. Deliberately DIFFERENT from a minimal patch — stay strictly in scope.',
        'STRATEGY C — ROOT CAUSE: trace WHY the acceptance fails to its true origin and fix the underlying cause, even if that line differs from the symptom; add a guard for the invariant being violated.',
        'STRATEGY D — SIMPLIFY/DELETE: satisfy the objective by REMOVING or collapsing code rather than adding; the smallest correct general implementation, no special-cases.',
    ];

    /**
     * DEEPER best-of-N (ACDE direction-(a)). On a single weak engine with no temperature/seed, widening
     * past the 5 base mandates above just re-rolls them ({@see strategyFor} cycles the pool), so the extra
     * scenarios collapse into near-duplicates. These four are STRUCTURALLY DISTINCT continuations of the
     * portfolio — different shapes of change, not different wordings — so widening to 9 buys genuinely new
     * candidates. Appended (never reordered) so indices 0..4 stay byte-identical and persisted strategy
     * keys remain stable. Opt-in: only consumed when the task carries `deep_strategy_portfolio` (the grinder
     * sets it from {@see config} `atlas.loop.deep_strategy_portfolio`); the explorer hot path stays config-free.
     */
    private const EXTENDED_STRATEGIES = [
        'STRATEGY E — GUARD-FIRST / FAIL-CLOSED: add precondition guards and early returns at the TOP of the responsible method so invalid states are rejected before any work runs; then satisfy the objective inside the now-validated region. Make the invalid case unrepresentable rather than handled late.',
        'STRATEGY F — EXTRACT-HELPER: move the load-bearing logic into a small, single-responsibility private helper with an explicit name and call it from the original site; keep the public signature and the behaviour for valid inputs identical. The diff is a clean extraction, not an inline patch.',
        'STRATEGY G — TYPE/DATA-DRIVEN: normalize the inputs into one explicit, well-typed shape up front (value object / typed array / enum-like) so the objective becomes a straight-line consequence of the normalized data, eliminating scattered conditionals rather than adding another.',
        'STRATEGY H — INVERT-AND-FLATTEN: invert the nested conditionals into guard clauses to flatten control flow, then place the fix in the de-nested happy path; reduce branch depth without changing outputs for any valid input.',
    ];

    /**
     * Anti-overfit clause appended to EVERY attempt's intent. A weak engine, told a test must pass,
     * games it with literal short-circuits (the observed `if (func_num_args()===1) return 5`). This
     * discourages that at prompt time; the certifier's mutation/behavioral gates catch it regardless.
     */
    private const ANTI_OVERFIT = 'Implement the GENERAL logic that satisfies the objective for ALL valid inputs. Do NOT special-case the acceptance test inputs, return literal constants, or branch on argument count to make a specific case pass — such a diff will be rejected by the certification gates.';

    private ?AtlasEvolutionScenarioDiffMetricsCalculator $diffMetrics = null;

    public function __construct(
        private readonly LoopExecutionDriver $driver,
        private readonly AtlasEvolutionFrozenJudge $judge,
        private readonly ?AtlasLoopScenarioProviderPortfolio $portfolio = null,
        // item6_fanout: OPTIONAL bounded-wave dispatcher (LAST, nullable, default null) so every
        // existing `new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge)` call
        // site keeps compiling. Typed against the CONTRACT so tests can inject a fake. Laravel does
        // not autowire a nullable-with-default param — the integrator's explicit bind passes the
        // concrete; without it this stays null and the serial path runs (byte-identical OFF).
        private readonly ?ScenarioWaveDispatcherContract $waveDispatcher = null,
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
     * @return array<string,mixed> atlas.evolution.scenario_exploration.v1
     */
    public function explore(array $task, ?int $scenarios = null, ?callable $onProgress = null): array
    {
        [
            $objective,
            $baseWorkspace,
            $acceptance,
            $metricKind,
            $surfaceId,
            $keepWorkspaces,
            $provider,
            $userConstraints,
            $surfaceHints,
            $validCommands,
        ] = $this->explorationInput($task);

        if ($objective === '' || ! is_dir($baseWorkspace) || ! $validCommands) {
            return $this->result($objective, $provider, $metricKind, null, [], [
                'blocked' => true,
                'reason' => 'invalid_task (objective/base_workspace/acceptance.commands required)',
            ]);
        }

        [$min, $max, $patience, $timeBudget] = $this->searchParams($task, $scenarios);
        $workspaceRoot = $this->scenarioRoot($task); // honor a per-worker root; defaults to sys_get_temp_dir
        $search = $this->exploreAttempts($min, $max, $patience, $timeBudget, $objective, $baseWorkspace, $task, $acceptance, $metricKind, $surfaceId, $userConstraints, $surfaceHints, $provider, $keepWorkspaces, $workspaceRoot, $onProgress);
        $attempts = $search['attempts'];

        $winner = $this->pickWinner($attempts, $metricKind);

        return $this->result($objective, $provider, $metricKind, $winner, $attempts, [
            'blocked' => false,
            'reason' => ['no_passing_candidate', 'winner_selected'][(int) ($winner !== null)],
            'scenarios_cap' => $max,
            'converged' => $search['converged'],
        ]);
    }

    /**
     * DEEP SEARCH: keep exploring NEW scenarios while they keep improving the
     * best candidate — up to a hard cap / time budget, stopping early once it
     * converges (patience). Fixed-N (min=max=N) when $scenarios is explicit.
     *
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array{attempts:list<array<string,mixed>>,converged:bool}
     */
    private function exploreAttempts(int $min, int $max, int $patience, int $timeBudget, string $objective, string $baseWorkspace, array $task, array $acceptance, string $metricKind, string $surfaceId, array $userConstraints, array $surfaceHints, string $provider, bool $keepWorkspaces, string $workspaceRoot, ?callable $onProgress = null): array
    {
        $start = microtime(true);
        $portfolio = $this->portfolio ?? new AtlasLoopScenarioProviderPortfolio;
        // PER-OBRA WORKING MEMORY (Next-Lever 5): accumulate what THIS obra already tried + why it failed,
        // and feed a "do NOT repeat" digest into each subsequent attempt's intent so round K is smarter than
        // 1..K-1 (anti-context-rot). The ledger's thrashing signal is exposed for the escalation ladder.
        $ledger = new AtlasLoopAttemptLedger;

        // item6_fanout: when the dispatcher is wired AND the flag is on, run the attempts in
        // bounded PARALLEL WAVES instead of the serial for-loop below — hiding the per-attempt
        // provider latency behind width. Fail-safe + byte-identical OFF: the null check is FIRST
        // (mirroring the line-above $this->portfolio short-circuit), so a null dispatcher — every
        // frozen unit test, which `new`s the explorer directly — never even reads config() and
        // falls straight through to the unchanged serial path.
        if ($this->isWaveFanoutEnabled()) {
            return $this->exploreAttemptsInWaves($min, $max, $patience, $timeBudget, $objective, $baseWorkspace, $task, $acceptance, $metricKind, $surfaceId, $userConstraints, $surfaceHints, $provider, $keepWorkspaces, $workspaceRoot, $portfolio, $ledger, $onProgress);
        }

        return $this->runSerialAttempts($min, $max, $patience, $timeBudget, $objective, $baseWorkspace, $task, $acceptance, $metricKind, $surfaceId, $userConstraints, $surfaceHints, $provider, $keepWorkspaces, $workspaceRoot, $portfolio, $ledger, $start, $onProgress);
    }

    /**
     * STRATEGY A — MINIMAL SURGICAL: collapse the two-clause fanout guard into a single boolean helper
     * so the `&&` decision point MOVES out of {@see exploreAttempts}. Byte-identical semantics: same
     * short-circuit order, same first-null-check ordering that lets frozen unit tests skip the config
     * read. The helper itself is a straight-line conjunction (no if, no ternary) so its decision
     * count is exactly the `&&` it absorbs — net zero on the file's total.
     */
    private function isWaveFanoutEnabled(): bool
    {
        return $this->waveDispatcher !== null && (bool) config('atlas.loop.scenario_fanout.enabled', false);
    }

    /**
     * Serial loop body for {@see exploreAttempts} — extracted so the orchestrator stays straight-line
     * and the per-method AST max drops well below the old 13. Behaviour is byte-identical: same
     * convergence rule, same per-attempt ordering, same ledger fold, same $best/$noImprove wiring.
     * Strategy-A (minimal surgical): pure relocation of the previously-inlined serial loop body.
     *
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array{attempts:list<array<string,mixed>>,converged:bool,convergence:array<string,mixed>}
     */
    private function runSerialAttempts(int $min, int $max, int $patience, int $timeBudget, string $objective, string $baseWorkspace, array $task, array $acceptance, string $metricKind, string $surfaceId, array $userConstraints, array $surfaceHints, string $provider, bool $keepWorkspaces, string $workspaceRoot, AtlasLoopScenarioProviderPortfolio $portfolio, AtlasLoopAttemptLedger $ledger, float $start, ?callable $onProgress = null): array
    {
        $attempts = [];
        $best = null;
        $noImprove = 0;

        for ($i = 0; $i < $max; $i++) {
            if ($this->shouldBreakSearch($i, $min, $best, $noImprove, $patience, $timeBudget, $start)) {
                break;
            }

            $strategy = $this->strategyFor($task, $i);
            // CROSS-PROVIDER best-of-N (Lever 4): rotate the N attempts across a provider portfolio so the
            // candidates are DECORRELATED by engine (codex and MiniMax fail differently), not only by
            // strategy hint. Fail-safe: an empty/single portfolio reproduces single-provider behavior
            // byte-for-byte (every attempt uses $provider, $surfaceHints unchanged).
            $attemptProvider = $portfolio->providerFor($task, $i, $provider);
            $attemptHints = $attemptProvider === $provider ? $surfaceHints : $this->surfaceHints($attemptProvider);
            // Feed prior failed approaches forward (empty on the first attempt => byte-identical).
            $guidance = $ledger->guidance();
            $strategyText = $guidance === '' ? $strategy['text'] : trim($strategy['text']."\n\n".$guidance);
            $attemptTimeout = $this->remainingAttemptTimeoutSeconds($timeBudget, $start);
            if ($attemptTimeout !== null && $attemptTimeout <= 0) {
                break;
            }

            $attempt = $this->runScenario($i, $objective, $strategyText, $strategy['key'], $baseWorkspace, $acceptance, $surfaceId, $userConstraints, $attemptHints, $attemptProvider, $keepWorkspaces, $workspaceRoot, $this->scenarioCloneMode($task), $attemptTimeout, $onProgress);
            $attempts[] = $attempt;
            $ledger->record(
                $strategy['key'],
                $attemptProvider,
                (bool) data_get($attempt, 'verdict.passed', false),
                (string) data_get($attempt, 'verdict.details.reason', ''),
                (string) ($attempt['scenario_id'] ?? ''),
            );

            $best = $this->applyAttemptOutcome($attempt, $best, $noImprove, $metricKind);
        }

        return [
            'attempts' => $attempts,
            'converged' => $best !== null && $noImprove >= $patience,
            'convergence' => $ledger->convergence(),
        ];
    }

    /**
     * Fold a settled attempt into the running best / noImprove counters. Centralises the if/else that
     * previously sat inline in the serial loop body, so the loop stays straight-line. Behaviour is
     * byte-identical: passing beats failing, strictly-better metric wins, gate ties break to the
     * smaller diff (handled inside {@see improvesBest}).
     *
     * @param  array<string,mixed>  $attempt
     * @param  array<string,mixed>|null  $best
     */
    private function applyAttemptOutcome(array $attempt, ?array $best, int &$noImprove, string $metricKind): ?array
    {
        if ($this->improvesBest($attempt, $best, $metricKind)) {
            $noImprove = 0;

            return $attempt;
        }
        $noImprove++;

        return $best;
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
            // Progress callbacks keep leases/heartbeats fresh; they must never affect scoring.
        }
    }

    /**
     * Combined convergence + budget break-check used by the serial loop body. Centralises the two
     * guards so the loop body stays straight-line and the per-method AST max-per-method in this file
     * drops well below the old 13. Returns true when the loop should stop iterating. Behaviour is
     * byte-identical to the prior inline guards (convergence: a winner exists and the last $patience
     * scenarios didn't beat it; budget: the soft time limit elapsed).
     */
    private function shouldBreakSearch(int $i, int $min, ?array $best, int $noImprove, int $patience, int $timeBudget, float $start): bool
    {
        // The TIME BUDGET is the authoritative hard stop (never overrun the campaign deadline).
        if ($timeBudget > 0 && (microtime(true) - $start) >= $timeBudget) {
            return true;
        }

        // CONVERGENCE (patience). LOOP-OS Slice 10 — the anti-one-shot persistence: when caps_advisory is ON
        // AND a positive time budget is set, patience is a HINT, not a hard stop — keep searching for a
        // better solution while budget REMAINS (the canon's "nunca desistir / nunca one-shot"). Crucially,
        // with NO budget (timeBudget==0) the patience stop is the only terminator besides the hard $max, so it
        // MUST stay hard there — caps_advisory can never create an unbounded search. Flag OFF (default) ⇒ the
        // patience stop fires exactly as before ⇒ byte-identical (the frozen convergence-count tests hold).
        if ($i >= $min && $best !== null && $noImprove >= $patience) {
            $persistPastPatience = $timeBudget > 0 && (bool) config('atlas.loop.scenario_caps_advisory', false);

            return ! $persistPastPatience;
        }

        return false;
    }

    /**
     * item6_fanout — the bounded PARALLEL WAVE engine (the flag-ON path of {@see exploreAttempts}).
     *
     * It mirrors the serial loop's bookkeeping (best / noImprove / start / ledger) but dispatches a
     * WAVE of up to `width` scenarios in-flight together each round, then folds every settled attempt
     * through the UNCHANGED improvesBest() and ledger->record(). Scenario indices stay ascending so
     * scn-ids, strategyFor() and portfolio->providerFor() remain index-stable, and the dispatcher
     * returns attempts ordered by index so the fold matches the serial scn ordering byte-for-byte.
     *
     * WAVE-LEVEL GUIDANCE (intended semantic vs serial): in the serial path attempt K sees the
     * "do NOT repeat" digest from attempts 1..K-1; in waves the scenarios IN one wave run in-flight
     * together and cannot see each other, so they all share ONE guidance digest computed from PRIOR
     * waves only. Convergence + the soft time budget are evaluated at WAVE boundaries.
     *
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array{attempts:list<array<string,mixed>>,converged:bool,convergence:array<string,mixed>}
     */
    private function exploreAttemptsInWaves(int $min, int $max, int $patience, int $timeBudget, string $objective, string $baseWorkspace, array $task, array $acceptance, string $metricKind, string $surfaceId, array $userConstraints, array $surfaceHints, string $provider, bool $keepWorkspaces, string $workspaceRoot, AtlasLoopScenarioProviderPortfolio $portfolio, AtlasLoopAttemptLedger $ledger, ?callable $onProgress = null): array
    {
        $attempts = [];
        $best = null;
        $noImprove = 0;
        $start = microtime(true);
        $width = max(1, (int) config('atlas.loop.scenario_fanout.width', 4));
        $i = 0;

        while ($i < $max) {
            // Convergence + budget checks at the WAVE boundary (kept identical to the serial guards,
            // now shared via the same {@see shouldBreakSearch} helper).
            if ($this->shouldBreakSearch($i, $min, $best, $noImprove, $patience, $timeBudget, $start)) {
                break;
            }

            // WAVE-level guidance: one digest from all PRIOR settled attempts, shared by this wave.
            $guidance = $ledger->guidance();

            // Build this wave's scenario specs (indices ascending => stable scn-ids/strategy/provider).
            $waveSpecs = [];
            $waveSize = min($width, $max - $i);
            $attemptTimeout = $this->remainingAttemptTimeoutSeconds($timeBudget, $start);
            if ($attemptTimeout !== null && $attemptTimeout <= 0) {
                break;
            }
            $this->emitProgress($onProgress, 'scenario_wave_start', ['index' => $i, 'wave_size' => $waveSize]);
            for ($k = 0; $k < $waveSize; $k++) {
                $idx = $i + $k;
                $strategy = $this->strategyFor($task, $idx);
                $attemptProvider = $portfolio->providerFor($task, $idx, $provider);
                $attemptHints = $attemptProvider === $provider ? $surfaceHints : $this->surfaceHints($attemptProvider);
                $strategyText = $guidance === '' ? $strategy['text'] : trim($strategy['text']."\n\n".$guidance);
                $waveSpecs[] = [
                    'index' => $idx,
                    'objective' => $objective,
                    'strategy_text' => $strategyText,
                    'strategy_key' => $strategy['key'],
                    'base_workspace' => $baseWorkspace,
                    'acceptance' => $acceptance,
                    'surface_id' => $surfaceId,
                    'user_constraints' => $userConstraints,
                    'surface_hints' => $attemptHints,
                    'provider' => $attemptProvider,
                    'keep_workspaces' => $keepWorkspaces,
                    'workspace_root' => $workspaceRoot,
                    'clone_mode' => $this->scenarioCloneMode($task),
                    'attempt_timeout_seconds' => $attemptTimeout,
                ];
            }

            // Run the wave in-flight together; the dispatcher returns one attempt per spec ORDERED by
            // index, so the fold order is deterministic and matches the serial scn ordering.
            $settled = $this->waveDispatcher->dispatch($waveSpecs);
            $this->emitProgress($onProgress, 'scenario_wave_end', ['index' => $i, 'settled' => count($settled)]);
            foreach ($settled as $attempt) {
                $attempts[] = $attempt;
                $ledger->record(
                    (string) ($attempt['strategy_key'] ?? ''),
                    (string) ($attempt['provider'] ?? ''),
                    (bool) data_get($attempt, 'verdict.passed', false),
                    (string) data_get($attempt, 'verdict.details.reason', ''),
                    (string) ($attempt['scenario_id'] ?? ''),
                );

                $best = $this->applyAttemptOutcome($attempt, $best, $noImprove, $metricKind);
            }

            $i += $waveSize;
        }

        return [
            'attempts' => $attempts,
            'converged' => $best !== null && $noImprove >= $patience,
            'convergence' => $ledger->convergence(),
        ];
    }

    /**
     * item6_fanout — thin PUBLIC shim the per-scenario subprocess (atlas:loop:run-scenario) and the
     * dispatcher's inline fallback call to run ONE scenario. It unpacks the wave spec and delegates to
     * the UNCHANGED private runScenario(...) so the byte-identical tested code path is reused — never
     * widening runScenario's visibility or signature.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function runScenarioForWave(array $spec): array
    {
        return $this->runScenario(
            (int) ($spec['index'] ?? 0),
            (string) ($spec['objective'] ?? ''),
            (string) ($spec['strategy_text'] ?? ''),
            (string) ($spec['strategy_key'] ?? ''),
            (string) ($spec['base_workspace'] ?? ''),
            is_array($spec['acceptance'] ?? null) ? $spec['acceptance'] : [],
            (string) ($spec['surface_id'] ?? 'atlas_evolution_loop'),
            array_values(array_filter(
                is_array($spec['user_constraints'] ?? null) ? $spec['user_constraints'] : [],
                static fn (mixed $v): bool => is_string($v),
            )),
            is_array($spec['surface_hints'] ?? null) ? $spec['surface_hints'] : [],
            (string) ($spec['provider'] ?? ''),
            (bool) ($spec['keep_workspaces'] ?? false),
            (string) ($spec['workspace_root'] ?? ''),
            (string) ($spec['clone_mode'] ?? 'copy'),
            is_numeric($spec['attempt_timeout_seconds'] ?? null) ? (int) $spec['attempt_timeout_seconds'] : null,
        );
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array{0:string,1:string,2:array<string,mixed>,3:string,4:string,5:bool,6:string,7:list<string>,8:array<string,mixed>,9:bool}
     */
    private function explorationInput(array $task): array
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

        $commands = $acceptance['commands'] ?? null;
        $validCommands = is_array($commands)
            && $commands !== []
            && array_values($commands) === $commands
            && array_filter($commands, static fn (mixed $command): bool => ! is_string($command) || trim($command) === '') === [];

        return [
            $objective,
            $baseWorkspace,
            $acceptance,
            $metricKind,
            $surfaceId,
            $keepWorkspaces,
            $provider,
            $userConstraints,
            $surfaceHints,
            $validCommands,
        ];
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array<string,mixed>
     */
    private function runScenario(int $index, string $objective, string $strategy, string $strategyKey, string $baseWorkspace, array $acceptance, string $surfaceId, array $userConstraints, array $surfaceHints, string $provider, bool $keepWorkspaces, string $workspaceRoot = '', string $cloneMode = 'copy', ?int $attemptTimeoutSeconds = null, ?callable $onProgress = null): array
    {
        $scenarioId = 'scn-'.($index + 1);
        $workspace = null;
        try {
            $this->emitProgress($onProgress, 'scenario_attempt_start', ['scenario_id' => $scenarioId, 'provider' => $provider]);
            $workspace = $this->prepareScenarioWorkspace($baseWorkspace, $index, $workspaceRoot, $cloneMode);
            // Forceful per-attempt framing (decorrelation) + the global anti-overfit clause on EVERY
            // attempt. "Approach hint" was too soft for a weak engine — it collapsed the N attempts.
            $intent = $strategy === ''
                ? $objective."\n\n".self::ANTI_OVERFIT
                : $objective."\n\nMANDATORY DISTINCT APPROACH (this is one of several independent attempts — do NOT produce the generic fix; commit fully to THIS angle):\n".$strategy."\n\n".self::ANTI_OVERFIT;

            $driverHints = $surfaceHints + ['acceptance' => $acceptance];
            if ($attemptTimeoutSeconds !== null) {
                $driverHints['attempt_timeout_seconds'] = max(1, $attemptTimeoutSeconds);
            }
            if ($onProgress !== null) {
                $driverHints['_progress_callback'] = $onProgress;
            }

            $loopSummary = $this->driver->attempt(
                surfaceId: $surfaceId,
                workspace: $workspace,
                intent: $intent,
                userConstraints: $userConstraints,
                // ACDE Tier-0 #2: hand the driver the FROZEN acceptance so iterate-to-green can drive
                // toward the JUDGE's bar (when armed), not a raw exit-0 proxy a gamed candidate satisfies.
                surfaceHints: $driverHints,
            );
            $this->emitProgress($onProgress, 'scenario_attempt_driver_returned', ['scenario_id' => $scenarioId, 'provider' => $provider]);

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
                // Carry the driver's REAL provider-invocation signal so attempt_metrics records
                // whether the engine actually ran (was absent here → every metric row read
                // provider_invoked:false even when the provider fired — masking the live signal).
                'provider_invoked' => (bool) ($loopSummary['provider_invoked'] ?? false),
                'edits_applied_from_text' => (bool) ($loopSummary['edits_applied_from_text'] ?? false),
                'edit_apply_status' => $this->boundedString($loopSummary['edit_apply_status'] ?? null, 80),
                'zero_diff_retry' => (bool) ($loopSummary['zero_diff_retry'] ?? false),
                'provider_projection_noise_reset' => (bool) ($loopSummary['provider_projection_noise_reset'] ?? false),
                'provider_projection_noise_files' => $this->boundedStringList($loopSummary['provider_projection_noise_files'] ?? [], 8, 180),
                'provider_exit_code' => is_numeric($loopSummary['exit_code'] ?? null) ? (int) $loopSummary['exit_code'] : null,
                'provider_output_present' => (bool) ($loopSummary['provider_output_present'] ?? false),
                'provider_output_bytes' => is_numeric($loopSummary['provider_output_bytes'] ?? null) ? max(0, (int) $loopSummary['provider_output_bytes']) : null,
                'provider_error_present' => (bool) ($loopSummary['provider_error_present'] ?? false),
                'provider_error_bytes' => is_numeric($loopSummary['provider_error_bytes'] ?? null) ? max(0, (int) $loopSummary['provider_error_bytes']) : null,
                'provider_failure_type' => $this->boundedString($loopSummary['provider_failure_type'] ?? null, 120),
                'provider_note' => $this->boundedString($loopSummary['provider_note'] ?? null, 160),
                'cost_estimate_usd' => $this->positiveFloat($loopSummary['cost_estimate_usd'] ?? $loopSummary['cost_usd'] ?? data_get($loopSummary, 'provider_usage.cost_usd')),
                'tokens_used' => $this->positiveInt($loopSummary['tokens_used'] ?? data_get($loopSummary, 'provider_usage.tokens_used')),
                'verdict' => $verdict,
                'diff_size' => $diffSize,
                'diff_text' => $diffText,
                'workspace' => $keepWorkspaces ? $workspace : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $this->emitProgress($onProgress, 'scenario_attempt_error', ['scenario_id' => $scenarioId, 'provider' => $provider]);
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
                'diff_text' => '',
                'workspace' => $keepWorkspaces ? $workspace : null,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        } finally {
            if (! $keepWorkspaces && $workspace !== null && is_dir($workspace)) {
                $this->removeScenarioWorkspace($baseWorkspace, $workspace, $cloneMode);
            }
        }
    }

    private function boundedString(mixed $value, int $limit): ?string
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
     * @return list<string>
     */
    private function boundedStringList(mixed $value, int $maxItems, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = $this->boundedString($item, $limit);
            if ($item === null) {
                continue;
            }
            $out[] = $item;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return array_values(array_unique($out));
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
        $pool = $provided !== [] ? $provided : $this->strategyPool($task);
        $text = (string) $pool[$i % count($pool)];
        $key = $keys !== [] ? (string) $keys[$i % count($keys)] : $this->defaultStrategyKey($text, $i);

        return ['key' => $key, 'text' => $text];
    }

    /**
     * The default decorrelation pool when the task pins no explicit strategies. 5 base mandates today;
     * 9 when the task carries `deep_strategy_portfolio` (set by the grinder from the loop config flag) —
     * the single-engine "more seeds" lever. Reads only the task (never config) so the explorer hot path
     * stays pure for the unit suite; indices 0..4 are always the base 5 (byte-identical OFF, stable keys).
     *
     * @param  array<string,mixed>  $task
     * @return list<string>
     */
    private function strategyPool(array $task): array
    {
        if (($task['deep_strategy_portfolio'] ?? false) === true) {
            return array_merge(self::DEFAULT_STRATEGIES, self::EXTENDED_STRATEGIES);
        }

        return self::DEFAULT_STRATEGIES;
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
            self::EXTENDED_STRATEGIES[0] => 'guard_first',
            self::EXTENDED_STRATEGIES[1] => 'extract_helper',
            self::EXTENDED_STRATEGIES[2] => 'type_driven',
            self::EXTENDED_STRATEGIES[3] => 'invert_flatten',
            default => 'custom_'.substr(hash('sha256', $strategy.'|'.$i), 0, 12),
        };
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array{0:int,1:int,2:int,3:int} [min, max, patience, time_budget_seconds]
     */
    private function searchParams(array $task, ?int $scenarios): array
    {
        if ($scenarios !== null) {
            $n = max(1, $scenarios);
            $timeBudget = max(0, (int) ($task['search_time_budget_seconds'] ?? 0));

            return [$n, $n, PHP_INT_MAX, $timeBudget]; // fixed-N: explore exactly N while respecting task budget
        }
        $min = max(1, (int) ($task['min_scenarios'] ?? config('atlas.loop.scenarios_per_task', 3)));
        $max = max($min, (int) ($task['max_scenarios'] ?? config('atlas.loop.max_scenarios_per_task', 12)));
        $patience = max(1, (int) ($task['search_patience'] ?? config('atlas.loop.search_patience', 3)));
        $timeBudget = max(0, (int) ($task['search_time_budget_seconds'] ?? 0));

        return [$min, $max, $patience, $timeBudget];
    }

    private function remainingAttemptTimeoutSeconds(int $timeBudget, float $start): ?int
    {
        if ($timeBudget <= 0) {
            return null;
        }

        return max(0, $timeBudget - (int) floor(microtime(true) - $start));
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
        $git(['git', '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '--no-verify', '-m', 'scenario baseline']);

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
        return $this->diffMetrics()->diffSize($workspace);
    }

    /**
     * @return array{files: int, lines: int}
     */
    private function numstatSize(Process $stat): array
    {
        return $this->diffMetrics()->numstatSize($stat);
    }

    /**
     * @return array{files: int, lines: int}
     */
    private function untrackedSize(string $workspace, Process $others): array
    {
        return $this->diffMetrics()->untrackedSize($workspace, $others);
    }

    private function diffMetrics(): AtlasEvolutionScenarioDiffMetricsCalculator
    {
        return $this->diffMetrics ??= new AtlasEvolutionScenarioDiffMetricsCalculator();
    }

    /**
     * The candidate's full diff (tracked edits + untracked additions) as a
     * self-contained, reviewable artifact — the body of a propose-only proposal.
     */
    private function diffText(string $workspace, int $max = 20000): string
    {
        // Capture a VALID, applyable unified diff that includes NEW (untracked) files. A multi-file
        // refactor CREATES files (e.g. an extracted class), and both the cert gate and the merge
        // apply this diff with `git apply`. The previous version hand-built the new-file hunk as
        // `--- /dev/null\n+++ b/<path>\n<raw contents>` — MALFORMED: no `diff --git`/`new file mode`/
        // `index`/`@@` headers and no `+` line prefixes — so `git apply` silently dropped the new
        // file => its class never existed in the gate workspace => class-not-found => EVERY multi-file
        // refactor was rejected. Let GIT generate the patch: stage everything (incl untracked) so the
        // diff carries proper creation hunks, capture index-vs-HEAD, then RESET the index so the
        // judge's untracked census (`git ls-files --others`) is unaffected (a mixed reset leaves the
        // working tree untouched — the candidate's files remain on disk, just unstaged again).
        (new Process(['git', 'add', '-A'], $workspace, null, null, 30.0))->run();
        $diff = new Process(['git', 'diff', '--cached', '--no-ext-diff'], $workspace, null, null, 30.0);
        $diff->run();
        $text = (string) $diff->getOutput();
        (new Process(['git', 'reset', '-q'], $workspace, null, null, 30.0))->run();

        return strlen($text) <= $max ? $text : substr($text, 0, $max).'…';
    }

    /**
     * @param  array{files:int,lines:int}  $a
     * @param  array{files:int,lines:int}  $b
     */
    private function isSmallerDiff(array $a, array $b): bool
    {
        return $this->diffMetrics()->isSmallerDiff($a, $b);
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
