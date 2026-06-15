<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
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
        $addedLines = $this->addedLines($workspace);
        $mutants = [];
        $sampled = 0;
        foreach ($targets as $target) {
            if ($sampled >= $maxMutants) {
                break;
            }
            $path = $workspace.'/'.$target;
            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $mutation = $this->firstMutation($target, $original, $addedLines[$target] ?? null, $decisionOnly);
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
     * @return array{mutation_id:string,operator:string,content:string}|null
     */
    private function firstMutation(string $file, string $content, ?string $preferredText = null, bool $decisionOnly = false): ?array
    {
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

        // Refactor contracts (decisionOnly) confine mutation to the APPROVED diff's added lines
        // ONLY — never the full pre-existing file. Otherwise, when the added lines yield no decision
        // mutant, this fallback would mutate a decision operator in OLD, UNCHANGED code; a sibling
        // test covering that old code kills it -> a FALSE certify of an untested refactor (adversarial
        // panel, 2026-06-15). No producible added-line decision mutant => null => no_applicable_mutation
        // (fail-closed). Legacy (decisionOnly=false) keeps the original full-file fallback byte-identical.
        if ($decisionOnly) {
            return null;
        }

        return $this->mutationForText($file, $content, $decisionOnly);
    }

    /**
     * COSMETIC operators relocate/replace a string literal without touching control flow.
     * For a behaviour-preserving REFACTOR, a moved unasserted literal surviving is NOT
     * evidence the test is empty — it conflates "one relocated string is unasserted" with
     * "the refactor is untested". {@see mutationForText} skips these for refactor contracts.
     *
     * @var array<string,true>
     */
    private const COSMETIC_OPERATORS = [
        'return_string_literal' => true,
        'string_literal' => true,
    ];

    /**
     * @return array{mutation_id:string,operator:string,content:string}|null
     */
    private function mutationForText(string $file, string $content, bool $decisionOnly = false): ?array
    {
        $mutators = [
            'return_string_literal' => static fn (string $source): ?string => self::replaceFirst('/return\s+([\'"])(?:\\\\.|(?!\1).)*\1\s*;/', "return '__atlas_mutant__';", $source),
            'return_true' => static fn (string $source): ?string => self::replaceFirst('/return\s+true\s*;/', 'return false;', $source),
            'return_false' => static fn (string $source): ?string => self::replaceFirst('/return\s+false\s*;/', 'return true;', $source),
            'strict_equals' => static fn (string $source): ?string => self::replaceFirst('/===/', '!==', $source),
            'strict_not_equals' => static fn (string $source): ?string => self::replaceFirst('/!==/', '===', $source),
            'return_integer' => static fn (string $source): ?string => self::replaceFirstCallback('/return\s+(-?\d+)\s*;/', static fn (array $m): string => 'return '.(((int) $m[1]) === 0 ? '1' : '0').';', $source),
            'positive_comparison' => static fn (string $source): ?string => self::replaceFirst('/>\s*0/', '<= 0', $source),
            'job_dispatch_noop' => static fn (string $source): ?string => self::replaceFirst('/\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::dispatch\(\);/', ';', $source),
            'db_insert_noop' => static fn (string $source): ?string => self::replaceFirst('/->insert\(/', "->whereRaw('1 = 0')->update(", $source),
            'string_literal' => static fn (string $source): ?string => self::replaceFirst('/([\'"])(?:\\\\.|(?!\1).){1,160}\1/', "'__atlas_mutant__'", $source),
        ];

        foreach ($mutators as $operator => $mutator) {
            // Refactor contracts sample DECISION operators only — a surviving cosmetic literal
            // must not decide a behaviour-preserving refactor's fate. decisionOnly=false keeps
            // the original full-ordered, first-mutation-wins behaviour byte-identical.
            if ($decisionOnly && isset(self::COSMETIC_OPERATORS[$operator])) {
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

        return isset(self::COSMETIC_OPERATORS[$operator]) ? 'cosmetic' : 'decision';
    }

    private function replaceFirstLiteral(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }

        return substr_replace($haystack, $replacement, $pos, strlen($needle));
    }

    private static function replaceFirst(string $pattern, string $replacement, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace($pattern, $replacement, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
    }

    /**
     * @param  callable(array<int,string>):string  $callback
     */
    private static function replaceFirstCallback(string $pattern, callable $callback, string $source): ?string
    {
        $count = 0;
        $mutated = preg_replace_callback($pattern, $callback, $source, 1, $count);

        return $count > 0 && is_string($mutated) ? $mutated : null;
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
