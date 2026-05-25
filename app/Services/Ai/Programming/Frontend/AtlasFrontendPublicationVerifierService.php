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
            'public_receipt' => $publicReceipt,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'claim_policy' => [
                'local_bundle_claim_allowed' => $localReady,
                'public_distribution_claim_allowed' => $publicVerified,
                'world_best_claim_allowed' => false,
                'public_distribution_requires_verified_receipt' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['publication_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeReceiptTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $path = $outputDirectory.'/publication-receipt.json';

        if (! File::isFile($path)) {
            File::put($path, json_encode([
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'status' => 'pending',
                'public_url' => 'https://example.com/atlas-frontend-proof/',
                'bundle_hash' => '<atlas.frontend.product_proof_bundle.v1 bundle_hash>',
                'index_content_hash' => '<sha256-64-hex>',
                'local_index_hash' => '<same sha256-64-hex from bundle index.hash>',
                'http_status' => 200,
                'checked_at' => '<ISO-8601 timestamp>',
                'operator_approved' => false,
                'notes' => 'Do not include secrets, cookies, raw customer source or provider tokens.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_publication_receipt',
            'receipt_path_hash' => hash('sha256', $path),
            'claim_policy' => [
                'template_is_not_public_verification' => true,
                'operator_approval_required' => true,
                'bundle_hash_match_required' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
    private function verifyPublicReceipt(string $path, string $bundleHash, string $localIndexHash): array
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

        return [
            'status' => $blockers === [] ? 'verified' : 'blocked',
            'receipt_hash' => hash('sha256', $raw),
            'public_url_hash' => isset($receipt['public_url']) ? hash('sha256', (string) $receipt['public_url']) : null,
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
}
