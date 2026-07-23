<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\Support\JsonFileStore;

/**
 * AP-786 owner-flow DIAGNOSTICS section: owner-runtime failure diagnostics and the
 * failure-capsule verification-log reader. Bodies moved verbatim from
 * Ap786OwnerFlowExecutor (GOD-DEBULK surgical split).
 */
final class Ap786OwnerFlowDiagnosticsSection
{
    public function __construct(
        private readonly Ap786OwnerFlowIntentSection $intentBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return list<string>
     */
    public function ownerRuntimeFailureDiagnostics(array $ownerResult, array $command = []): array
    {
        $diagnostics = [];
        $completion = (string) data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', '');
        if ($completion !== '') {
            $diagnostics[] = 'completion_state='.$this->intentBuilder->safeCliValue($completion);
        }

        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        if ($debugReason !== '') {
            $diagnostics[] = 'debug_reason='.$this->intentBuilder->safeCliValue($debugReason);
        }

        foreach ([
            'scope_guard_status' => 'runtime_invocation.senior_loop.run_summary.scope_guard_status',
            'verification_status' => 'runtime_invocation.senior_loop.run_summary.verification_status',
            'verification_receipt_hash' => 'runtime_invocation.senior_loop.run_summary.verification_receipt_hash',
            'persisted_ref' => 'runtime_invocation.senior_loop.persisted_ref',
            'error_ledger_ref' => 'runtime_invocation.senior_loop.learning.error_ledger_ref',
        ] as $label => $path) {
            $value = (string) data_get($ownerResult, $path, '');
            if ($value !== '') {
                $diagnostics[] = $label.'='.$this->intentBuilder->safeCliValue($value);
            }
        }

        $failureRefs = [];
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (is_array($capsule) && (string) ($capsule['ref'] ?? '') !== '') {
                $failureRefs[] = $this->intentBuilder->safeCliValue((string) $capsule['ref']);
            }
        }
        if ($failureRefs !== []) {
            $diagnostics[] = 'failure_capsules='.implode(',', array_slice(AreaFocusStringListNormalizer::uniqueStringValues($failureRefs), 0, 3));
        }
        foreach ($this->failureCapsuleDiagnostics($ownerResult, $command) as $capsuleDiagnostic) {
            $diagnostics[] = $capsuleDiagnostic;
        }

        $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? []);
        if ($changedFiles !== []) {
            $diagnostics[] = 'changed_files='.implode(',', array_map(
                fn (string $file): string => $this->intentBuilder->safeCliValue($file),
                array_slice($changedFiles, 0, 5),
            ));
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($diagnostics);
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $command
     * @return list<string>
     */
    public function failureCapsuleDiagnostics(array $ownerResult, array $command): array
    {
        $workspace = $this->workspaceFromCommand($command);
        if ($workspace === '') {
            return [];
        }

        $diagnostics = [];
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (! is_array($capsule)) {
                continue;
            }
            $ref = trim((string) ($capsule['ref'] ?? ''));
            if ($ref === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
                continue;
            }
            $path = $workspace.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'atlas-dev'.DIRECTORY_SEPARATOR.$ref;
            if (! is_file($path)) {
                continue;
            }
            $payload = JsonFileStore::readArray($path);
            if (! is_array($payload)) {
                continue;
            }
            foreach ([
                'failing_test' => 'failing_test',
                'primary_error' => 'primary_error_excerpt',
                'failure_signature' => 'failure_signature',
            ] as $label => $key) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    $diagnostics[] = $label.'='.$this->intentBuilder->safeCliValue($value);
                }
            }
            $verificationLog = $this->failureCapsuleVerificationLogExcerpt($payload, $workspace);
            if ($verificationLog !== '') {
                $diagnostics[] = 'verification_log='.$this->intentBuilder->safeCliValue($verificationLog);
            }
            if (count($diagnostics) >= 6) {
                break;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($diagnostics);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function failureCapsuleVerificationLogExcerpt(array $payload, string $workspace): string
    {
        foreach ($this->failureLogPaths($payload) as $candidate) {
            $path = $this->resolveWorkspaceLogPath($workspace, $candidate);
            if ($path === '') {
                continue;
            }

            $excerpt = $this->verificationLogExcerptFromFile($path);
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function failureLogPaths(array $payload): array
    {
        $paths = [];
        foreach (['output_path', 'full_error_log_path', 'error_log_path', 'test_log_path'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                $paths[] = $value;
            }
        }

        foreach (['primary_error_excerpt', 'primary_error', 'error'] as $key) {
            $value = (string) ($payload[$key] ?? '');
            if ($value === '') {
                continue;
            }
            if (preg_match_all('/(?:output_path|full_error_log_path|error_log_path|test_log_path)=([^\\s)]+)/', $value, $matches) > 0) {
                foreach ($matches[1] ?? [] as $match) {
                    $paths[] = trim((string) $match, " \t\n\r\0\x0B'\"");
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter($paths, static fn (string $path): bool => $path !== ''));
    }

    public function resolveWorkspaceLogPath(string $workspace, string $candidate): string
    {
        if ($candidate === '' || str_contains($candidate, "\0")) {
            return '';
        }

        $workspaceRoot = realpath($workspace);
        if ($workspaceRoot === false) {
            return '';
        }

        $paths = str_starts_with($candidate, DIRECTORY_SEPARATOR)
            ? [$candidate]
            : [
                $workspace.DIRECTORY_SEPARATOR.$candidate,
                $workspace.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'atlas-dev'.DIRECTORY_SEPARATOR.$candidate,
            ];

        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false || ! is_file($real)) {
                continue;
            }
            if ($real !== $workspaceRoot && ! str_starts_with($real, $workspaceRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $real;
        }

        return '';
    }

    public function verificationLogExcerptFromFile(string $path): string
    {
        $contents = $this->readFilePrefix($path, 65536);
        if ($contents === '') {
            return '';
        }

        $strings = [];
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $this->collectLogStrings($decoded, $strings);
        } else {
            $strings[] = $contents;
        }

        return $this->selectVerificationLogExcerpt($strings);
    }

    public function readFilePrefix(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return '';
        }

        try {
            return (string) fread($handle, $bytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $strings
     */
    public function collectLogStrings(array $payload, array &$strings): void
    {
        foreach ($payload as $value) {
            if (count($strings) >= 40) {
                return;
            }
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;

                continue;
            }
            if (is_array($value)) {
                $this->collectLogStrings($value, $strings);
            }
        }
    }

    /**
     * @param  list<string>  $strings
     */
    public function selectVerificationLogExcerpt(array $strings): string
    {
        $lines = [];
        foreach ($strings as $string) {
            $clean = (string) preg_replace('/\e\[[0-9;]*m/', '', $string);
            foreach (preg_split('/\r\n|\r|\n/', $clean) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/psr-4|autoload|class .*not found|fatal error|parse error|exception|error|failed/i', $line) === 1) {
                return $line;
            }
        }

        return $lines[0] ?? '';
    }

    /** @param list<string> $command */
    public function workspaceFromCommand(array $command): string
    {
        foreach ($command as $part) {
            if (! is_string($part) || ! str_starts_with($part, '--workspace=')) {
                continue;
            }
            $workspace = trim(substr($part, strlen('--workspace=')));
            if ($workspace !== '' && is_dir($workspace)) {
                return $workspace;
            }
        }

        return '';
    }
}
