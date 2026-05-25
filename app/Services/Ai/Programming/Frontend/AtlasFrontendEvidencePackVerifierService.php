<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendEvidencePackVerifierService
{
    public const SCHEMA_VERSION = 'atlas.frontend.evidence_pack_verifier.v1';

    public const PACK_SCHEMA_VERSION = 'atlas.frontend.evidence_pack.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.evidence_pack_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function verify(string $manifestPath, ?string $rootDirectory = null): array
    {
        $manifestPath = trim($manifestPath);
        $root = $this->rootDirectory($rootDirectory, $manifestPath);
        $blockers = [];
        $warnings = [];

        if ($manifestPath === '' || ! File::isFile($manifestPath)) {
            return $this->result('blocked', $manifestPath, $root, ['manifest_missing'], [], []);
        }

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return $this->result('blocked', $manifestPath, $root, ['manifest_json_invalid'], [], []);
        }

        if (($manifest['schema_version'] ?? null) !== self::PACK_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach (['pack_id', 'case_id', 'system', 'task_spec_hash', 'artifacts'] as $field) {
            if (! array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
                $blockers[] = 'missing_'.$field;
            }
        }
        if ($this->hasForbiddenRawFields($manifest)) {
            $blockers[] = 'forbidden_raw_prompt_or_source_field_present';
        }
        if (! is_array($manifest['artifacts'] ?? null)) {
            $blockers[] = 'artifacts_must_be_array';
        }

        $artifactResults = is_array($manifest['artifacts'] ?? null)
            ? $this->verifyArtifacts($manifest['artifacts'], $root)
            : [];

        $presentKinds = array_values(array_unique(array_map(
            fn (array $artifact): string => (string) ($artifact['kind'] ?? ''),
            array_filter($artifactResults, fn (array $artifact): bool => ($artifact['status'] ?? null) === 'present'),
        )));

        foreach ($this->requiredArtifactKinds() as $kind) {
            if (! in_array($kind, $presentKinds, true)) {
                $blockers[] = 'missing_artifact_kind_'.$kind;
            }
        }

        foreach ($artifactResults as $artifact) {
            foreach ((array) ($artifact['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
            foreach ((array) ($artifact['warnings'] ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        return $this->result($blockers === [] ? 'passed' : 'blocked', $manifestPath, $root, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $artifactResults, [
            'task_spec_hash' => preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($manifest['task_spec_hash'] ?? '')))
                ? strtolower((string) $manifest['task_spec_hash'])
                : null,
            'manifest_hash' => hash('sha256', $raw),
            'pack_id_hash' => isset($manifest['pack_id']) ? hash('sha256', (string) $manifest['pack_id']) : null,
            'case_id' => is_string($manifest['case_id'] ?? null) ? $manifest['case_id'] : null,
            'system' => is_string($manifest['system'] ?? null) ? $manifest['system'] : null,
            'frontend_app_scope' => $this->frontendAppScope($manifest),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        File::ensureDirectoryExists($outputDirectory.'/artifacts');

        $manifestPath = $outputDirectory.'/evidence-pack.json';
        if (! File::isFile($manifestPath)) {
            File::put($manifestPath, json_encode([
                'schema_version' => self::PACK_SCHEMA_VERSION,
                'pack_id' => 'replace-with-run-id',
                'case_id' => 'saas_dashboard_repair',
                'system' => 'atlas_frontend',
                'task_spec_hash' => '<sha256-64-hex>',
                'artifacts' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'path' => 'artifacts/'.$kind.'.json',
                    'sha256' => '<sha256-64-hex>',
                ], $this->requiredArtifactKinds()),
                'notes' => 'Store artifact refs and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_evidence_pack_manifest',
            'manifest_path_hash' => hash('sha256', $manifestPath),
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'claim_policy' => [
                'template_is_not_evidence' => true,
                'verification_requires_real_files_and_matching_hashes' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function requiredArtifactKinds(): array
    {
        return [
            'output_artifact',
            'screenshot_set',
            'design_5d_review',
            'quality_budget_report',
            'anti_slop_report',
            'verification_report',
            'console_report',
            'a11y_or_reason',
            'performance_or_reason',
            'receipt',
        ];
    }

    /**
     * @param  array<int,mixed>  $artifacts
     * @return array<int,array<string,mixed>>
     */
    private function verifyArtifacts(array $artifacts, string $root): array
    {
        return array_values(array_map(function (mixed $artifact) use ($root): array {
            if (! is_array($artifact)) {
                return [
                    'status' => 'blocked',
                    'blockers' => ['artifact_entry_invalid'],
                    'warnings' => [],
                ];
            }

            $kind = (string) ($artifact['kind'] ?? '');
            $path = (string) ($artifact['path'] ?? '');
            $expectedHash = strtolower((string) ($artifact['sha256'] ?? ''));
            $blockers = [];
            $warnings = [];

            if (! in_array($kind, $this->requiredArtifactKinds(), true)) {
                $blockers[] = 'artifact_kind_invalid';
            }
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
                $blockers[] = 'artifact_path_invalid';
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                $blockers[] = 'artifact_hash_invalid';
            }

            $absolute = $path !== '' ? $root.'/'.$path : '';
            if ($absolute === '' || ! File::isFile($absolute)) {
                $blockers[] = 'artifact_file_missing';
            }

            $actualHash = ($absolute !== '' && File::isFile($absolute)) ? hash_file('sha256', $absolute) : null;
            if ($actualHash !== null && $this->artifactHasForbiddenRawFields($absolute)) {
                $blockers[] = 'artifact_forbidden_raw_prompt_or_source_field_present';
            }
            if ($actualHash !== null && $expectedHash !== '' && preg_match('/^[a-f0-9]{64}$/', $expectedHash) && $actualHash !== $expectedHash) {
                $blockers[] = 'artifact_hash_mismatch';
            }
            if ($actualHash !== null && File::size($absolute) === 0) {
                $warnings[] = 'artifact_file_empty';
            }

            return [
                'kind' => $kind,
                'path_hash' => $path !== '' ? hash('sha256', $path) : null,
                'status' => $blockers === [] ? 'present' : 'blocked',
                'sha256' => $actualHash,
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }, $artifacts));
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<int,array<string,mixed>>  $artifacts
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function result(string $status, string $manifestPath, string $root, array $blockers, array $warnings, array $artifacts, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'verifier_type' => 'frontend_local_evidence_pack',
            'source' => self::class,
            'manifest_path_hash' => $manifestPath !== '' ? hash('sha256', $manifestPath) : null,
            'root_hash' => hash('sha256', $root),
            'pack_schema_version' => self::PACK_SCHEMA_VERSION,
            'required_artifact_kinds' => $this->requiredArtifactKinds(),
            'artifact_results' => $artifacts,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'claim_policy' => [
                'passed_pack_can_support_replay_manifest' => $status === 'passed',
                'passed_pack_is_not_public_hosting_proof' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['verification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function hasForbiddenRawFields(array $manifest): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data'];

        return $this->containsForbiddenKeyRecursive($manifest, $forbidden);
    }

    /**
     * @param  array<int,string>  $forbidden
     */
    private function containsForbiddenKeyRecursive(array $payload, array $forbidden): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(Str::snake($key), $forbidden, true)) {
                return true;
            }

            if (is_array($value) && $this->containsForbiddenKeyRecursive($value, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function artifactHasForbiddenRawFields(string $path): bool
    {
        if (! str_ends_with(strtolower($path), '.json')) {
            return false;
        }

        $payload = json_decode(File::get($path), true);

        return is_array($payload) && $this->hasForbiddenRawFields($payload);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function frontendAppScope(array $manifest): array
    {
        $scope = $manifest['frontend_app_scope'] ?? null;
        if (! is_array($scope)) {
            return ['status' => 'repo_root', 'relative_name_hash' => null];
        }

        $status = (string) ($scope['status'] ?? 'repo_root');
        $relative = is_string($scope['relative_name'] ?? null) ? trim(str_replace('\\', '/', (string) $scope['relative_name']), '/') : null;

        if ($status !== 'subscope_selected') {
            return ['status' => $status !== '' ? $status : 'repo_root', 'relative_name_hash' => null];
        }

        if ($relative === null || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['status' => 'invalid_subscope', 'relative_name_hash' => $relative !== null ? hash('sha256', $relative) : null];
        }

        return [
            'status' => 'subscope_selected',
            'relative_name' => $relative,
            'relative_name_hash' => hash('sha256', $relative),
            'repo_workspace_remains_primary' => true,
        ];
    }

    private function rootDirectory(?string $rootDirectory, string $manifestPath): string
    {
        $rootDirectory = trim((string) $rootDirectory);
        if ($rootDirectory !== '') {
            return rtrim($rootDirectory, DIRECTORY_SEPARATOR);
        }

        return $manifestPath !== '' ? dirname($manifestPath) : getcwd();
    }
}
