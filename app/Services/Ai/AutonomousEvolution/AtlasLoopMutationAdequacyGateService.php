<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use Symfony\Component\Process\Process;

/**
 * L6-5 mutation adequacy gate.
 *
 * The frozen judge proves the candidate earns GREEN against the declared test.
 * This gate proves the test is not empty: one safe, temporary mutant must make
 * the same acceptance go RED. It mutates only the candidate gate workspace and
 * restores it before returning; it never writes the source checkout or changes
 * merge policy.
 */
final class AtlasLoopMutationAdequacyGateService
{
    public const SCHEMA = 'atlas.loop.mutation_adequacy_gate.v1';

    public const PROPERTY_SCHEMA = 'atlas.loop.property_adversarial_inputs.v1';

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(string $workspace, array $acceptance, array $changedFiles = [], array $options = []): array
    {
        if (! (bool) ($options['enabled'] ?? true)) {
            return $this->receipt('disabled', true, [], [], [], $this->propertyProbe($workspace, [], 1));
        }

        $commands = AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []);
        $timeout = max(1, (int) ($options['timeout_seconds'] ?? $acceptance['timeout_seconds'] ?? config('atlas.loop.mutation_adequacy_gate.timeout_seconds', 120)));
        $propertyCommands = AiStringListNormalizer::trimmedStrings($options['property_commands'] ?? $acceptance['property_commands'] ?? []);
        $propertyProbe = $this->propertyProbe($workspace, $propertyCommands, $timeout);

        $blockers = [];
        if (! is_dir($workspace)) {
            $blockers[] = 'workspace_missing';
        }
        if ($commands === []) {
            $blockers[] = 'acceptance_commands_missing';
        }
        if (! (bool) ($propertyProbe['passed'] ?? false)) {
            $blockers[] = 'property_adversarial_inputs_failed';
        }
        if ($blockers !== []) {
            return $this->receipt('blocked', false, $blockers, [], [], $propertyProbe);
        }

        $baseline = $this->runCommands($workspace, $commands, $timeout, $propertyProbe);
        if (! $baseline['passed']) {
            return $this->receipt('baseline_failed', false, ['baseline_acceptance_failed'], [], [$baseline], $propertyProbe);
        }

        $changedFiles = $changedFiles === [] ? $this->discoverChangedFiles($workspace) : $changedFiles;
        $targets = $this->mutationTargets($workspace, $changedFiles, $acceptance);
        if ($targets === []) {
            return $this->receipt('skipped_no_php_change', true, [], [], [$baseline], $propertyProbe);
        }

        $maxMutants = max(1, (int) ($options['max_mutants'] ?? config('atlas.loop.mutation_adequacy_gate.max_mutants', 1)));
        $decisionOnly = $this->refactorDecisionAware($acceptance, $options);

        // REFACTOR contracts (complexity_proof + metric_kind=minimize, flag ON) run the decision-aware
        // tiered path: prefer a DECISION mutant (real bar), fall back to a COSMETIC mutant confined to
        // the added lines (a KILLED cosmetic still proves the test exercises the new code), and only
        // SKIP-certify when nothing at all is producible. This turns the original false-reject of a
        // relocated-literal refactor AND the false-reject of a no-decision-op refactor into pass/skip,
        // without ever lowering the true bar (a surviving DECISION mutant still rejects).
        if ($decisionOnly) {
            return $this->evaluateRefactorContract($workspace, $commands, $timeout, $targets, $baseline, $propertyProbe);
        }

        $addedLines = $this->addedLines($workspace);
        $mutants = [];
        $sampled = 0;
        foreach ($targets as $target) {
            if ($sampled >= $maxMutants) {
                break;
            }
            $path = $workspace.'/'.$target;
            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $mutation = $this->firstMutation($target, $original, $addedLines[$target] ?? null, false, []);
            if ($mutation === null) {
                continue;
            }

            $sampled++;
            $mutantContent = $mutation['content'];
            file_put_contents($path, $mutantContent);
            try {
                $mutantRun = $this->runCommands($workspace, $commands, $timeout, $propertyProbe);
            } finally {
                file_put_contents($path, $original);
            }

            $killed = ! (bool) ($mutantRun['passed'] ?? false);
            $mutants[] = [
                'file' => $target,
                'mutation_id' => $mutation['mutation_id'],
                'operator' => $mutation['operator'],
                'original_hash' => 'sha256:'.hash('sha256', $original),
                'mutant_hash' => 'sha256:'.hash('sha256', $mutantContent),
                'killed' => $killed,
                'survived' => ! $killed,
                'command_results' => $mutantRun['results'],
            ];

            if (! $killed) {
                return $this->receipt('mutation_survived', false, ['mutation_survived'], $mutants, [$baseline], $propertyProbe);
            }
        }

