<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\DeepSwe;

/**
 * Parses DeepSWE / Harbor-compatible task folders without exposing benchmark
 * prompt or solution contents in command output.
 */
final class AtlasForgeRivalsDeepSweTaskParserService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.deepswe_task.v1';

    /**
     * @return array<string,mixed>
     */
    public function parseTask(string $taskDir): array
    {
        $taskDir = rtrim($taskDir, DIRECTORY_SEPARATOR);
        $blockers = [];

        if ($taskDir === '' || ! is_dir($taskDir)) {
            return $this->blocked($taskDir, ['deepswe_task_dir_missing:'.$taskDir]);
        }

        $taskToml = $taskDir.'/task.toml';
        $instruction = $taskDir.'/instruction.md';
        $testSh = $taskDir.'/tests/test.sh';
        $testPatch = $taskDir.'/tests/test.patch';
        $solutionDir = $taskDir.'/solution';
        $environmentDir = $taskDir.'/environment';

        foreach ([
            'task.toml' => $taskToml,
            'instruction.md' => $instruction,
        ] as $label => $path) {
            if (! is_file($path)) {
                $blockers[] = 'deepswe_required_file_missing:'.$label;
            }
        }

        if (! is_file($testSh)) {
            $blockers[] = 'deepswe_verifier_entrypoint_missing:tests/test.sh';
        }
        if (! is_file($testPatch)) {
            $blockers[] = 'deepswe_verifier_patch_missing:tests/test.patch';
        }

        $metadata = is_file($taskToml) ? $this->parseTomlLike((string) file_get_contents($taskToml)) : [];
        $taskId = $this->taskId($taskDir, $metadata);
        $artifactHashes = $this->artifactHashes([
            'task_toml' => $taskToml,
            'instruction' => $instruction,
            'verifier_entrypoint' => $testSh,
            'verifier_patch' => $testPatch,
        ]);

        $solutionFiles = is_dir($solutionDir) ? $this->listRelativeFiles($solutionDir) : [];
        $environmentFiles = is_dir($environmentDir) ? $this->listRelativeFiles($environmentDir) : [];

        if ($blockers !== []) {
            return $this->blocked($taskDir, $blockers) + [
                'task_id' => $taskId,
                'metadata' => $metadata,
                'artifact_hashes' => $artifactHashes,
            ];
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'case_source' => 'deepswe',
            'task_format' => 'harbor',
            'task_id' => $taskId,
            'task_dir' => $taskDir,
            'metadata' => $metadata,
            'language' => $this->metadataString($metadata, ['language', 'lang']),
            'repository' => $this->metadataString($metadata, ['repository', 'repo']),
            'base_commit' => $this->metadataString($metadata, ['base_commit', 'commit', 'base_ref']),
            'prebuilt_image' => $this->metadataString($metadata, ['prebuilt_image', 'image', 'docker_image']),
            'paths' => [
                'task_toml' => $taskToml,
                'instruction_md' => $instruction,
                'environment_dir' => is_dir($environmentDir) ? $environmentDir : null,
                'verifier_test_sh' => $testSh,
                'verifier_test_patch' => $testPatch,
                'solution_dir' => is_dir($solutionDir) ? $solutionDir : null,
            ],
            'artifact_hashes' => $artifactHashes,
            'environment_files' => $environmentFiles,
            'solution_reference' => [
                'present' => is_dir($solutionDir),
                'file_count' => count($solutionFiles),
                'files' => $solutionFiles,
                'forbidden_to_agent' => true,
                'content_exposed' => false,
            ],
            'agent_visible_inputs' => [
                'instruction_md_path' => $instruction,
                'instruction_md_sha256' => $artifactHashes['instruction']['sha256'] ?? null,
                'instruction_content_exposed_in_rivals_json' => false,
                'solution_content_exposed_in_rivals_json' => false,
            ],
            'verifier' => [
                'entrypoint' => 'tests/test.sh',
                'patch' => 'tests/test.patch',
                'entrypoint_sha256' => $artifactHashes['verifier_entrypoint']['sha256'] ?? null,
                'patch_sha256' => $artifactHashes['verifier_patch']['sha256'] ?? null,
                'programmatic_verifier_required' => true,
            ],
            'evidence_requirements' => [
                'task_manifest',
                'verifier_hash',
                'environment_metadata',
                'trajectory',
                'patch',
                'stdout_stderr',
                'verifier_result',
                'replay_result',
                'matrix_lock',
            ],
            'claim_policy' => $this->claimPolicy(),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
        ];

        $manifest['manifest_hash'] = hash('sha256', (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'task_id' => $taskId,
            'metadata' => $metadata,
            'artifact_hashes' => $artifactHashes,
            'solution_file_count' => count($solutionFiles),
            'environment_files' => $environmentFiles,
            'claim_policy' => $manifest['claim_policy'],
        ], JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $taskDir, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'case_source' => 'deepswe',
            'task_format' => 'harbor',
            'task_dir' => $taskDir,
            'blockers' => array_values(array_unique(array_map('strval', $blockers))),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * Small TOML subset parser for DeepSWE metadata. It intentionally supports
     * scalar keys and dotted sections needed for manifest/readiness only.
     *
     * @return array<string,mixed>
     */
    private function parseTomlLike(string $source): array
    {
        $out = [];
        $section = [];
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^\[([A-Za-z0-9_.-]+)]$/', $line, $m) === 1) {
                $section = explode('.', $m[1]);

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $key = $section === [] ? $m[1] : implode('.', array_merge($section, [$m[1]]));
            data_set($out, $key, $this->parseTomlValue(trim($m[2])));
        }

        return $out;
    }

    private function parseTomlValue(string $value): mixed
    {
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return (float) $value;
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }

            return array_map(
                fn (string $part): mixed => $this->parseTomlValue(trim($part)),
                str_getcsv($inner),
            );
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function taskId(string $taskDir, array $metadata): string
    {
        foreach (['id', 'task_id', 'name'] as $key) {
            $value = data_get($metadata, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return basename($taskDir);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  list<string>  $keys
     */
    private function metadataString(array $metadata, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($metadata, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $paths
     * @return array<string,array<string,mixed>>
     */
    private function artifactHashes(array $paths): array
    {
        $out = [];
        foreach ($paths as $key => $path) {
            $out[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : null,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function listRelativeFiles(string $dir): array
    {
        $files = [];
        $rootLen = strlen(rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), $rootLen);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'ready_for_external_claim' => false,
            'external_claim_allowed' => false,
            'requires_programmatic_verifier_green' => true,
            'requires_trajectory' => true,
            'requires_patch' => true,
            'requires_replay_green' => true,
            'requires_matrix_lock' => true,
            'requires_reproducible_environment' => true,
            'solution_reference_must_remain_hidden_from_agent' => true,
            'local_import_is_not_benchmark_claim' => true,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
        ];
    }
}
