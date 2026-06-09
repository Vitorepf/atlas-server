<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Builds the targeted repair-agent feedback context for the AP-786 repair loop.
 *
 * The old repair prompt fed the provider only a generic "fix the failing test"
 * instruction plus loose diagnostics, so a repair attempt could not see (a) the
 * exact failed test/error, (b) the precise scope it may touch, (c) the correct
 * namespace for a misplaced class, (d) the exact command that re-validates the
 * fix, or (e) the diff/merge that was just rejected. Without this the same broken
 * diff is produced again, wasting provider calls on a 10h run.
 *
 * This service derives all of that from the failed owner runtime result, the
 * slice's allowed/forbidden files, the declared validation command and the
 * merge governor's rejection reason, into a single machine-readable context with
 * the nine canonical fields the repair agent needs. It is a pure read model: it
 * never executes anything and never mutates the owner result.
 */
final class RepairAgentFeedbackContextBuilderService
{
    public const SCHEMA = 'atlas.software_company_stewardship.area_focus_loop.repair_agent_feedback_context.v1';

    /**
     * @param  array<string,mixed>  $input
     *                                      - owner_result:           array<string,mixed> failed AP-759 owner result
     *                                      - allowed_files:          list<string> files the diff may touch
     *                                      - forbidden_files:        list<string> files that must NOT be touched
     *                                      - validation_command:     string|null exact command to re-validate the fix
     *                                      - validation_commands:    list<string> (fallback when validation_command absent)
     *                                      - rejected_diff:          string|null the diff that was rejected
     *                                      - merge_rejection_reason: string|null exact reason from the merge governor
     *                                      - sandbox_current_commit: string|null current sandbox commit hash
     *                                      - expected_namespace:     string|null correct PHP namespace for a new class
     *                                      - repair_attempt_number:  int 1-based attempt index
     *                                      - worktree_path:          string optional worktree root for capsule/log reads
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $ownerResult = is_array($input['owner_result'] ?? null) ? $input['owner_result'] : [];
        $allowedFiles = AreaFocusStringListNormalizer::trimmedStrings($input['allowed_files'] ?? []);
        $forbiddenFiles = AreaFocusStringListNormalizer::trimmedStrings($input['forbidden_files'] ?? []);
        $worktree = trim((string) ($input['worktree_path'] ?? ''));

        $failedTest = $this->failedTest($ownerResult);
        $testErrorOutput = $this->testErrorOutput($ownerResult, $worktree);
        $lintErrors = $this->lintErrors($ownerResult, $worktree);
        $failedFile = $this->failedFile($ownerResult, $lintErrors, $allowedFiles);

        $validationCommand = $this->validationCommand($input);
        $expectedNamespace = $this->expectedNamespace($input, $lintErrors, $allowedFiles);

        $repairAttemptNumber = max(1, (int) ($input['repair_attempt_number'] ?? 1));

        return [
            'schema_version' => self::SCHEMA,
            'failed_test' => $failedTest,
            'test_error_output' => $testErrorOutput,
            'lint_errors' => $lintErrors,
            'failed_file' => $failedFile,
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $forbiddenFiles,
            'expected_namespace' => $expectedNamespace,
            'validation_command' => $validationCommand,
            'rejected_diff' => $this->nullableString($input['rejected_diff'] ?? null),
            'merge_rejection_reason' => $this->nullableString($input['merge_rejection_reason'] ?? null),
            'sandbox_current_commit' => $this->nullableString($input['sandbox_current_commit'] ?? null),
            'repair_attempt_number' => $repairAttemptNumber,
        ];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    private function failedTest(array $ownerResult): ?string
    {
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (! is_array($capsule)) {
                continue;
            }
            $failing = trim((string) ($capsule['failing_test'] ?? ''));
            if ($failing !== '') {
                return $failing;
            }
        }

        $primary = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failing_test', ''));

        return $primary !== '' ? $primary : null;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    private function testErrorOutput(array $ownerResult, string $worktree): ?string
    {
        foreach ($this->capsulePayloads($ownerResult, $worktree) as $payload) {
            foreach (['primary_error_excerpt', 'primary_error', 'error'] as $key) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    return mb_substr($value, 0, 4000);
                }
            }
        }

        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        if ($debugReason !== '') {
            return $debugReason;
        }

        // Fallback: use command-level stderr when senior-loop debug detail is absent.
        // This covers the senior_loop_execution_not_passed case where the CLI exits
        // non-zero but produces no structured debug_loop capsule.
        $stderrExcerpt = trim((string) data_get($ownerResult, 'runtime_invocation.command_result.stderr_excerpt', ''));

        return $stderrExcerpt !== '' ? mb_substr($stderrExcerpt, 0, 4000) : null;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return list<array{file:string,line:int|null,message:string}>
     */
    private function lintErrors(array $ownerResult, string $worktree): array
    {
        $errors = [];
        $sources = [];
        foreach ($this->capsulePayloads($ownerResult, $worktree) as $payload) {
            foreach (['primary_error_excerpt', 'primary_error', 'error', 'failure_signature'] as $key) {
                $value = (string) ($payload[$key] ?? '');
                if ($value !== '') {
                    $sources[] = $value;
                }
            }
        }
        $sources[] = (string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', '');

        foreach ($sources as $source) {
            // PSR-4 autoload non-compliance is the dominant repeated-repair cause:
            // a class placed in the wrong file/namespace. Capture file (and the
            // expected-namespace hint when present) so the repair can move it.
            if (preg_match('/located in (\S+\.php) does not comply with psr-4/i', $source, $m) === 1) {
                $file = $this->normalizePath($m[1]);
                $errors[] = [
                    'file' => $file,
                    'line' => null,
                    'message' => 'PSR-4 autoload violation: class located in '.$file.' does not match its namespace.',
                ];

                continue;
            }
            // Generic "file.php:line" lint/parse error location.
            if (preg_match('/(\S+\.php)(?::|\son\sline\s)(\d+)/i', $source, $m) === 1) {
                $errors[] = [
                    'file' => $this->normalizePath($m[1]),
                    'line' => (int) $m[2],
                    'message' => mb_substr(trim($source), 0, 400),
                ];
            }
        }

        return $this->uniqueLintErrors($errors);
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  list<array{file:string,line:int|null,message:string}>  $lintErrors
     * @param  list<string>  $allowedFiles
     */
    private function failedFile(array $ownerResult, array $lintErrors, array $allowedFiles): ?string
    {
        if ($lintErrors !== []) {
            return $lintErrors[0]['file'];
        }

        $changed = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        foreach ($changed as $file) {
            if (! str_ends_with($file, 'Test.php')) {
                return $file;
            }
        }
        if ($changed !== []) {
            return $changed[0];
        }

        // Fall back to the first non-test allowed file as the most likely fix site.
        foreach ($allowedFiles as $file) {
            if (! str_ends_with($file, 'Test.php')) {
                return $file;
            }
        }

        return $allowedFiles[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validationCommand(array $input): ?string
    {
        $explicit = trim((string) ($input['validation_command'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        foreach (AreaFocusStringListNormalizer::trimmedStrings($input['validation_commands'] ?? []) as $command) {
            // Prefer a focused test command over generic lint checks like
            // `git diff --check` so the repair re-validates the actual gap.
            if (preg_match('/phpunit|artisan test/i', $command) === 1) {
                return $command;
            }
        }

        $first = AreaFocusStringListNormalizer::trimmedStrings($input['validation_commands'] ?? [])[0] ?? '';

        return $first !== '' ? $first : null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array{file:string,line:int|null,message:string}>  $lintErrors
     * @param  list<string>  $allowedFiles
     */
    private function expectedNamespace(array $input, array $lintErrors, array $allowedFiles): ?string
    {
        $explicit = trim((string) ($input['expected_namespace'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        // Derive the canonical namespace for the failing PHP file from its path
        // (PSR-4: app/ => App\, tests/ => Tests\) so a misplaced class can be
        // moved without a second wrong guess.
        $candidate = $lintErrors[0]['file'] ?? null;
        if ($candidate === null) {
            foreach ($allowedFiles as $file) {
                if (str_ends_with($file, '.php')) {
                    $candidate = $file;
                    break;
                }
            }
        }

        return $candidate !== null ? $this->namespaceForPath($candidate) : null;
    }

    private function namespaceForPath(string $path): ?string
    {
        $path = ltrim(trim($path), './');
        if (! str_ends_with($path, '.php')) {
            return null;
        }
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        if ($dir === '') {
            return null;
        }
        $segments = explode('/', $dir);
        $root = array_shift($segments);
        $prefix = match ($root) {
            'app' => 'App',
            'tests' => 'Tests',
            'database' => 'Database',
            default => ucfirst($root),
        };

        $studly = array_map(static fn (string $s): string => str_replace(
            ' ',
            '',
            ucwords(str_replace(['-', '_'], ' ', $s)),
        ), $segments);

        return $studly === [] ? $prefix : $prefix.'\\'.implode('\\', $studly);
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return list<array<string,mixed>>
     */
    private function capsulePayloads(array $ownerResult, string $worktree): array
    {
        $payloads = [];
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (! is_array($capsule)) {
                continue;
            }
            // Inline capsule payload (already on the owner result).
            if (isset($capsule['primary_error_excerpt']) || isset($capsule['failing_test']) || isset($capsule['failure_signature'])) {
                $payloads[] = $capsule;
            }
            $ref = trim((string) ($capsule['ref'] ?? ''));
            if ($ref === '' || $worktree === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
                continue;
            }
            $path = rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'atlas-dev'.DIRECTORY_SEPARATOR.$ref;
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }

        return $payloads;
    }

    /**
     * @param  list<array{file:string,line:int|null,message:string}>  $errors
     * @return list<array{file:string,line:int|null,message:string}>
     */
    private function uniqueLintErrors(array $errors): array
    {
        $seen = [];
        $unique = [];
        foreach ($errors as $error) {
            $key = $error['file'].'#'.($error['line'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $error;
        }

        return $unique;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, " \t'\"");
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
