<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_draft_workspace_inspector.v1';

    private const STORAGE_DISK = 'local';

    private const WORKSPACE_PREFIX = 'atlas/self-construction/operator-submissions/draft-workspaces';

    private const REQUIRED_ARTIFACTS = [
        'runtime_promotion_receipt',
        'real_provider_smoke',
        'human_completion_receipt',
    ];

    private const FORBIDDEN_FLAGS = [
        'execution_allowed',
        'dispatch_allowed',
        'provider_call_allowed',
        'provider_called_by_atlas',
        'token_spend_allowed',
        'token_spent_by_atlas',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_autopromoted',
        'completion_claim_promoted_without_receipt',
    ];

    /** @param array<string, mixed> $options */
    public function inspect(array $options = []): array
    {
        $requestedPath = (string) ($options['operator_draft_workspace_path'] ?? '');
        $manifestPath = $this->resolveManifestPath($requestedPath);
        $violations = [];
        $warnings = [];

        if ($manifestPath === '') {
            $violations[] = 'operator_draft_workspace_manifest_not_found';

            return $this->payload(
                status: 'no_workspace',
                manifestPath: '',
                workspaceDirectory: '',
                requestedPath: $requestedPath,
                manifest: [],
                files: [],
                violations: $violations,
                warnings: $warnings,
            );
        }

        $manifest = $this->readJson($manifestPath, $violations);
        $workspaceDirectory = (string) data_get($manifest, 'workspace_directory', dirname($manifestPath));
        $files = [];
        $seenArtifacts = [];

        foreach ((array) data_get($manifest, 'files', []) as $file) {
            $fileReport = $this->inspectFile((array) $file);
            $files[] = $fileReport;
            $artifact = (string) data_get($fileReport, 'artifact', '');
            if ($artifact !== '') {
                $seenArtifacts[] = $artifact;
            }
            foreach ((array) data_get($fileReport, 'violations', []) as $violation) {
                $violations[] = $artifact === '' ? $violation : $artifact.':'.$violation;
            }
            foreach ((array) data_get($fileReport, 'warnings', []) as $warning) {
                $warnings[] = $artifact === '' ? $warning : $artifact.':'.$warning;
            }
        }

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            if (! in_array($artifact, $seenArtifacts, true)) {
                $violations[] = 'missing_artifact:'.$artifact;
            }
        }

        if ((bool) data_get($manifest, 'can_persist_completion_evidence_from_draft_workspace', false)) {
            $violations[] = 'manifest_allows_completion_evidence_persistence';
        }
        if ((bool) data_get($manifest, 'can_promote_completion_from_draft_workspace', false)) {
            $violations[] = 'manifest_allows_completion_promotion';
        }

        return $this->payload(
            status: $violations === [] ? 'workspace_safe_for_operator_editing' : 'workspace_attention_required',
            manifestPath: $manifestPath,
            workspaceDirectory: $workspaceDirectory,
            requestedPath: $requestedPath,
            manifest: $manifest,
            files: $files,
            violations: array_values(array_unique($violations)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function inspectFile(array $file): array
    {
        $violations = [];
        $warnings = [];
        $artifact = (string) data_get($file, 'artifact', '');
        $draftPath = (string) data_get($file, 'draft_path', '');
        $payload = [];
        $exists = $draftPath !== '' && Storage::disk(self::STORAGE_DISK)->exists($draftPath);

        if (! $exists) {
            $violations[] = 'draft_file_missing';
        } else {
            $payload = $this->readJson($draftPath, $violations);
        }

        $draftJson = $payload === []
            ? ''
            : (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $computedSha = $draftJson === '' ? '' : hash('sha256', $draftJson);
        $declaredSha = (string) data_get($file, 'draft_file_sha256', '');
        $templateSha = (string) data_get($file, 'payload_template_json_sha256', '');
        $placeholders = $this->placeholderPaths($payload);
        $forbiddenFlagsTrue = $this->trueFlagPaths($payload, self::FORBIDDEN_FLAGS);

        if ($computedSha !== '' && $declaredSha !== '' && ! hash_equals($declaredSha, $computedSha)) {
            $warnings[] = 'draft_file_sha256_changed_since_export';
        }
        if ($computedSha !== '' && $templateSha !== '' && ! hash_equals($templateSha, $computedSha)) {
            $warnings[] = 'draft_file_differs_from_initial_template';
        }
        if ((bool) data_get($file, 'draft_is_evidence', true)) {
            $violations[] = 'draft_marked_as_evidence';
        }
        if ((bool) data_get($file, 'can_persist_draft_directly', true)) {
            $violations[] = 'draft_marked_directly_persistible';
        }

        $verifyCommand = (string) data_get($file, 'command_to_verify_draft_file', '');
        if (str_contains($verifyCommand, '--persist-runtime-promotion-receipt') || str_contains($verifyCommand, '--persist-completion-evidence')) {
            $violations[] = 'verify_command_contains_persist_flag';
        }

        if ($forbiddenFlagsTrue !== []) {
            $violations[] = 'forbidden_flags_true';
        }

        return [
            'artifact' => $artifact,
            'draft_path' => $draftPath,
            'exists' => $exists,
            'declared_draft_file_sha256' => $declaredSha,
            'computed_draft_file_sha256' => $computedSha,
            'payload_template_json_sha256' => $templateSha,
            'sha256_matches_exported_draft' => $computedSha !== '' && $declaredSha !== '' && hash_equals($declaredSha, $computedSha),
            'sha256_matches_initial_template' => $computedSha !== '' && $templateSha !== '' && hash_equals($templateSha, $computedSha),
            'placeholder_count' => count($placeholders),
            'placeholders' => $placeholders,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'draft_is_evidence' => (bool) data_get($file, 'draft_is_evidence', true),
            'can_persist_draft_directly' => (bool) data_get($file, 'can_persist_draft_directly', true),
            'verify_command_has_persist_flag' => str_contains($verifyCommand, '--persist-runtime-promotion-receipt') || str_contains($verifyCommand, '--persist-completion-evidence'),
            'violations' => array_values(array_unique($violations)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param list<string> $violations */
    private function readJson(string $path, array &$violations): array
    {
        try {
            $contents = Storage::disk(self::STORAGE_DISK)->get($path);
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $violations[] = 'invalid_json:'.$path;

            return [];
        }
    }

    private function resolveManifestPath(string $requestedPath): string
    {
        $path = $this->normalizeStoragePath($requestedPath);
        if ($path !== '') {
            if (! str_ends_with($path, 'manifest.json')) {
                $path = rtrim($path, '/').'/manifest.json';
            }

            return Storage::disk(self::STORAGE_DISK)->exists($path) ? $path : '';
        }

        $manifests = array_values(array_filter(
            Storage::disk(self::STORAGE_DISK)->allFiles(self::WORKSPACE_PREFIX),
            fn (string $file): bool => str_ends_with($file, '/manifest.json'),
        ));
        rsort($manifests);

        return (string) ($manifests[0] ?? '');
    }

    private function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        $path = preg_replace('#^storage/app/#', '', $path) ?? $path;

        return trim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function placeholderPaths(array $payload, string $prefix = ''): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($paths, ...$this->placeholderPaths($value, $path));
            } elseif (is_string($value) && str_starts_with(trim($value), '<') && str_ends_with(trim($value), '>')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $flags
     * @return list<string>
     */
    private function trueFlagPaths(array $payload, array $flags, string $prefix = ''): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($paths, ...$this->trueFlagPaths($value, $flags, $path));
            } elseif (in_array((string) $key, $flags, true) && $value === true) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $files
     * @param  list<string>  $violations
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function payload(string $status, string $manifestPath, string $workspaceDirectory, string $requestedPath, array $manifest, array $files, array $violations, array $warnings): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'read_only_operator_evidence_draft_workspace_inspector',
            'status' => $status,
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'workspace_directory' => $workspaceDirectory,
            'artifact_count' => count($files),
            'files' => $files,
            'manifest' => $manifest,
            'violation_count' => count($violations),
            'violations' => $violations,
            'warning_count' => count($warnings),
            'warnings' => $warnings,
            'workspace_safe_for_operator_editing' => $violations === [],
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'draft_workspace_inspector_does_not_write_files',
                'draft_workspace_inspector_does_not_persist_receipts',
                'draft_workspace_inspector_does_not_persist_smoke',
                'draft_workspace_inspector_does_not_sign_for_operator',
                'draft_workspace_inspector_does_not_call_provider',
                'draft_workspace_inspector_does_not_spend_tokens',
                'draft_workspace_inspector_does_not_dispatch',
                'draft_workspace_inspector_does_not_promote_completion',
            ],
        ];
        $payload['inspector_hash'] = hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
