<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use App\Services\Ai\Support\AiStringListNormalizer;
use Symfony\Component\Process\Process;

/**
 * The FROZEN JUDGE — the autoresearch `evaluate_bpb` of the Atlas evolution loop.
 *
 * It scores a candidate workspace against a task's FROZEN acceptance contract and
 * is the single source of "did this change actually improve, honestly?". It is
 * deliberately the one thing the loop is NEVER allowed to edit (the loop touches
 * only the target; the judge, the frozen tests and the metric are out of reach).
 *
 * Provider-agnostic BY CONSTRUCTION: the judge scores a *workspace*, it does not
 * know or care which provider (Hermes, codex, or anything else) produced the
 * candidate. Removing any provider changes nothing here.
 *
 * Three Goodhart-guards live in this class:
 *   1. TAMPER  — the candidate may not touch any frozen path (tests/harness/metric).
 *   2. SCOPE   — every changed file must fall inside the task's allowed paths.
 *   3. RE-PROOF — the judge RE-RUNS the frozen acceptance commands itself in the
 *                 candidate workspace; it never trusts the loop's self-report.
 */
final class AtlasEvolutionFrozenJudge
{
    public const VERDICT_SCHEMA = 'atlas.evolution.frozen_judge_verdict.v1';

    public const METRIC_GATE = 'gate';        // pass/fail -> 1.0 / 0.0

    public const METRIC_MINIMIZE = 'minimize'; // a number to drive down (lower = better)

    public const METRIC_MAXIMIZE = 'maximize'; // a number to drive up (higher = better)

    public function __construct(private ?AtlasLoopBenchmarkHarness $benchmark = null) {}

    /** Lazily-built deterministic PERF-CERT harness — the judge's OWN speedup measure. */
    private function benchmark(): AtlasLoopBenchmarkHarness
    {
        return $this->benchmark ??= new AtlasLoopBenchmarkHarness();
    }

    /**
     * Score a candidate workspace.
     *
     * @param  array{
     *     commands: list<string>,
     *     allowed_globs?: list<string>,
     *     frozen_globs?: list<string>,
     *     metric_kind?: string,
     *     metric_pattern?: string,
     *     timeout_seconds?: int
     * }  $acceptance  The FROZEN acceptance contract for the task.
     * @return array<string,mixed>  atlas.evolution.frozen_judge_verdict.v1
     */
    public function score(string $workspace, array $acceptance): array
    {
        $commands = AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []);
        $allowedGlobs = AiStringListNormalizer::trimmedStrings($acceptance['allowed_globs'] ?? ['**']);
        $frozenGlobs = AiStringListNormalizer::trimmedStrings($acceptance['frozen_globs'] ?? []);
        // Fail-closed (sweep O-1): acceptance sem frozen_globs dava ZERO proteção de
        // tamper — o candidato podia editar o próprio teste de acceptance e o diff_earned
        // (que reverte o diff inteiro) ainda marcava "earned". Os arquivos referenciados
        // pelos commands são SEMPRE congelados implicitamente quando o contrato não diz nada.
        if ($frozenGlobs === []) {
            $frozenGlobs = $this->commandFileRefs($commands);
        }
        $metricKind = (string) ($acceptance['metric_kind'] ?? self::METRIC_GATE);
        $metricPattern = isset($acceptance['metric_pattern']) ? (string) $acceptance['metric_pattern'] : null;
        $timeout = max(1, (int) ($acceptance['timeout_seconds'] ?? 600));

        if (! is_dir($workspace)) {
            return $this->verdict(false, 0.0, [
                'rejected' => true,
                'reason' => 'workspace_missing',
                'changed_files' => [],
            ], $acceptance);
        }

        $changed = $this->changedFiles($workspace, (bool) ($acceptance['strict_untracked'] ?? false));

        // Guard 1 — TAMPER: candidate may not touch any frozen path.
        $tampered = array_values(array_filter($changed, fn (string $f): bool => $this->matchesAny($f, $frozenGlobs)));
        if ($tampered !== []) {
            return $this->verdict(false, 0.0, [
                'rejected' => true,
                'reason' => 'frozen_path_tampered',
                'tampered_files' => $tampered,
                'changed_files' => $changed,
            ], $acceptance);
        }

        // Guard 2 — SCOPE: every changed file must be inside an allowed path.
        $outOfScope = array_values(array_filter($changed, fn (string $f): bool => ! $this->matchesAny($f, $allowedGlobs)));
        if ($outOfScope !== []) {
            return $this->verdict(false, 0.0, [
                'rejected' => true,
                'reason' => 'out_of_scope_change',
                'out_of_scope_files' => $outOfScope,
                'changed_files' => $changed,
            ], $acceptance);
        }

