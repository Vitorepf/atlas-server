<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

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

        $metric = $this->computeMetric($metricKind, $allPassed, $lastStdout, $metricPattern);

        return $this->verdict($allPassed, $metric, [
            'rejected' => false,
            'reason' => $allPassed ? 'accepted' : 'acceptance_command_failed',
            'changed_files' => $changed,
            'command_results' => $commandResults,
            'metric_kind' => $metricKind,
            'diff_earned' => ($allPassed && (bool) ($acceptance['revert_recheck'] ?? false)) ? true : null,
        ], $acceptance);
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
        $process = Process::fromShellCommandline($command, $workspace, null, null, (float) $timeout);
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