        if ($mutants === []) {
            return $this->receipt('no_applicable_mutation', false, ['no_applicable_mutation'], [], [$baseline], $propertyProbe);
        }

        return $this->receipt('mutation_killed', true, [], $mutants, [$baseline], $propertyProbe);
    }

    /**
     * REFACTOR-contract tiered sampling (decisionOnly=true; flag-gated, opt-in). The tiers, per target,
     * preserve the TRUE bar while turning two legitimate refactors that the legacy hard-reject path
     * killed into pass/skip:
     *
     *   1. DECISION mutant (position-confined to the added lines, exact NEW-file index, drift-guarded):
     *        KILLED  -> mutation_killed (certified)        — the test covers the new branch.
     *        SURVIVED -> mutation_survived (REJECT)        — a real branch the test misses; bar kept.
     *   2. else COSMETIC mutant (same position confinement, e.g. a relocated string literal):
     *        KILLED  -> mutation_killed (certified)        — the test DOES exercise the new code via the
     *                                                         relocated literal; this fixes the
     *                                                         no-decision-op refactor false reject.
     *        SURVIVED -> skipped_cosmetic_survived (CERTIFIED, SKIP) — a relocated UNASSERTED literal is
     *                                                         not evidence of an empty test (the original
     *                                                         keystone insight; fixes the
     *                                                         string-relocating false reject).
     *   3. else (no mutant at all): skipped_no_refactor_mutant (CERTIFIED, SKIP) — the deterministic
     *        complexity gate + behaviour frozen test + consumer/refuter gates carry the proof.
     *
     * A DECISION mutant is ALWAYS preferred over a cosmetic one across all targets: only when NO target
     * can yield a decision mutant does a cosmetic outcome decide. That ordering is what keeps a real
     * surviving decision branch a hard reject even if some other target has a killable cosmetic. The
     * decision pass is EXHAUSTIVE over every decision-bearing target and a surviving decision mutant
     * OUTRANKS any killed decision mutant — so a covered (killed) decision on an earlier file can never
     * mask an uncovered (surviving) decision on a later file (Fix D), keeping the verdict order-independent.
     *
     * The max_mutants perf budget deliberately does NOT bound this method: the decision-survivor hunt
     * must be EXHAUSTIVE over every decision-bearing target (a surviving branch anywhere is fatal and
     * must never escape because of a sampling cap), and the cosmetic fallback already samples exactly
     * ONE mutant. Refactor diffs are small (a single-file extract), so this stays cheap.
     *
     * @param  list<string>  $commands
     * @param  list<string>  $targets
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $propertyProbe
     * @return array<string,mixed>
     */
    private function evaluateRefactorContract(string $workspace, array $commands, int $timeout, array $targets, array $baseline, array $propertyProbe): array
    {
        $addedLineMap = $this->addedLineMap($workspace);

        // ABSOLUTE DECISION PRIORITY (Fix C, false-certify probe 2026-06-14): a DECISION mutant on ANY
        // target is the real bar and MUST be checked before a cosmetic outcome on a different target can
        // decide. The earlier single-pass loop incremented one shared $sampled counter for BOTH families,
        // so under the live max_mutants=1 default a KILLED cosmetic on the FIRST diffed file consumed the
        // budget and `break`ed BEFORE a later file's SURVIVING uncovered decision branch was ever sampled
        // — certifying untested decision code purely by git's file ordering. Two ordered passes fix this
        // structurally: PASS 1 hunts decision mutants across all targets (max_mutants = the decision-probe
        // budget); only if NO decision mutant exists anywhere does PASS 2 fall back to ONE cosmetic.
        // This keeps the true bar: a surviving decision mutant on any target still hard-rejects.
        //
        // Fix D (false-certify probe round 2, 2026-06-14): PASS 1 must not `return mutation_killed` on the
        // FIRST decision mutant it kills — that re-opens the SAME ordering hole one level down. With the
        // live max_mutants=1 default a KILLED covered decision on the FIRST diffed file would consume the
        // budget and return certified BEFORE a LATER file's SURVIVING uncovered decision branch was ever
        // probed, so the verdict again depended on git's file order (A-first => mutation_killed/certified;
        // B-first => mutation_survived — confirmed by a two-decision-file probe). A SURVIVING decision
        // mutant ANYWHERE is fatal and outranks any number of killed ones, so the survivor hunt must be
        // EXHAUSTIVE over every decision-bearing target and CANNOT be capped by max_mutants (that cap is a
        // perf budget for SAMPLING, never a license for a surviving branch to escape). Refactor diffs are
        // inherently small (the complexity gate runs on a single-file extract), so one decision probe per
        // changed file is cheap and bounded. PASS 1 therefore: probes the first decision mutant on EVERY
        // target; a survivor on any target hard-rejects immediately (order-independent); a kill is
        // REMEMBERED but never short-circuits the hunt; only once ALL decision targets are proven
        // survivor-free does a remembered kill certify. True bar never lowered.

        // PASS 1 — DECISION mutants only, EXHAUSTIVE across ALL targets (not max_mutants-capped). A
        // survivor on any target rejects order-independently; the first kill is remembered and only
        // decides once every decision target is proven survivor-free.
        $killedDecision = null;
        foreach ($targets as $target) {
            $path = $workspace.'/'.$target;
            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $map = $addedLineMap[$target] ?? [];

            $decision = $this->firstAddedLineMutation($target, $original, $map, false);
            if ($decision === null) {
                continue;
            }
            $record = $this->runMutant($workspace, $path, $original, $target, $decision, $commands, $timeout, $propertyProbe);
            if (! $record['killed']) {
                // A surviving DECISION mutant is a real failure — reject immediately, order-independent,
                // and never downgrade to a cosmetic skip (that would hide a branch the test misses).
                return $this->receipt('mutation_survived', false, ['mutation_survived'], [$record], [$baseline], $propertyProbe);
            }
            // Remember the FIRST kill but keep hunting: a later target may carry a surviving decision the
            // test misses, which must outrank this kill regardless of git's file ordering.
            $killedDecision ??= $record;
        }

        // Every decision target proven survivor-free: a remembered kill certifies the refactor.
        if ($killedDecision !== null) {
            return $this->receipt('mutation_killed', true, [], [$killedDecision], [$baseline], $propertyProbe);
        }

        // PASS 2 — NO decision mutant anywhere: fall back to the FIRST COSMETIC mutant confined to an
        // added line. A relocated literal whose kill proves the test exercises the new code certifies;
        // a surviving relocated unasserted literal SKIP-certifies (not an empty-test signal).
        foreach ($targets as $target) {
            $path = $workspace.'/'.$target;
            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $map = $addedLineMap[$target] ?? [];

            $cosmetic = $this->firstAddedLineMutation($target, $original, $map, true);
            if ($cosmetic === null) {
                continue;
            }
            $record = $this->runMutant($workspace, $path, $original, $target, $cosmetic, $commands, $timeout, $propertyProbe);
            if ($record['killed']) {
                return $this->receipt('mutation_killed', true, [], [$record], [$baseline], $propertyProbe);
            }

            return $this->receipt('skipped_cosmetic_survived', true, [], [$record], [$baseline], $propertyProbe);
        }

        // TIER 3 — nothing producible from the added lines: SKIP-certify; the deterministic complexity
        // gate + frozen behaviour test + consumer/refuter gates carry the proof.
        return $this->receipt('skipped_no_refactor_mutant', true, [], [], [$baseline], $propertyProbe);
    }

    /**
     * Apply ONE mutant to the live file, run the acceptance, restore the file, and return the audit
     * record. Position confinement / drift guarding already happened in {@see firstAddedLineMutation}.
     *
     * @param  array{mutation_id:string,operator:string,content:string}  $mutation
     * @param  list<string>  $commands
     * @param  array<string,mixed>  $propertyProbe
     * @return array<string,mixed>
     */
    private function runMutant(string $workspace, string $path, string $original, string $target, array $mutation, array $commands, int $timeout, array $propertyProbe): array
    {
        $mutantContent = $mutation['content'];
        file_put_contents($path, $mutantContent);
        try {
            $mutantRun = $this->runCommands($workspace, $commands, $timeout, $propertyProbe);
        } finally {
            file_put_contents($path, $original);
        }

        $killed = ! (bool) ($mutantRun['passed'] ?? false);

        return [
            'file' => $target,
            'mutation_id' => $mutation['mutation_id'],
            'operator' => $mutation['operator'],
            'original_hash' => 'sha256:'.hash('sha256', $original),
            'mutant_hash' => 'sha256:'.hash('sha256', $mutantContent),
            'killed' => $killed,
            'survived' => ! $killed,
            'command_results' => $mutantRun['results'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function propertyProbe(string $workspace, array $commands, int $timeout): array
    {
        $cases = $this->adversarialNumericCases();
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->runCommand($workspace, $command, $timeout, [
                'ATLAS_MUTATION_PROPERTY_CASES' => $this->json($cases),
                'ATLAS_MUTATION_GATE' => 'property_probe',
            ]);
            if (! (bool) ($results[array_key_last($results)]['passed'] ?? false)) {
                break;
            }
        }

        $passed = count($results) === 0 || ! in_array(false, array_map(static fn (array $r): bool => (bool) ($r['passed'] ?? false), $results), true);

        return [
            'schema_version' => self::PROPERTY_SCHEMA,
            'status' => $commands === [] ? 'generated_without_runner' : ($passed ? 'generated_and_runner_passed' : 'runner_failed'),
            'passed' => $passed,
            'case_count' => count($cases),
            'families' => ['nan', 'positive_infinity', 'negative_infinity', 'float_overflow', 'integer_bounds'],
            'cases' => $cases,
            'runner_commands' => $commands,
            'runner_results' => $results,
        ];
    }

    /**
     * ACDE Tier-0 #4 — DETERMINISTIC OVERFIT PROBE. A weak engine told "make this test pass" games it
     * with a literal short-circuit instead of the general logic — the observed
     * `if (func_num_args() === 1) return 5;` and the `if ($x === 2) return 5;` test-value special-case.
     * Mutation sampling can miss the gamed line; this catches the pattern STATICALLY off the diff's
     * ADDED lines (the only thing the candidate authored), with zero model dependency. Deliberately
     * HIGH-PRECISION (only the unambiguous hardcode shapes, returning a BARE literal) so it never
     * false-rejects honest code. Returns a refusal reason, or null when no overfit pattern is present.
     *
     * @param  array<string,string>|list<string>  $addedLinesByFile  diff-added lines (the certifier's addedLines() map, or a flat list)
     */
    public function detectOverfitShortCircuit(array $addedLinesByFile): ?string
    {
        $blob = implode("\n", array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $addedLinesByFile));
        if (trim($blob) === '') {
            return null;
        }

        // A bare-literal return: `return <int|float|'str'|"str"|true|false|null>;` — NOT an expression,
        // variable, property, or call (those are legitimate). This is the "hardcoded answer" half.
        $literal = '(?:-?\d+(?:\.\d+)?|\'[^\']*\'|"[^"]*"|true|false|null)';
        $hasLiteralReturn = preg_match('/\breturn\s+'.$literal.'\s*;/i', $blob) === 1;

        // Pattern A — argument-count short-circuit (the exact observed gaming): func_num_args()/func_get_args()
        // appears in NEW code AND a bare-literal return is present.
        if ($hasLiteralReturn && preg_match('/\bfunc_(?:num|get)_args\s*\(\s*\)/', $blob) === 1) {
            return 'overfit_constant_return:arg_count_short_circuit';
        }

        // Pattern B — single-line input special-case: `if (<expr> ==|=== <literal>) [{] return <literal>;`
        // i.e. hardcode the test's input value, then hardcode its expected answer. Single-line form only
        // (a strong, low-false-positive gaming signal; multi-line if-blocks are intentionally not flagged).
        if (preg_match('/\bif\s*\([^)]*(?:===|==)\s*'.$literal.'[^)]*\)\s*\{?\s*return\s+'.$literal.'\s*;/i', $blob) === 1) {
            return 'overfit_constant_return:literal_input_special_case';
        }

        return null;
    }

    /**
     * @return list<array{id:string,family:string,php_expression:string,contract:string}>
     */
    private function adversarialNumericCases(): array
    {
        return [
            ['id' => 'nan', 'family' => 'nan', 'php_expression' => 'NAN', 'contract' => 'fail_closed_or_clamp'],
            ['id' => 'positive_inf', 'family' => 'positive_infinity', 'php_expression' => 'INF', 'contract' => 'fail_closed_or_clamp'],
            ['id' => 'negative_inf', 'family' => 'negative_infinity', 'php_expression' => '-INF', 'contract' => 'fail_closed_or_clamp'],
            ['id' => 'float_overflow_pos', 'family' => 'float_overflow', 'php_expression' => '1.0E+309', 'contract' => 'fail_closed_or_clamp'],
            ['id' => 'float_overflow_neg', 'family' => 'float_overflow', 'php_expression' => '-1.0E+309', 'contract' => 'fail_closed_or_clamp'],
            ['id' => 'php_float_max', 'family' => 'float_overflow', 'php_expression' => 'PHP_FLOAT_MAX', 'contract' => 'bounded_result'],
            ['id' => 'php_int_max', 'family' => 'integer_bounds', 'php_expression' => 'PHP_INT_MAX', 'contract' => 'bounded_result'],
            ['id' => 'php_int_min', 'family' => 'integer_bounds', 'php_expression' => 'PHP_INT_MIN', 'contract' => 'bounded_result'],
        ];
    }

    /**
     * @param  list<string>  $commands
     * @return array{passed:bool,results:list<array<string,mixed>>}
     */
    private function runCommands(string $workspace, array $commands, int $timeout, array $propertyProbe): array
    {
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->runCommand($workspace, $command, $timeout, [
                'ATLAS_MUTATION_PROPERTY_CASES' => $this->json($propertyProbe['cases'] ?? []),
                'ATLAS_MUTATION_GATE' => 'acceptance',
            ]);
            if (! (bool) ($results[array_key_last($results)]['passed'] ?? false)) {
                return ['passed' => false, 'results' => $results];
            }
        }

        return ['passed' => true, 'results' => $results];
    }

    /**
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function runCommand(string $workspace, string $command, int $timeout, array $env): array
    {
        $process = Process::fromShellCommandline($command, $workspace, $this->commandEnv($env), null, (float) $timeout);
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
     * @param  array<string,string>  $env
     * @return array<string,string>
     */
    private function commandEnv(array $env): array
    {
        $binary = PHP_BINARY;
        if (is_string($binary) && $binary !== '') {
            $binDir = \dirname($binary);
            $currentPath = getenv('PATH');
            $env['PATH'] = $binDir.((is_string($currentPath) && $currentPath !== '') ? PATH_SEPARATOR.$currentPath : '');
        }

        return $env;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $acceptance
     * @return list<string>
     */
    private function mutationTargets(string $workspace, array $changedFiles, array $acceptance): array
    {
        $frozenGlobs = AiStringListNormalizer::trimmedStrings($acceptance['frozen_globs'] ?? []);
        $targets = [];
        foreach ($changedFiles as $file) {
            $file = trim($file);
            if ($file === '' || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            if ($this->matchesAny($file, $frozenGlobs)) {
                continue;
            }
            if (is_file($workspace.'/'.$file)) {
                $targets[] = $file;
            }
        }

        return AiStringListNormalizer::uniqueStrings($targets);
    }

    /**
     * @return list<string>
     */
    private function discoverChangedFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
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

        return array_keys($files);
    }

    /**
     * Prefer mutating the approved diff, not unrelated historical code in the
     * same large file. That makes the gate an anti-empty-test proof for this
     * proposal instead of a random mutation of old surface area.
     *
     * @return array<string,string>
     */
    private function addedLines(string $workspace): array
    {
        $process = new Process(['git', 'diff', '--unified=0', '--no-ext-diff'], $workspace, null, null, 30.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return [];
        }

        $byFile = [];
        $current = null;
        foreach (preg_split('/\R/', (string) $process->getOutput()) ?: [] as $line) {
            if (str_starts_with($line, '+++ b/')) {
                $current = substr($line, 6);
                $byFile[$current] ??= [];

                continue;
            }
            if ($current !== null && str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $byFile[$current][] = substr($line, 1);
            }
        }

        $out = [];
        foreach ($byFile as $file => $lines) {
            if ($lines !== []) {
                $out[$file] = implode("\n", $lines);
            }
        }

        return $out;
    }

    /**
     * Map each ADDED line to its exact NEW-file line number (1-based) per file.
     *
     * Fix A (adversarial panel, 2026-06-14): {@see addedLines} concatenates non-contiguous
     * git hunks into one block that is NOT verbatim in the file, so the decision-aware path
     * fell back to a per-line strpos that can land a mutation on a byte-identical line in OLD,
     * UNCHANGED code (false certify). This map lets the gate mutate the diff's added line AT
     * ITS EXACT INDEX, so a mutation can NEVER touch unchanged code — even for multi-hunk diffs
     * and duplicate line text. The hunk header `@@ -a,b +c,d @@` declares the added run starts
     * at NEW line c; each `+` line in that hunk is consecutive from c.
     *
     * @return array<string,array<int,string>> file => [newLineNumber => addedLineText]
     */
    private function addedLineMap(string $workspace): array
    {
        $process = new Process(['git', 'diff', '--unified=0', '--no-ext-diff'], $workspace, null, null, 30.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return [];
        }

        $byFile = [];
        $current = null;
        $newLine = 0;
        foreach (preg_split('/\R/', (string) $process->getOutput()) ?: [] as $line) {
            if (str_starts_with($line, '+++ b/')) {
                $current = substr($line, 6);
                $byFile[$current] ??= [];
                $newLine = 0;

                continue;
            }
            if ($current === null) {
                continue;
            }
            if (str_starts_with($line, '@@')) {
                // @@ -a,b +c,d @@  -> the added run for this hunk starts at NEW line c.
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                    $newLine = (int) $m[1];
                }

                continue;
            }
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                if ($newLine > 0) {
                    $byFile[$current][$newLine] = substr($line, 1);
                    $newLine++;
                }
            }
            // --unified=0 emits no context lines, so any non-+ line ends the current run;
            // the next @@ header re-seeds $newLine. Nothing else to track.
        }

        $out = [];
        foreach ($byFile as $file => $lines) {
            if ($lines !== []) {
                $out[$file] = $lines;
            }
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $addedLineMap  NEW-file line number => added line text (Fix A)
     * @return array{mutation_id:string,operator:string,content:string}|null
     */
    private function firstMutation(string $file, string $content, ?string $preferredText = null, bool $decisionOnly = false, array $addedLineMap = []): ?array
    {
        // Refactor contracts (decisionOnly) POSITION-CONFINE the mutation: every added line is
        // mutated AT ITS EXACT NEW-file index (Fix A, adversarial panel 2026-06-14). The legacy
        // strpos-based path could land a per-line mutation on a byte-identical line in OLD,
        // UNCHANGED code (false certify); index mutation makes that structurally impossible even
        // for multi-hunk diffs and duplicate line text. No added line yields a decision mutant =>
        // null => no_applicable_mutation (fail-closed, no free pass into old code).
        if ($decisionOnly) {
            return $this->firstAddedLineMutation($file, $content, $addedLineMap);
        }

        $preferredText = is_string($preferredText) ? trim($preferredText, "\n") : '';
        if ($preferredText !== '') {
            $preferredMutation = $this->mutationForText($file, $preferredText, $decisionOnly);
            if ($preferredMutation !== null && str_contains($content, $preferredText)) {
                $mutatedContent = $this->replaceFirstLiteral($content, $preferredText, $preferredMutation['content']);
                if ($mutatedContent !== $content) {
                    $preferredMutation['content'] = $mutatedContent;

                    return $preferredMutation;
                }
            }

            foreach (preg_split('/\R/', $preferredText) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $lineMutation = $this->mutationForText($file, $line, $decisionOnly);
                if ($lineMutation !== null && str_contains($content, $line)) {
                    $mutatedContent = $this->replaceFirstLiteral($content, $line, $lineMutation['content']);
                    if ($mutatedContent !== $content) {
                        $lineMutation['content'] = $mutatedContent;

                        return $lineMutation;
                    }
                }
            }
        }

        return $this->mutationForText($file, $content, $decisionOnly);
    }

    /**
     * Fix A: mutate exactly ONE added line at its NEW-file index, never via strpos.
     *
     * Splits the live file into lines, finds the first added line (by NEW line number) that the
     * selected mutators can change, mutates THAT line in place, and rebuilds the file. Because the
     * mutation is applied at the diff-declared index — and we additionally verify the file's text at
     * that index still equals the added line — a duplicate line in OLD code can never be the target.
     * If the file at the declared index no longer matches the added line (e.g. unexpected drift),
     * that line is skipped rather than mutated elsewhere, preserving the fail-closed contract.
     *
     * $cosmeticOnly selects which mutator family is allowed at each added line:
     *   false (default) -> DECISION operators only (cosmetics skipped) — the real refactor bar.
     *   true            -> COSMETIC operators only (decisions skipped) — the fallback that proves the
     *                      test exercises the new code via a relocated literal. Both modes share the
     *                      SAME position-confinement + drift guard + comment/docblock skip, so a
     *                      cosmetic mutant can never land on old code either.
     *
     * @param  array<int,string>  $addedLineMap  NEW-file line number => added line text
     * @return array{mutation_id:string,operator:string,content:string}|null
     */
    private function firstAddedLineMutation(string $file, string $content, array $addedLineMap, bool $cosmeticOnly = false): ?array
    {
        if ($addedLineMap === []) {
            return null;
        }

        // Preserve the file's exact line endings on rebuild.
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (! is_array($lines)) {
            return null;
        }

        ksort($addedLineMap);
        foreach ($addedLineMap as $lineNumber => $addedText) {
            $index = $lineNumber - 1; // NEW line numbers are 1-based.
            if ($index < 0 || ! array_key_exists($index, $lines)) {
                continue;
            }
            // POSITION CONFINEMENT: the live file at this exact index must still be the added line.
            // If it drifted, skip — never search elsewhere (would risk landing on old code).
            if ($lines[$index] !== $addedText) {
                continue;
            }
            // A docblock/comment CONTINUATION added line carries no comment opener on the line
            // itself (e.g. ' * @param array<string,mixed> $x', ' * @return Collection<int,Foo>'), so
            // per-line masking cannot see it and the generic's '<'/'>' would become a FAKE relational
            // mutant that the test can never kill -> false reject of the refactor. Skip pure
            // comment/docblock lines; real code lines keep inline comment/string masking below.
            $trimmedAdded = ltrim($addedText);
            if ($trimmedAdded !== '' && (
                str_starts_with($trimmedAdded, '*')
                || str_starts_with($trimmedAdded, '//')
                || str_starts_with($trimmedAdded, '/*')
                || str_starts_with($trimmedAdded, '#')
            )) {
                continue;
            }
            // decisionOnly stays true for BOTH modes (cosmetic-only is selected separately below) so the
            // generic relational ops in mutationForText keep their comment/string masking guards; the
            // $cosmeticOnly flag flips WHICH family is allowed, not the masking.
            $lineMutation = $this->mutationForText($file, $addedText, true, $cosmeticOnly);
            if ($lineMutation === null || $lineMutation['content'] === $addedText) {
                continue;
            }

            $mutatedLines = $lines;
            $mutatedLines[$index] = $lineMutation['content'];
            $mutatedContent = implode($eol, $mutatedLines);
            if ($mutatedContent === $content) {
                continue;
            }

            return [
                'mutation_id' => substr(hash('sha256', $file.'|'.$lineMutation['operator'].'|'.$lineNumber.'|'.$mutatedContent), 0, 16),
                'operator' => $lineMutation['operator'],
                'content' => $mutatedContent,
            ];
        }

        return null;
    }

    /**
    /**
     * @param  bool  $cosmeticOnly  when true, ONLY cosmetic operators are allowed (the inverse of the
     *                              decisionOnly skip) — used by the refactor cosmetic-fallback tier so a
     *                              relocated string literal can prove the test exercises the new code.
     * @return array{mutation_id:string,operator:string,content:string}|null
     */
    private function mutationForText(string $file, string $content, bool $decisionOnly = false, bool $cosmeticOnly = false): ?array
    {
        $mutators = AtlasLoopMutationOperators::map();

        foreach ($mutators as $operator => $mutator) {
            $isCosmetic = isset(AtlasLoopMutationOperators::COSMETIC_OPERATORS[$operator]);
            // Refactor contracts sample DECISION operators only — a surviving cosmetic literal
            // must not decide a behaviour-preserving refactor's fate. decisionOnly=false keeps
            // the original full-ordered, first-mutation-wins behaviour byte-identical.
            if ($decisionOnly && ! $cosmeticOnly && $isCosmetic) {
                continue;
            }
            // The refactor COSMETIC-fallback tier wants the inverse: only a cosmetic operator (a
            // relocated string literal). Skip every decision operator so the produced mutant is
            // exactly the cosmetic one whose kill proves the test exercises the new code.
            if ($cosmeticOnly && ! $isCosmetic) {
                continue;
            }
            $mutated = $mutator($content);
            if (is_string($mutated) && $mutated !== $content) {
                return [
                    'mutation_id' => substr(hash('sha256', $file.'|'.$operator.'|'.$mutated), 0, 16),
                    'operator' => $operator,
                    'content' => $mutated,
                ];
            }
        }

        return null;
    }

    /**
     * A refactor contract (complexity_proof + metric_kind=minimize) — the IDENTICAL predicate to
     * {@see AtlasLoopSemanticImplementationCertifier::complexityProofRequired} so the gate and the
     * certifier never disagree on what is a refactor. Decision-aware sampling is flag-gated
     * (default OFF = byte-identical legacy first-mutation-wins), opt-in and fail-closed.
     *
     * @param  array<string,mixed>  $acceptance
     * @param  array<string,mixed>  $options
     */
    private function refactorDecisionAware(array $acceptance, array $options = []): bool
    {
        $enabled = (bool) ($options['refactor_decision_aware']
            ?? config('atlas.loop.mutation_adequacy_gate.refactor_decision_aware', false));

        return $enabled
            && (bool) ($acceptance['complexity_proof'] ?? false)
            && (string) ($acceptance['metric_kind'] ?? '') === AtlasEvolutionFrozenJudge::METRIC_MINIMIZE;
    }

    /**
     * Which mutator family produced the deciding mutant (audit-only; never changes the verdict).
     *
     * @param  list<array<string,mixed>>  $mutants
     */
    private function decisiveOperatorFamily(array $mutants): string
    {
        if ($mutants === []) {
            return 'none';
        }
        $operator = (string) ($mutants[array_key_last($mutants)]['operator'] ?? '');
        if ($operator === '') {
            return 'none';
        }

        return isset(AtlasLoopMutationOperators::COSMETIC_OPERATORS[$operator]) ? 'cosmetic' : 'decision';
    }

    private function replaceFirstLiteral(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }

        return substr_replace($haystack, $replacement, $pos, strlen($needle));
    }

    /**
     * @param  list<string>  $globs
     */
    private function matchesAny(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            $glob = trim($glob);
            if ($glob === '') {
                continue;
            }
            if ($path === $glob || fnmatch($glob, $path, FNM_PATHNAME) || fnmatch($glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<array<string,mixed>>  $mutants
     * @param  list<array<string,mixed>>  $baseline_runs
     * @return array<string,mixed>
     */
    private function receipt(string $status, bool $certified, array $blockers, array $mutants, array $baseline_runs, array $propertyProbe): array
    {
        $receipt = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'certified' => $certified,
            'blockers' => $blockers,
            'baseline_runs' => $baseline_runs,
            'property_adversarial_inputs' => $propertyProbe,
            'mutants_sampled' => count($mutants),
            'mutants_killed' => count(array_filter($mutants, static fn (array $m): bool => (bool) ($m['killed'] ?? false))),
            'mutants_survived' => count(array_filter($mutants, static fn (array $m): bool => (bool) ($m['survived'] ?? false))),
            'decisive_operator_family' => $this->decisiveOperatorFamily($mutants),
            'mutants' => $mutants,
            'invariants' => [
                'proposal_only' => true,
                'source_checkout_mutated' => false,
                'workspace_restored' => true,
                'merge_gate_changed' => false,
                'never_merge_changed' => false,
            ],
            'generated_at' => time(),
        ];
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', $this->json([
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'certified' => $certified,
            'blockers' => $blockers,
            'mutants_sampled' => count($mutants),
            'mutants_killed' => $receipt['mutants_killed'],
            'mutants_survived' => $receipt['mutants_survived'],
        ]));

        return $receipt;
    }

    private function json(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'...' : $text;
    }
}
