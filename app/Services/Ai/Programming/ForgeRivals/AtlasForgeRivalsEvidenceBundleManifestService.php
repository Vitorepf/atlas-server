<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Evidence Bundle Manifest.
 *
 * Builds a portable, hash-pinned manifest for a materialized run directory.
 * This is the bridge between local runs and external/reproducible evidence
 * stores: it tells the operator exactly which files must travel together
 * without running providers, replay, adjudication, or report generation.
 */
final class AtlasForgeRivalsEvidenceBundleManifestService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.evidence_bundle_manifest.v1';

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestService.php', 'kind' => 'service_implementation', 'reason' => 'Read-only manifest builder and verifier for hash-pinned rivals evidence bundles.'],
            ['path' => 'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Focused unit tests for manifest/verify fail-closed contracts and bundle integrity.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php', 'kind' => 'test_evidence', 'reason' => 'Artisan evidence-bundle and evidence-bundle-verify integration coverage.'],
        ];
    }

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function manifest(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return $this->blocked(['run_id_required'], 'php artisan atlas:forge:rivals evidence-bundle --run-id=<id> --json');
        }

        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return $this->blocked(
                ['run_not_found:'.$paths['run_id']],
                'php artisan atlas:forge:rivals runs --run-id='.$paths['run_id'].' --json',
                $paths['run_id'],
            );
        }

        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = is_file($packPath) ? $this->readJson($packPath) : [];
        $artifacts = is_array($pack['artifacts'] ?? null) ? $pack['artifacts'] : [];
        $required = $this->requiredRelativePaths($paths, $artifacts);
        $optional = $this->optionalRelativePaths($paths);
        $files = [];
        $blockers = [];

        foreach ($required as $relativePath) {
            $file = $this->fileRow($paths['base'], $relativePath, required: true);
            if (($file['present'] ?? false) !== true) {
                $blockers[] = 'bundle_required_file_missing:'.$relativePath;
            }
            $files[] = $file;
        }

        foreach ($optional as $relativePath) {
            if (in_array($relativePath, $required, true)) {
                continue;
            }
            $file = $this->fileRow($paths['base'], $relativePath, required: false);
            if (($file['present'] ?? false) === true) {
                $files[] = $file;
            }
        }

        foreach ($this->artifactRows($paths['base'], $artifacts) as $artifactRow) {
            $relativePath = (string) ($artifactRow['relative_path'] ?? '');
            if ($relativePath === '' || $this->fileAlreadyListed($files, $relativePath)) {
                continue;
            }
            if (($artifactRow['required'] ?? false) === true && ($artifactRow['present'] ?? false) !== true) {
                $blockers[] = 'bundle_required_artifact_missing:'.$relativePath;
            }
            $files[] = $artifactRow;
        }

        $blockers = array_values(array_unique($blockers));
        $fileCount = 0;
        $totalBytes = 0;
        foreach ($files as $file) {
            if (($file['present'] ?? false) === true) {
                $fileCount++;
                $totalBytes += (int) ($file['bytes'] ?? 0);
            }
        }

        $manifest = [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'run_dir' => $paths['base'],
            'runs_root' => $paths['root'],
            'bundle_ready' => $blockers === [],
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
            'files' => $files,
            'blockers' => $blockers,
            'claim_ready' => false,
            'score_or_claim_allowed' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'archive_command' => 'tar -C '.$paths['root'].' -czf '.$paths['run_id'].'.rivals-evidence.tgz '.$paths['run_id'],
            'restore_command' => 'tar -C '.$paths['root'].' -xzf '.$paths['run_id'].'.rivals-evidence.tgz',
            'next_command' => $blockers === []
                ? 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --json --strict'
                : 'fix bundle blockers before exporting evidence',
        ];

        $outputPath = trim((string) ($input['output_path'] ?? ''));
        if ($outputPath !== '') {
            $write = $this->writeManifest($outputPath, $manifest);
            $manifest['output_path'] = $outputPath;
            if ($write !== null) {
                $manifest['status'] = 'blocked';
                $manifest['bundle_ready'] = false;
                $manifest['blockers'] = array_values(array_unique(array_merge($manifest['blockers'], [$write])));
                $manifest['next_command'] = 'fix bundle blockers before exporting evidence';
            }
        }

        return $manifest;
    }

    /**
     * Verify a previously emitted bundle manifest against the current disk.
     * This is an integrity check for restored/copied evidence; it is not a
     * substitute for replay and never promotes score or claim readiness.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $inputPath = trim((string) ($input['input'] ?? ''));
        $manifest = $inputPath !== '' ? $this->readJson($inputPath) : $this->manifest($input);
        if ($manifest === []) {
            return array_merge($this->blocked(
                ['bundle_manifest_input_missing_or_invalid:'.$inputPath],
                'php artisan atlas:forge:rivals evidence-bundle --run-id=<id> --output-path=<manifest.json> --json',
            ), [
                'schema_version' => 'atlas.forge.rivals.evidence_bundle_verification.v1',
                'bundle_manifest_path' => $inputPath !== '' ? $inputPath : null,
                'bundle_verified' => false,
                'verified_file_count' => 0,
                'files' => [],
            ]);
        }

        $schema = (string) ($manifest['schema_version'] ?? '');
        $runId = is_string($manifest['run_id'] ?? null) ? (string) $manifest['run_id'] : null;
        $manifestRunDir = is_string($manifest['run_dir'] ?? null) ? rtrim((string) $manifest['run_dir'], '/') : '';
        $runDir = $this->resolveVerificationRunDir($input, $manifestRunDir);
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $blockers = [];
        $verified = [];

        if ($schema !== self::SCHEMA_VERSION) {
            $blockers[] = 'bundle_manifest_schema_invalid:'.$schema;
        }
        if ($runDir === '' || ! is_dir($runDir)) {
            $blockers[] = 'bundle_run_dir_missing:'.$runDir;
        }
        if ($files === []) {
            $blockers[] = 'bundle_manifest_files_empty';
        }

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $relative = (string) ($file['relative_path'] ?? '');
            $required = (bool) ($file['required'] ?? false);
            $expectedSha = (string) ($file['sha256'] ?? '');
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                $blockers[] = 'bundle_file_relative_path_invalid:'.$relative;

                continue;
            }

            $path = $runDir.'/'.$relative;
            $present = is_file($path);
            $actualSha = $present ? (hash_file('sha256', $path) ?: '') : '';
            $hashMatches = $present && $expectedSha !== '' && $actualSha === $expectedSha;
            if ($required && ! $present) {
                $blockers[] = 'bundle_verify_required_file_missing:'.$relative;
            }
            if ($present && $expectedSha !== '' && ! $hashMatches) {
                $blockers[] = 'bundle_verify_hash_mismatch:'.$relative;
            }

            $verified[] = [
                'relative_path' => $relative,
                'required' => $required,
                'present' => $present,
                'hash_matches' => $expectedSha === '' ? null : $hashMatches,
                'expected_sha256' => $expectedSha !== '' ? $expectedSha : null,
                'actual_sha256' => $present ? $actualSha : null,
            ];
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'schema_version' => 'atlas.forge.rivals.evidence_bundle_verification.v1',
            'bundle_manifest_schema_version' => $schema,
            'bundle_manifest_path' => $inputPath !== '' ? $inputPath : null,
            'run_id' => $runId,
            'run_dir' => $runDir !== '' ? $runDir : null,
            'manifest_run_dir' => $manifestRunDir !== '' ? $manifestRunDir : null,
            'run_dir_overridden' => $runDir !== '' && $manifestRunDir !== '' && $runDir !== $manifestRunDir,
            'bundle_verified' => $blockers === [],
            'verified_file_count' => count(array_filter($verified, static fn (array $row): bool => ($row['present'] ?? false) === true)),
            'files' => $verified,
            'blockers' => $blockers,
            'claim_ready' => false,
            'score_or_claim_allowed' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $blockers === []
                ? 'php artisan atlas:forge:rivals replay --run-id='.$runId.' --json --strict'
                : 'restore/tamper-check bundle files before replay',
        ];
    }

    /**
     * @param  array<string,string>  $paths
     * @param  array<string,mixed>  $artifacts
     * @return list<string>
     */
    private function requiredRelativePaths(array $paths, array $artifacts): array
    {
        $required = [
            $this->relative($paths['base'], $paths['manifest_json']),
            $this->relative($paths['base'], $paths['events_jsonl']),
            $this->relative($paths['base'], $paths['evidence'].'/evidence_pack.json'),
            $this->relative($paths['base'], $paths['evidence'].'/artifact_index.json'),
        ];

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact) || ($artifact['present'] ?? false) !== true) {
                continue;
            }
            $relative = $this->relative($paths['base'], (string) ($artifact['path'] ?? ''));
            if ($relative !== null) {
                $required[] = $relative;
            }
        }

        return array_values(array_unique(array_filter($required)));
    }

    /**
     * @param  array<string,string>  $paths
     * @return list<string>
     */
    private function optionalRelativePaths(array $paths): array
    {
        return array_values(array_filter([
            $this->relative($paths['base'], $paths['scorecard_json']),
            $this->relative($paths['base'], $paths['scorecard_v2_json']),
            $this->relative($paths['base'], $paths['report_md']),
            $this->relative($paths['base'], $paths['evidence'].'/battery_evidence_pack.json'),
            $this->relative($paths['base'], $paths['evidence'].'/battery_replay_verification.json'),
            $this->relative($paths['base'], $paths['evidence'].'/battery_report.md'),
            $this->relative($paths['base'], $paths['evidence'].'/matrix_report.json'),
            $this->relative($paths['base'], $paths['evidence'].'/matrix_report.md'),
        ]));
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return list<array<string,mixed>>
     */
    private function artifactRows(string $base, array $artifacts): array
    {
        $rows = [];
        foreach ($artifacts as $key => $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $path = (string) ($artifact['path'] ?? '');
            $relative = $this->relative($base, $path);
            if ($relative === null) {
                continue;
            }
            $row = $this->fileRow($base, $relative, required: ($artifact['present'] ?? false) === true);
            $row['artifact_key'] = (string) $key;
            $row['evidence_pack_sha256'] = (string) ($artifact['sha256'] ?? '');
            $row['hash_matches_evidence_pack'] = ($row['present'] ?? false) === true
                && ($row['evidence_pack_sha256'] === '' || $row['sha256'] === $row['evidence_pack_sha256']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function fileRow(string $base, string $relativePath, bool $required): array
    {
        $path = $base.'/'.$relativePath;
        $present = is_file($path);

        return [
            'relative_path' => $relativePath,
            'required' => $required,
            'present' => $present,
            'bytes' => $present ? (filesize($path) ?: 0) : 0,
            'sha256' => $present ? (hash_file('sha256', $path) ?: null) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $files
     */
    private function fileAlreadyListed(array $files, string $relativePath): bool
    {
        foreach ($files as $file) {
            if (($file['relative_path'] ?? null) === $relativePath) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $base, string $path): ?string
    {
        $base = rtrim($base, '/');
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, $base.'/')) {
            return null;
        }

        return ltrim(substr($path, strlen($base)), '/');
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveVerificationRunDir(array $input, string $manifestRunDir): string
    {
        foreach (['bundle_run_dir', 'run_dir'] as $key) {
            $override = trim((string) ($input[$key] ?? ''));
            if ($override !== '') {
                return rtrim($override, '/');
            }
        }

        return $manifestRunDir;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function writeManifest(string $path, array $manifest): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return 'bundle_manifest_output_dir_not_writable:'.$dir;
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || @file_put_contents($path, $json."\n") === false) {
            return 'bundle_manifest_write_failed:'.$path;
        }

        return null;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, string $nextCommand, ?string $runId = null): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'bundle_ready' => false,
            'blockers' => $blockers,
            'claim_ready' => false,
            'score_or_claim_allowed' => false,
            'external_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $nextCommand,
        ];
    }
}
