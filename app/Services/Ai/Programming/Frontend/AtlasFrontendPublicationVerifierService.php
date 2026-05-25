<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendPublicationVerifierService
{
    public const SCHEMA_VERSION = 'atlas.frontend.publication_verifier.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.frontend.publication_receipt.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.publication_receipt_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function verify(string $bundleDirectory, ?string $publicReceiptPath = null): array
    {
        $bundleDirectory = rtrim(trim($bundleDirectory), DIRECTORY_SEPARATOR);
        $blockers = [];
        $warnings = [];
        $bundle = null;

        if ($bundleDirectory === '' || ! File::isDirectory($bundleDirectory)) {
            $blockers[] = 'bundle_directory_missing';
        }

        $manifestPath = $bundleDirectory.'/manifest.json';
        if ($blockers === [] && ! File::isFile($manifestPath)) {
            $blockers[] = 'bundle_manifest_missing';
        }

        if ($blockers === []) {
            $bundle = $this->readJson($manifestPath);
            if (! is_array($bundle)) {
                $blockers[] = 'bundle_manifest_json_invalid';
            }
        }

        if (is_array($bundle)) {
            $blockers = array_merge($blockers, $this->bundleBlockers($bundle, $bundleDirectory));
        }

        $publicReceipt = $this->verifyPublicReceipt(
            (string) ($publicReceiptPath ?? ''),
            is_array($bundle) ? (string) ($bundle['bundle_hash'] ?? '') : '',
            is_array($bundle) ? (string) data_get($bundle, 'index.hash', '') : '',
            $this->frontendAppScope(is_array($bundle) ? (array) ($bundle['frontend_app_scope'] ?? []) : []),
        );
        if ($publicReceiptPath !== null && trim($publicReceiptPath) !== '' && $publicReceipt['status'] !== 'verified') {
            $blockers[] = 'public_receipt_invalid';
        }
        if (($publicReceipt['status'] ?? null) === 'missing') {
            $warnings[] = 'public_receipt_missing';
        }

        $localReady = $blockers === [] && is_array($bundle);
        $publicVerified = $localReady && ($publicReceipt['status'] ?? null) === 'verified';
        $status = $publicVerified ? 'public_verified' : ($localReady ? 'local_ready' : 'blocked');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'verifier_type' => 'frontend_product_proof_publication',
            'source' => self::class,
            'bundle_directory_hash' => $bundleDirectory !== '' ? hash('sha256', $bundleDirectory) : null,
            'bundle_manifest_hash' => File::isFile($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'bundle_hash' => is_array($bundle) ? ($bundle['bundle_hash'] ?? null) : null,
            'frontend_app_scope' => $this->frontendAppScope(is_array($bundle) ? (array) ($bundle['frontend_app_scope'] ?? []) : []),
            'public_receipt' => $publicReceipt,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'claim_policy' => [
                'local_bundle_claim_allowed' => $localReady,
                'public_distribution_claim_allowed' => $publicVerified,
                'world_best_claim_allowed' => false,
                'public_distribution_requires_verified_receipt' => true,
                'public_distribution_requires_matching_frontend_app_scope' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['publication_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeReceiptTemplate(string $outputDirectory, ?string $bundleDirectory = null): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $path = $outputDirectory.'/publication-receipt.json';
        $bundleContext = $this->receiptTemplateBundleContext($bundleDirectory);

        if (! File::isFile($path)) {
            File::put($path, json_encode([
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'status' => 'pending',
                'public_url' => 'https://example.com/atlas-frontend-proof/',
                'bundle_hash' => $bundleContext['bundle_hash'] ?? '<atlas.frontend.product_proof_bundle.v1 bundle_hash>',
                'index_content_hash' => $bundleContext['index_hash'] ?? '<sha256-64-hex>',
                'local_index_hash' => $bundleContext['index_hash'] ?? '<same sha256-64-hex from bundle index.hash>',
                'frontend_app_scope' => $bundleContext['frontend_app_scope'] ?? [
                    'status' => 'repo_root',
                    'relative_name' => null,
                    'relative_name_hash' => null,
                ],
                'http_status' => 200,
                'checked_at' => '<ISO-8601 timestamp>',
                'operator_approved' => false,
                'notes' => 'Do not include secrets, cookies, raw customer source or provider tokens.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => ($bundleContext['blockers'] ?? []) === [] ? 'ready' : 'blocked',
            'template_type' => 'frontend_publication_receipt',
            'receipt_path_hash' => hash('sha256', $path),
            'bundle_context' => [
                'provided' => (bool) ($bundleContext['provided'] ?? false),
                'prefilled_from_bundle' => (bool) ($bundleContext['prefilled_from_bundle'] ?? false),
                'bundle_directory_hash' => $bundleContext['bundle_directory_hash'] ?? null,
                'bundle_manifest_hash' => $bundleContext['bundle_manifest_hash'] ?? null,
                'frontend_app_scope' => $bundleContext['frontend_app_scope'] ?? null,
            ],
            'claim_policy' => [
                'template_is_not_public_verification' => true,
                'prefilled_bundle_hash_is_not_public_verification' => true,
                'operator_approval_required' => true,
                'bundle_hash_match_required' => true,
            ],
            'blockers' => array_values(array_unique((array) ($bundleContext['blockers'] ?? []))),
            'warnings' => [],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptTemplateBundleContext(?string $bundleDirectory): array
    {
        $bundleDirectory = rtrim(trim((string) ($bundleDirectory ?? '')), DIRECTORY_SEPARATOR);
        if ($bundleDirectory === '') {
            return [
                'provided' => false,
                'prefilled_from_bundle' => false,
                'blockers' => [],
            ];
        }

        $blockers = [];
        $manifestPath = $bundleDirectory.'/manifest.json';
        $bundle = null;

        if (! File::isDirectory($bundleDirectory)) {
            $blockers[] = 'bundle_directory_missing';
        } elseif (! File::isFile($manifestPath)) {
            $blockers[] = 'bundle_manifest_missing';
        } else {
            $bundle = $this->readJson($manifestPath);
            if (! is_array($bundle)) {
                $blockers[] = 'bundle_manifest_json_invalid';
            } else {
                $blockers = array_merge($blockers, $this->bundleBlockers($bundle, $bundleDirectory));
            }
        }

        return [
            'provided' => true,
            'prefilled_from_bundle' => $blockers === [] && is_array($bundle),
            'bundle_directory_hash' => hash('sha256', $bundleDirectory),
            'bundle_manifest_hash' => File::isFile($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'bundle_hash' => $blockers === [] && is_array($bundle) ? (string) ($bundle['bundle_hash'] ?? '') : null,
            'index_hash' => $blockers === [] && is_array($bundle) ? (string) data_get($bundle, 'index.hash', '') : null,
            'frontend_app_scope' => $blockers === [] && is_array($bundle)
                ? $this->frontendAppScope((array) ($bundle['frontend_app_scope'] ?? []))
                : null,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $bundle
     * @return array<int,string>
     */
    private function bundleBlockers(array $bundle, string $bundleDirectory): array
    {
        $blockers = [];
        if (($bundle['schema_version'] ?? null) !== AtlasFrontendProductProofRuntimeService::BUNDLE_SCHEMA_VERSION) {
            $blockers[] = 'bundle_schema_version_invalid';
        }
        if (($bundle['status'] ?? null) !== 'ready') {
            $blockers[] = 'bundle_status_not_ready';
        }
        if (! is_string($bundle['bundle_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $bundle['bundle_hash']) !== 1) {
            $blockers[] = 'bundle_hash_invalid';
        }

        $indexPath = (string) data_get($bundle, 'index.path', '');
        $indexHash = (string) data_get($bundle, 'index.hash', '');
        $blockers = array_merge($blockers, $this->fileHashBlockers('index', $bundleDirectory, $indexPath, $indexHash));

        $assets = $bundle['assets'] ?? null;
        if (! is_array($assets) || $assets === []) {
            $blockers[] = 'bundle_assets_missing';
        } else {
            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    $blockers[] = 'bundle_asset_invalid';

                    continue;
                }
                $blockers = array_merge($blockers, $this->fileHashBlockers('asset', $bundleDirectory, (string) ($asset['path'] ?? ''), (string) ($asset['hash'] ?? '')));
            }
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyPublicReceipt(string $path, string $bundleHash, string $localIndexHash, array $expectedFrontendAppScope): array
    {
        $path = trim($path);
        if ($path === '') {
            return ['status' => 'missing'];
        }
        if (! File::isFile($path)) {
            return ['status' => 'blocked', 'blockers' => ['public_receipt_missing']];
        }

        $raw = File::get($path);
        $receipt = json_decode($raw, true);
        if (! is_array($receipt)) {
            return ['status' => 'blocked', 'blockers' => ['public_receipt_json_invalid'], 'receipt_hash' => hash('sha256', $raw)];
        }

        $blockers = [];
        if (($receipt['schema_version'] ?? null) !== self::RECEIPT_SCHEMA_VERSION) {
            $blockers[] = 'public_receipt_schema_invalid';
        }
        if (($receipt['status'] ?? null) !== 'verified') {
            $blockers[] = 'public_receipt_status_not_verified';
        }
        if ((bool) ($receipt['operator_approved'] ?? false) !== true) {
            $blockers[] = 'public_receipt_operator_approval_missing';
        }
        if ((int) ($receipt['http_status'] ?? 0) !== 200) {
            $blockers[] = 'public_receipt_http_status_not_200';
        }
        if (($receipt['bundle_hash'] ?? null) !== $bundleHash) {
            $blockers[] = 'public_receipt_bundle_hash_mismatch';
        }
        if (! is_string($receipt['public_url'] ?? null) || ! str_starts_with((string) $receipt['public_url'], 'https://')) {
            $blockers[] = 'public_receipt_https_url_required';
        }
        if (! is_string($receipt['index_content_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $receipt['index_content_hash']) !== 1) {
            $blockers[] = 'public_receipt_index_content_hash_invalid';
        }
        if (
            is_string($receipt['index_content_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $receipt['index_content_hash']) === 1
            && $localIndexHash !== ''
            && (string) $receipt['index_content_hash'] !== $localIndexHash
        ) {
            $blockers[] = 'public_receipt_index_content_hash_mismatch';
        }
        if (isset($receipt['local_index_hash']) && $receipt['local_index_hash'] !== $localIndexHash) {
            $blockers[] = 'public_receipt_local_index_hash_mismatch';
        }
        $receiptFrontendAppScope = $this->frontendAppScope((array) ($receipt['frontend_app_scope'] ?? []));
        if (($expectedFrontendAppScope['status'] ?? null) === 'subscope_selected' && ! is_array($receipt['frontend_app_scope'] ?? null)) {
            $blockers[] = 'public_receipt_frontend_app_scope_missing';
        } elseif ($this->frontendAppScopeKey($receiptFrontendAppScope) !== $this->frontendAppScopeKey($expectedFrontendAppScope)) {
            $blockers[] = 'public_receipt_frontend_app_scope_mismatch';
        }

        return [
            'status' => $blockers === [] ? 'verified' : 'blocked',
            'receipt_hash' => hash('sha256', $raw),
            'public_url_hash' => isset($receipt['public_url']) ? hash('sha256', (string) $receipt['public_url']) : null,
            'frontend_app_scope' => $receiptFrontendAppScope,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function fileHashBlockers(string $label, string $root, string $path, string $expectedHash): array
    {
        $blockers = [];
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            $blockers[] = $label.'_path_invalid';
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            $blockers[] = $label.'_hash_invalid';
        }

        $absolute = $path !== '' ? $root.'/'.$path : '';
        if ($absolute === '' || ! File::isFile($absolute)) {
            $blockers[] = $label.'_file_missing';
        } elseif (preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1 && hash_file('sha256', $absolute) !== $expectedHash) {
            $blockers[] = $label.'_hash_mismatch';
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    private function frontendAppScope(array $scope): array
    {
        if ($scope === []) {
            return ['status' => 'repo_root', 'relative_name_hash' => null];
        }

        $status = (string) ($scope['status'] ?? 'repo_root');
        if ($status !== 'subscope_selected') {
            return [
                'status' => $status !== '' ? $status : 'repo_root',
                'relative_name_hash' => null,
            ];
        }

        $relative = is_string($scope['relative_name'] ?? null) ? trim(str_replace('\\', '/', (string) $scope['relative_name']), '/') : null;
        if ($relative === null || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return [
                'status' => 'invalid_subscope',
                'relative_name_hash' => $relative !== null ? hash('sha256', $relative) : null,
            ];
        }

        return [
            'status' => 'subscope_selected',
            'relative_name' => $relative,
            'relative_name_hash' => hash('sha256', $relative),
            'repo_workspace_remains_primary' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function frontendAppScopeKey(array $scope): string
    {
        return implode(':', [
            (string) ($scope['status'] ?? 'repo_root'),
            (string) ($scope['relative_name_hash'] ?? ''),
        ]);
    }
}