        // Guard 2b — REQUIRED OUTPUTS (ARBOR-GRAFT MG1): the first POSITIVE merge guard. Every Atlas
        // guard is negative (don't-touch / stay-inside); Arbor's required_outputs asserts an artifact MUST
        // exist. DATA-declared in the frozen acceptance (a provider can never author or weaken it — it is
        // covered by the freeze), CODE-enforced here, CONJUNCTIVE: it runs AFTER tamper+scope (so those
        // keep precedence) and rejects in ADDITION to — never instead of — the other guards. Absent key =
        // byte-identical (no required outputs declared => loop unchanged).
        $requiredOutputs = AiStringListNormalizer::trimmedStrings($acceptance['required_outputs'] ?? []);
        if ($requiredOutputs !== []) {
            $missingOutputs = $this->missingRequiredOutputs($workspace, $requiredOutputs);
            if ($missingOutputs !== []) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    'reason' => 'missing_required_output',
                    'missing_required_outputs' => $missingOutputs,
                    'changed_files' => $changed,
                ], $acceptance);
            }
        }

        // Guard 3 — RE-PROOF: the judge re-runs the frozen acceptance ITSELF.
        $commandResults = [];
        $allPassed = true;
        $lastStdout = '';
        foreach ($commands as $command) {
            $result = $this->runFrozenCommand($command, $workspace, $timeout);
            $commandResults[] = $result;
            $lastStdout = $result['stdout'];
            if (! $result['passed']) {
                $allPassed = false;
                break; // fail fast: one red command sinks the candidate
            }
        }

        // Guard 4 — DIFF-EARNED (anti-fake): for materialized/framework targets where a
        // test could pass on ambient state the diff did NOT earn, prove honesty in the
        // grind environment ITSELF: revert the candidate's edits to the bare baseline and
        // re-run acceptance — it MUST now go RED. If it stays green with the diff reverted,
        // the change is fake (the test does not depend on it) and the candidate is rejected.
        // No env assumption: the proof happens in this very workspace, so it cannot be
        // fooled by where the generator's RED-check ran. Opt-in via `revert_recheck`.
        if ($allPassed && (bool) ($acceptance['revert_recheck'] ?? false)) {
            $earned = $this->diffEarned($workspace, $commands, $timeout);
            if ($earned !== true) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    'reason' => 'acceptance_not_diff_earned', // green even with the diff reverted -> fake
                    'diff_earned' => $earned, // false = fake-green; null = could not verify (fail closed)
                    'changed_files' => $changed,
                    'command_results' => $commandResults,
                ], $acceptance);
            }
        }

        // Guard 4b — COMPLEXITY-EARNED (governed refactor, default-inert). When the FROZEN
        // acceptance is a `refactor_reduce_complexity` contract (metric_kind=minimize AND
        // complexity_proof=true) AND the operator flag is ON, certification is a CONJUNCTION:
        // behavior MUST be preserved (Guard 3 above re-ran the FROZEN sibling test — which the
        // loop can never edit, frozen_globs — and it stayed GREEN) AND a REAL AST cyclomatic
        // measure MUST drop. complexityEarned() reuses diffEarned's git-stash machinery to
        // measure CANDIDATE then BASELINE with the judge's OWN parser (never the provider's
        // claimed number), so it is ungameable: a behavior change fails Guard 3, a
        // delete-the-branch cheat turns the sibling test RED (Guard 3), and a no-op leaves
        // candidate>=baseline -> rejected here. When the flag is OFF the branch is never
        // entered and the judge is BYTE-IDENTICAL to today.
        $complexityProof = null;
        $wantComplexityProof = $allPassed
            && (bool) ($acceptance['complexity_proof'] ?? false)
            && $metricKind === self::METRIC_MINIMIZE
            && (bool) config('atlas.loop.refactor_complexity_proof', false);
        // STRUCTURAL lane (extract-class): a structural_proof contract rides ON TOP of complexity_proof,
        // swapping the verdict to the per-method-identity gate. Config-gated (default OFF) -> inert: a
        // structural_proof task with the flag off falls back to complexityReduced (new-file-lock rejects
        // the extract-class), so the running soak is byte-identical until the operator flips it.
        $structuralProof = (bool) ($acceptance['structural_proof'] ?? false)
            && (bool) config('atlas.loop.complexity_method_identity_gate', false);
        if ($wantComplexityProof) {
            $complexityProof = $this->complexityEarned($workspace, $changed, $structuralProof);
            $reduced = is_array($complexityProof) ? ($complexityProof['reduced'] ?? null) : null;
            if ($reduced !== true) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    // fail-closed: not reduced, OR null/error measuring (could not verify).
                    'reason' => 'complexity_not_reduced',
                    'complexity_proof' => $complexityProof,
                    'changed_files' => $changed,
                    'command_results' => $commandResults,
                ], $acceptance);
            }
        }

        // Guard 4c — PERFORMANCE-EARNED (governed perf-cert, default-inert). When the FROZEN
        // acceptance is a `refactor_performance_proof` contract (metric_kind=minimize AND
        // performance_proof=true) AND the operator flag is ON, certification is a CONJUNCTION:
        // behavior MUST be preserved (Guard 3 above re-ran the FROZEN sibling command — which the
        // loop can never edit, frozen_globs — and it stayed GREEN) AND a REAL, variance-guarded
        // speedup MUST be proven by the judge's OWN benchmark harness over the FROZEN
        // benchmark_command's stdout samples (never a provider-claimed speedup number). The
        // variance guard (candidate_median + candidate_iqr < baseline_median) is what makes it
        // REAL not no-op: a noisy candidate whose band reaches past baseline is rejected. Missing
        // or garbled samples fail CLOSED. When the flag is OFF the branch is never entered and the
        // judge is BYTE-IDENTICAL to today.
        $perfProof = null;
        $wantPerformanceProof = $allPassed
            && (bool) ($acceptance['performance_proof'] ?? false)
            && $metricKind === self::METRIC_MINIMIZE
            && (bool) config('atlas.loop.refactor_performance_proof', true);
        if ($wantPerformanceProof) {
            $perfProof = $this->performanceEarned($workspace, $acceptance, $timeout);
            $significant = is_array($perfProof) ? ($perfProof['significant'] ?? null) : null;
            if ($significant !== true) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    // fail-closed: not significant, OR null/garbled samples (could not verify).
                    'reason' => 'performance_not_proven',
                    'performance_proof' => $perfProof,
                    'changed_files' => $changed,
                    'command_results' => $commandResults,
                ], $acceptance);
            }
        }

        // Guard 4d — DEDUP-EARNED (governed clone-unification, default-inert). When the FROZEN acceptance is a
        // dedup contract (metric_kind=minimize AND dedup_proof=true) AND the operator flag is ON, certification
        // is a CONJUNCTION: behavior MUST be preserved (Guard 3 above re-ran the FROZEN per-member sibling tests
        // — frozen_globs, which the loop can never edit — and they stayed GREEN) AND the targeted clone
        // duplication MUST be REMOVED (a count-drop measured by the judge's OWN parser, never a provider number):
        // a no-op "dedup" that adds a helper but leaves both bodies keeps the shared count >=2 => rejected here.
        // When the flag is OFF the branch is never entered and the judge is BYTE-IDENTICAL to today.
        $dedupProof = null;
        $wantDedupProof = $allPassed
            && (bool) ($acceptance['dedup_proof'] ?? false)
            && $metricKind === self::METRIC_MINIMIZE
            && (bool) config('atlas.loop.refactor_dedup_proof', false);
        if ($wantDedupProof) {
            $dedupProof = $this->dedupEarned($workspace, (array) ($acceptance['clone_target'] ?? []));
            $removed = is_array($dedupProof) ? ($dedupProof['removed'] ?? null) : null;
            if ($removed !== true) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    // fail-closed: not removed, OR null/error measuring (could not verify).
                    'reason' => 'dedup_not_earned',
                    'dedup_proof' => $dedupProof,
                    'changed_files' => $changed,
                    'command_results' => $commandResults,
                ], $acceptance);
            }
        }

        // Guard 4e — WIRED-EARNED (governed orphan-wiring, default-inert). When the FROZEN acceptance is a
        // wiring contract (wired_proof=true) AND the operator flag is ON, certification is a CONJUNCTION:
        //   (a) WAS-DEAD/NOW-WIRED — the orphan went from ZERO production callers (git-stashed baseline) to >=1
        //       (candidate), measured by the judge's OWN caller grep, never a provider claim; AND
        //   (b) MEANINGFULLY LOAD-BEARING — neutralizing the orphan's METHOD bodies (a throw injected first; the
        //       constructor left intact) turns a FROZEN command RED. A cosmetic `new Orphan()` never calls a
        //       method (constructor intact => green => REJECTED); a hardcoded test value doesn't call the orphan
        //       (=> REJECTED); only a wiring that genuinely INVOKES the orphan's behavior breaks => certified.
        // This is the sound replacement for the farmable wiredEarned+revert_recheck (which a hardcode+cosmetic-
        // ref could pass). Flag OFF => never entered => the judge is BYTE-IDENTICAL to today.
        $wiredProof = null;
        $wantWiredProof = $allPassed
            && (bool) ($acceptance['wired_proof'] ?? false)
            && (bool) config('atlas.loop.refactor_wired_proof', false);
        if ($wantWiredProof) {
            $orphanPath = is_array($acceptance['wired_target'] ?? null) ? (string) ($acceptance['wired_target']['orphan_path'] ?? '') : '';
            $wiredProof = $this->wiredEarned($workspace, $orphanPath, $commands, $timeout);
            if (($wiredProof['earned'] ?? null) !== true) {
                return $this->verdict(false, 0.0, [
                    'rejected' => true,
                    // fail-closed: orphan still 0 callers, OR neutralization did not kill the test (cosmetic), OR
                    // null/error measuring (could not verify).
                    'reason' => 'wiring_not_earned',
                    'wired_proof' => $wiredProof,
                    'changed_files' => $changed,
                    'command_results' => $commandResults,
                ], $acceptance);
            }
        }

        $metric = $this->computeMetric($metricKind, $allPassed, $lastStdout, $metricPattern);
        // For a verified refactor, the candidate's own AST max-per-method is the honest
        // ranking number — never trust a metric_pattern parse of provider stdout for the
        // ORDER either (the gate decision already used the AST; keep ordering consistent).
        if ($wantComplexityProof && is_array($complexityProof) && ($complexityProof['reduced'] ?? false)) {
            $metric = (float) $complexityProof['candidate_max'];
        }
        // Honest ranking for a verified perf-cert: the candidate's OWN benchmarked median
        // (lower = faster = better for MINIMIZE), never a provider-claimed speedup number.
        if ($wantPerformanceProof && is_array($perfProof) && ($perfProof['significant'] ?? false)) {
            $metric = (float) ($perfProof['candidate']['median_ns'] ?? $metric);
        }

        return $this->verdict($allPassed, $metric, [
            'rejected' => false,
            'reason' => $allPassed ? 'accepted' : 'acceptance_command_failed',
            'changed_files' => $changed,
            'command_results' => $commandResults,
            'metric_kind' => $metricKind,
            'diff_earned' => ($allPassed && (bool) ($acceptance['revert_recheck'] ?? false)) ? true : null,
            '_complexity_reduction' => $complexityProof,
        ], $acceptance);
    }

    /**
     * The ungameable refactor proof: did the candidate genuinely REDUCE complexity while
     * preserving behavior (Guard 3 already proved behavior with the frozen sibling test)?
     * Reuses diffEarned's git-stash machinery: the candidate diff is live in the workspace,
     * so measure the CANDIDATE first, then stash to the committed baseline, measure BASELINE,
     * and restore. The measure is the judge's OWN deterministic AST cyclomatic pass over the
     * files the diff touched (never a provider-claimed number).
     *
     * AGGREGATION (the declared metric — see acceptance.complexity_aggregation): the PRIMARY
     * comparison is max-per-method cyclomatic, so simplifying or extracting from the WORST
     * method registers a real drop even when the file total stays flat; AND the file total is
     * required NOT to increase, so "split one ugly method into two uglier ones" cannot game the
     * max while ballooning the file. reduced = candidate_max < baseline_max AND
     * candidate_total <= baseline_total.
     *
     * @param  list<string>  $changed  the SCOPE/TAMPER census (already inside allowed_globs)
     * @return array{baseline_max:int,candidate_max:int,baseline_total:int,candidate_total:int,reduced:bool}|null
     *                                  null = could not verify (no diff to stash / git error / nothing measured) -> fail closed
     */
    private function complexityEarned(string $workspace, array $changed, bool $structural = false): ?array
    {
        $phpFiles = array_values(array_filter(
            $changed,
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        if ($phpFiles === []) {
            return null; // nothing measurable -> fail closed
        }
        $absPaths = array_map(static fn (string $f): string => $workspace.'/'.ltrim($f, '/'), $phpFiles);

        $analyzer = $this->signalAnalyzer();

        // CANDIDATE first: the diff is live in the working tree right now.
        $candidate = $analyzer->aggregateComplexity($absPaths);
        if (! $candidate['measured']) {
            return null; // candidate unparseable -> fail closed
        }

        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return null; // no diff to stash (no-op candidate) -> fail closed
        }

        try {
            $baseline = $analyzer->aggregateComplexity($absPaths);
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }

        if (! $baseline['measured']) {
            return null; // baseline unparseable -> fail closed
        }

        // Secondary "no new complexity" guard compares DECISION POINTS (total − methods), not raw
        // total: extract-method (the only way to cut max-per-method) adds +1 to total per new method
        // (each method's base cyclomatic is 1), so a raw-total gate falsely rejects legitimate
        // extraction (proven live: a refactor cutting the worst method 19→4 was rejected only because
        // 10 new helper methods raised total 29→38, though real decisions fell 21→20). Anti-gaming
        // still holds via the max-gate (no method may exceed baseline max). Flag default ON; flip OFF
        // to restore the prior raw-total behavior.
        $decisionsGate = (bool) config('atlas.loop.complexity_decisions_gate', true);
        $perFileGate = (bool) config('atlas.loop.complexity_per_file_max_gate', true);
        $candidateAgg = $decisionsGate ? ($candidate['total'] - ($candidate['methods'] ?? 0)) : $candidate['total'];
        $baselineAgg = $decisionsGate ? ($baseline['total'] - ($baseline['methods'] ?? 0)) : $baseline['total'];
        // PER-FILE reduced verdict (shared single source with the certifier so the two cannot drift):
        // a multi-file cluster refactor that simplifies the hub but not the cluster's global-worst
        // method (in an UNTOUCHED sibling) used to be false-rejected. Single-file is byte-identical.
        // STRUCTURAL lane (extract-class): route to the per-method-identity verdict, which supersedes
        // the per-file-max + new-file-lock (an extract-class legitimately creates a new file) under the
        // anti-relocation invariant. Same single source as the certifier so the two cannot drift.
        $reduced = $structural
            ? AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate)
            : AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, $decisionsGate, $perFileGate);

        return [
            'baseline_max' => $baseline['max_per_method'],
            'candidate_max' => $candidate['max_per_method'],
            'baseline_total' => $baseline['total'],
            'candidate_total' => $candidate['total'],
            'baseline_decisions' => $baseline['total'] - ($baseline['methods'] ?? 0),
            'candidate_decisions' => $candidate['total'] - ($candidate['methods'] ?? 0),
            'reduced' => $reduced,
        ];
    }

    /** Lazily-built deterministic AST analyzer — the judge's OWN complexity measure. */
    private function signalAnalyzer(): AtlasLoopSignalAnalyzer
    {
        return new AtlasLoopSignalAnalyzer();
    }

    /**
     * The ungameable dedup proof: did the candidate genuinely REMOVE the targeted clone duplication (behavior
     * already proven by Guard 3's frozen per-member sibling tests)? Mirrors complexityEarned's git-stash
     * machinery: the candidate diff is live, so read the member files' CANDIDATE source, stash to the committed
     * BASELINE, read it, restore — then the PURE {@see \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupProof}
     * count-drop measure decides with the judge's OWN parser (never a provider-claimed number). A no-op that
     * leaves both clone bodies keeps the shared count >=2 => removed=false. null = could not verify (no clone /
     * <2 members / git error / no-op-no-diff) -> fail closed.
     *
     * @param  array<string,mixed>  $cloneTarget  acceptance.clone_target {members:[{path,...}|path], ...}
     * @return array{target_hash:string,baseline_count:int,candidate_count:int,removed:bool}|null
     */
    private function dedupEarned(string $workspace, array $cloneTarget): ?array
    {
        $members = [];
        foreach ((array) ($cloneTarget['members'] ?? []) as $m) {
            $rel = ltrim(is_array($m) ? (string) ($m['path'] ?? '') : (string) $m, '/');
            if ($rel !== '' && str_ends_with($rel, '.php')) {
                $members[$rel] = true;
            }
        }
        $members = array_keys($members);
        if (count($members) < 2) {
            return null; // need >=2 member files to have a duplication to remove -> fail closed
        }

        // CANDIDATE source first: the diff is live in the working tree right now.
        $candidate = [];
        foreach ($members as $rel) {
            $candidate[$rel] = (string) @file_get_contents($workspace.'/'.$rel);
        }

        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return null; // no diff to stash (no-op candidate) -> fail closed
        }
        try {
            $baseline = [];
            foreach ($members as $rel) {
                $baseline[$rel] = (string) @file_get_contents($workspace.'/'.$rel);
            }
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }

        return (new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupProof)->evaluate($baseline, $candidate);
    }

    /**
     * The ungameable wiring proof (Guard 4e): is a former ORPHAN now MEANINGFULLY wired? CONJUNCTION of
     *   (a) was-dead/now-wired — the orphan's production callers went 0 (git-stashed baseline) -> >=1 (candidate),
     *       via the same FQCN caller oracle the discovery model uses; AND
     *   (b) load-bearing — neutralizing the orphan's method bodies turns a FROZEN command RED ({@see orphanMethodKills}).
     * null on any unverifiable leg => fail closed (earned=false).
     *
     * @param  list<string>  $commands
     * @return array{baseline_callers:int,candidate_callers:int,method_kills:bool,earned:bool}|null
     */
    private function wiredEarned(string $workspace, string $orphanRel, array $commands, int $timeout): ?array
    {
        $orphanRel = ltrim($orphanRel, '/');
        if ($orphanRel === '' || ! str_ends_with($orphanRel, '.php')) {
            return null;
        }

        // (b) MEANINGFUL first, on the live candidate tree (no git surgery): neutralize the orphan, re-run.
        $methodKills = $this->orphanMethodKills($workspace, $orphanRel, $commands, $timeout);

        // (a) WAS-DEAD/NOW-WIRED: candidate callers (live), then git-stash to the committed baseline.
        $candidate = (new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService($workspace))
            ->callerPaths([$orphanRel])[$orphanRel] ?? null;
        if (! is_array($candidate)) {
            return null;
        }
        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return null;
        }
        try {
            $baseline = (new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService($workspace))
                ->callerPaths([$orphanRel])[$orphanRel] ?? null;
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }
        if (! is_array($baseline)) {
            return null;
        }

        $wasOrphanNowWired = count($baseline) === 0 && count($candidate) >= 1;

        return [
            'baseline_callers' => count($baseline),
            'candidate_callers' => count($candidate),
            'method_kills' => $methodKills,
            'earned' => $wasOrphanNowWired && $methodKills === true,
        ];
    }

    /**
     * The meaningful-wiring probe: neutralize the orphan's METHOD bodies ({@see AtlasLoopMethodNeutralizer} —
     * a throw injected first, constructor untouched) IN PLACE, re-run the frozen commands, and report whether
     * ANY went RED. A genuine wiring that invokes the orphan diverges (RED = killed); a cosmetic instantiation
     * or a hardcoded value never calls a method (GREEN = not killed). Always restores the original source.
     * Returns false (not killed) on any error — fail-closed.
     *
     * @param  list<string>  $commands
     */
    private function orphanMethodKills(string $workspace, string $orphanRel, array $commands, int $timeout): bool
    {
        $abs = $workspace.'/'.ltrim($orphanRel, '/');
        $original = @file_get_contents($abs);
        if (! is_string($original) || $original === '') {
            return false; // can't read the orphan -> cannot prove load-bearing -> fail closed
        }
        $neutralized = (new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMethodNeutralizer)->neutralize($original);
        if ($neutralized === null || $neutralized === $original) {
            return false; // unparseable / nothing to neutralize -> cannot prove -> fail closed
        }

        $red = false;
        try {
            file_put_contents($abs, $neutralized);
            foreach ($commands as $command) {
                $result = $this->runFrozenCommand($command, $workspace, $timeout);
                if (! $result['passed']) {
                    $red = true; // neutralizing the orphan broke a frozen command => the orphan is load-bearing
                    break;
                }
            }
        } finally {
            file_put_contents($abs, $original); // ALWAYS restore the candidate's real source
        }

        return $red;
    }

    /**
     * The ungameable perf-cert proof: did the candidate genuinely run FASTER (Guard 3 already
     * proved behavior with the frozen sibling command)? Runs the FROZEN benchmark_command
     * OUT-OF-PROCESS in the candidate workspace; the command must emit JSON
     * {"baseline":[int ns...],"candidate":[int ns...]} on stdout. The judge then summarizes both
     * sample sets with its OWN harness and certifies via the harness's threshold + variance guard
     * (candidate_median + candidate_iqr < baseline_median). NEVER trusts a provider-claimed
     * speedup number — only the benchmark_command's measured samples, which are covered by the
     * frozen acceptance (the loop can never author or weaken them).
     *
     * @param  array<string,mixed>  $acceptance
     * @return array{faster:bool,speedup:float,significant:bool,baseline:array<string,mixed>,candidate:array<string,mixed>}|null
     *                                  null = could not verify (no/garbled/empty samples) -> fail closed
     */
    private function performanceEarned(string $workspace, array $acceptance, int $timeout): ?array
    {
        $command = trim((string) ($acceptance['benchmark_command'] ?? ''));
        if ($command === '') {
            return null; // no benchmark command declared -> fail closed
        }

        $result = $this->runFrozenCommand($command, $workspace, $timeout);
        if (! $result['passed']) {
            return null; // benchmark command itself failed -> fail closed
        }

        $decoded = json_decode($result['stdout'], true);
        if (! is_array($decoded)) {
            return null; // garbled stdout -> fail closed
        }

        $baseline = $this->intSamples($decoded['baseline'] ?? null);
        $candidate = $this->intSamples($decoded['candidate'] ?? null);
        if ($baseline === null || $candidate === null) {
            return null; // missing / non-numeric / empty samples -> fail closed
        }

        $b = $this->benchmark()->summarize($baseline);
        $c = $this->benchmark()->summarize($candidate);

        return $this->benchmark()->certify($b, $c, (float) ($acceptance['min_speedup'] ?? 1.10));
    }

    /**
     * Coerce a decoded JSON value into a non-empty list of int nanosecond samples, or null
     * (fail-closed) when it is not an array, is empty, or holds a non-numeric element.
     *
     * @return list<int>|null
     */
    private function intSamples(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }
        $samples = [];
        foreach ($value as $item) {
            if (! is_numeric($item)) {
                return null;
            }
            $samples[] = (int) $item;
        }

        return $samples;
    }

    /**
     * The anti-fake re-proof: does the candidate's diff genuinely EARN the green? Stash the
     * candidate's working-tree edits (back to the committed baseline) IN THIS workspace, re-run
     * acceptance, and require it to FAIL (RED). Restore the candidate afterwards.
     *
     * @param  list<string>  $commands
     * @return bool|null  true = earned (baseline is RED without the diff); false = fake (still
     *                    green); null = could not verify (no diff to stash / git error) -> fail closed
     */
    private function diffEarned(string $workspace, array $commands, int $timeout): ?bool
    {
        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        // No local changes to save => the candidate was a no-op; a "passing" no-op is fake by
        // definition (the test was green without any change). Fail closed.
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return null;
        }

        try {
            foreach ($commands as $command) {
                $result = $this->runFrozenCommand($command, $workspace, $timeout);
                if (! $result['passed']) {
                    return true; // baseline is RED without the diff -> the diff earned the green
                }
            }

            return false; // baseline still GREEN with the diff reverted -> fake
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }
    }

    /** True when a stash entry exists (the push actually captured changes). */
    private function stashCreated(string $workspace): bool
    {
        $list = new Process(['git', 'stash', 'list'], $workspace, null, null, 30.0);
        $list->run();

        return trim((string) $list->getOutput()) !== '';
    }

    /**
     * Compare two verdicts under the task's metric kind. Returns true if $a is
     * STRICTLY better than $b (the keep/discard rule — only strict wins are kept).
     */
    public function isStrictlyBetter(array $a, array $b, string $metricKind = self::METRIC_GATE): bool
    {
        $aPass = (bool) ($a['passed'] ?? false);
        $bPass = (bool) ($b['passed'] ?? false);

        // A passing candidate always beats a failing one; a failing one never wins.
        if ($aPass !== $bPass) {
            return $aPass;
        }
        if (! $aPass) {
            return false;
        }

        $am = (float) ($a['metric'] ?? 0.0);
        $bm = (float) ($b['metric'] ?? 0.0);

        return match ($metricKind) {
            self::METRIC_MINIMIZE => $am < $bm,
            self::METRIC_MAXIMIZE => $am > $bm,
            default => false, // pure gate: two passes tie — caller breaks ties (e.g. smaller diff)
        };
    }

    /**
     * Caminhos de arquivo referenciados diretamente pelos commands da acceptance
     * (ex.: `php tests/Frozen/FooTest.php` → `tests/Frozen/FooTest.php`). Usados como
     * frozen_globs implícitos quando o contrato não declara nenhum.
     *
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function commandFileRefs(array $commands): array
    {
        $refs = [];
        foreach ($commands as $command) {
            foreach (preg_split('/\s+/', $command) ?: [] as $token) {
                $token = trim($token, "'\"");
                if ($token !== '' && str_contains($token, '/') && str_ends_with($token, '.php') && ! str_starts_with($token, '-')) {
                    $refs[$token] = true;
                }
            }
        }

        return array_keys($refs);
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $workspace, bool $strictUntracked = false): array
    {
        // SCOPE/TAMPER census. By default untracked files honor .gitignore/.git/info/exclude
        // (so the engineering loop ignores legitimately-ignored build artifacts). When a task
        // sets strict_untracked (the finance flow does), DROP --exclude-standard so a candidate
        // cannot hide sibling files behind a self-authored .gitignore or .git/info/exclude.
        $untracked = $strictUntracked
            ? ['git', 'ls-files', '--others']
            : ['git', 'ls-files', '--others', '--exclude-standard'];
        $files = [];
        // Fail-closed (sweep O-1): mesmo no modo padrão, arquivos de REGRA de ignore nunca
        // escapam do censo — um candidato podia esconder um sibling com lógica real atrás
        // de um .gitignore auto-autorado (que se auto-ignora) ou de .git/info/exclude,
        // invisível para os guards de TAMPER e SCOPE.
        if (! $strictUntracked) {
            $ignoreRules = new Process(['git', 'ls-files', '--others'], $workspace, null, null, 30.0);
            $ignoreRules->run();
            if ($ignoreRules->isSuccessful() || $ignoreRules->getExitCode() === 1) {
                foreach (preg_split('/\R/', trim((string) $ignoreRules->getOutput())) ?: [] as $line) {
                    $line = trim($line);
                    $base = basename($line);
                    if ($line !== '' && ($base === '.gitignore' || $base === '.gitattributes')) {
                        $files[$line] = true;
                    }
                }
            }
            $infoExclude = $workspace.'/.git/info/exclude';
            if (is_file($infoExclude) && trim(preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($infoExclude)) ?? '') !== '') {
                $files['.git/info/exclude'] = true;
            }
        }
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            // STAGED edits: `git add` removes a file from BOTH the unstaged diff and the untracked
            // set, so a provider that stages its work would be invisible to the scope/tamper census.
            ['git', 'diff', '--cached', '--name-only', '--no-ext-diff'],
            $untracked,
        ] as $argv) {
            $process = new Process($argv, $workspace, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_values(array_keys($files));
    }

    /**
     * @return array{command: string, passed: bool, exit_code: int, stdout: string, stderr: string}
     */
    private function runFrozenCommand(string $command, string $workspace, int $timeout): array
    {
        $process = Process::fromShellCommandline($command, $workspace, $this->frozenCommandEnv(), null, (float) $timeout);
        $process->run();
        $exit = $process->getExitCode() ?? 1;

        return [
            'command' => $command,
            'passed' => $exit === 0,
            'exit_code' => $exit,
            'stdout' => $this->excerpt((string) $process->getOutput()),
            'stderr' => $this->excerpt((string) $process->getErrorOutput()),
        ];
    }

    /**
     * Environment for frozen acceptance commands.
     *
     * Acceptance commands can run generated PHPUnit/Artisan tests. They must
     * resolve the same PHP binary AND must never inherit the operator's real DB
     * settings, otherwise a schema-destructive test in a throwaway worktree can
     * mutate the live loop runtime database.
     *
     * @return array<string,string|false>
     */
    private function frozenCommandEnv(): array
    {
        return AtlasLoopHermeticCommandEnvironment::forAcceptance();
    }

    private function computeMetric(string $kind, bool $passed, string $stdout, ?string $pattern): float
    {
        if ($kind === self::METRIC_GATE) {
            return $passed ? 1.0 : 0.0;
        }
        if (! $passed) {
            // a failing candidate has no meaningful number; worst by construction
            return $kind === self::METRIC_MINIMIZE ? INF : -INF;
        }
        if ($pattern === null) {
            return $passed ? 1.0 : 0.0;
        }
        if (preg_match($pattern, $stdout, $m) === 1 && isset($m[1]) && is_numeric($m[1])) {
            return (float) $m[1];
        }

        // pattern declared but not found in a passing run = inconclusive number; treat as worst
        return $kind === self::METRIC_MINIMIZE ? INF : -INF;
    }

    /**
     * @param  list<string>  $globs
     */
    /**
     * ARBOR-GRAFT MG1 — globs from required_outputs that match NO file/dir present in the workspace.
     * Literal paths use is_file/is_dir; wildcard patterns use glob() (GLOB_BRACE). required_outputs are
     * DATA-declared in the frozen acceptance, so concrete artifact paths are the expected shape.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    private function missingRequiredOutputs(string $workspace, array $required): array
    {
        $missing = [];
        $root = rtrim($workspace, '/');
        foreach ($required as $glob) {
            $rel = ltrim((string) $glob, '/');
            if ($rel === '') {
                continue;
            }
            if (! preg_match('/[*?\[{]/', $rel)) {
                $present = is_file($root.'/'.$rel) || is_dir($root.'/'.$rel);
            } else {
                $hits = glob($root.'/'.$rel, GLOB_BRACE);
                $present = $hits !== false && $hits !== [];
            }
            if (! $present) {
                $missing[] = $glob;
            }
        }

        return $missing;
    }

    private function matchesAny(string $path, array $globs): bool
    {
        $path = ltrim($path, '/');
        foreach ($globs as $glob) {
            $glob = ltrim(trim($glob), '/');
            if ($glob === '') {
                continue;
            }
            if ($glob === '**' || $glob === '*') {
                return true;
            }
            // fnmatch with FNM_PATHNAME would block '**'; normalise '**' to match across '/'.
            $regex = $this->globToRegex($glob);
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function globToRegex(string $glob): string
    {
        $out = '';
        $len = strlen($glob);
        for ($i = 0; $i < $len; $i++) {
            $c = $glob[$i];
            if ($c === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $out .= '.*';
                    $i++;
                } else {
                    $out .= '[^/]*';
                }
            } elseif ($c === '?') {
                $out .= '[^/]';
            } else {
                $out .= preg_quote($c, '#');
            }
        }

        return '#^'.$out.'$#';
    }

    private function excerpt(string $value, int $max = 4000): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max).'…';
    }

    /**
     * @param  array<string,mixed>  $details
     * @param  array<string,mixed>  $acceptance
     * @return array<string,mixed>
     */
    /**
     * The canonical FROZEN acceptance fingerprint — SINGLE SOURCE so verdict() (grind-time) and the
     * merge-boundary reprove (AtlasLoopProposalPromotionGate, ACDE #8) can never drift: a contract swapped
     * between cert and merge produces a different hash and is caught fail-closed. The composition is FROZEN
     * (commands / allowed_globs / frozen_globs / metric_kind) — changing it would invalidate every persisted
     * acceptance_hash, so it must stay byte-identical to what stamped the stored hashes.
     *
     * @param  array<string,mixed>  $acceptance
     */
    public static function acceptanceHash(array $acceptance): string
    {
        return hash('sha256', json_encode([
            'commands' => AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []),
            'allowed_globs' => AiStringListNormalizer::trimmedStrings($acceptance['allowed_globs'] ?? ['**']),
            'frozen_globs' => AiStringListNormalizer::trimmedStrings($acceptance['frozen_globs'] ?? []),
            'metric_kind' => (string) ($acceptance['metric_kind'] ?? self::METRIC_GATE),
        ], JSON_THROW_ON_ERROR));
    }

    private function verdict(bool $passed, float $metric, array $details, array $acceptance): array
    {
        $metricValue = is_finite($metric) ? $metric : ($metric > 0 ? 1.0e308 : -1.0e308);

        return [
            'schema_version' => self::VERDICT_SCHEMA,
            'passed' => $passed,
            'metric' => $metricValue,
            'metric_finite' => is_finite($metric),
            'details' => $details,
            'acceptance_hash' => self::acceptanceHash($acceptance),
            // ARBOR-GRAFT J1 — audit-only provenance: this verdict was produced by re-running the frozen
            // contract OUT-OF-PROCESS, never trusting a self-report. Pure constant, never caller-supplied,
            // and intentionally OUTSIDE the acceptance_hash above (does not alter the frozen fingerprint).
            'provenance' => 'verified_by_judge',
        ];
    }
}
