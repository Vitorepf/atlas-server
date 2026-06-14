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
        if ($wantComplexityProof) {
            $complexityProof = $this->complexityEarned($workspace, $changed);
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

        $metric = $this->computeMetric($metricKind, $allPassed, $lastStdout, $metricPattern);
        // For a verified refactor, the candidate's own AST max-per-method is the honest
        // ranking number — never trust a metric_pattern parse of provider stdout for the
        // ORDER either (the gate decision already used the AST; keep ordering consistent).
        if ($wantComplexityProof && is_array($complexityProof) && ($complexityProof['reduced'] ?? false)) {
            $metric = (float) $complexityProof['candidate_max'];
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
    private function complexityEarned(string $workspace, array $changed): ?array
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
        $candidateAgg = $decisionsGate ? ($candidate['total'] - ($candidate['methods'] ?? 0)) : $candidate['total'];
        $baselineAgg = $decisionsGate ? ($baseline['total'] - ($baseline['methods'] ?? 0)) : $baseline['total'];
        $reduced = $candidate['max_per_method'] < $baseline['max_per_method']
            && $candidateAgg <= $baselineAgg;

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
     * Acceptance commands run `php …` directly, and `./vendor/bin/*` shebangs
     * resolve through `/usr/bin/env php`. When the reprove pass is spawned by a
     * minimal-PATH parent (launchd/cron), `/bin/sh -c "php …"` cannot find php
     * and exits 127 — which the gate mis-reported as a transient `reproof_failed`
     * (the real "scheduled drain merges 0 but manual merges work" cause: the
     * interactive shell had php on PATH, launchd did not). PHP_BINARY is the
     * absolute path of the interpreter actually running this code, so prepending
     * its directory to PATH makes the subprocess resolve the same php regardless
     * of who spawned us. Prepend (not replace) so every other tool on the
     * inherited PATH still resolves. Returns null (inherit parent env) only when
     * PHP_BINARY is unavailable.
     *
     * @return array<string, string>|null
     */
    private function frozenCommandEnv(): ?array
    {
        $binary = PHP_BINARY;
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $binDir = \dirname($binary);
        if ($binDir === '' || $binDir === '.' || $binDir === DIRECTORY_SEPARATOR) {
            return null;
        }

        $currentPath = getenv('PATH');
        $path = (! is_string($currentPath) || $currentPath === '')
            ? $binDir
            : $binDir.PATH_SEPARATOR.$currentPath;

        return ['PATH' => $path];
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
    private function verdict(bool $passed, float $metric, array $details, array $acceptance): array
    {
        $metricValue = is_finite($metric) ? $metric : ($metric > 0 ? 1.0e308 : -1.0e308);

        return [
            'schema_version' => self::VERDICT_SCHEMA,
            'passed' => $passed,
            'metric' => $metricValue,
            'metric_finite' => is_finite($metric),
            'details' => $details,
            'acceptance_hash' => hash('sha256', json_encode([
                'commands' => AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []),
                'allowed_globs' => AiStringListNormalizer::trimmedStrings($acceptance['allowed_globs'] ?? ['**']),
                'frozen_globs' => AiStringListNormalizer::trimmedStrings($acceptance['frozen_globs'] ?? []),
                'metric_kind' => (string) ($acceptance['metric_kind'] ?? self::METRIC_GATE),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
