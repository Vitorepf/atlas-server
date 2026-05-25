<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendPublicationVerifierServiceTest extends TestCase
{
    public function test_verifier_marks_static_bundle_local_ready_without_public_claim(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-local-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('atlas.frontend.publication_verifier.v1', $payload['schema_version']);
        $this->assertSame('local_ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_bundle_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertContains('public_receipt_missing', $payload['warnings']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['publication_hash']);
    }

    public function test_verifier_marks_public_verified_with_valid_receipt(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-public-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $receipt = $bundle.'/publication-receipt.json';

        File::put($receipt, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend-proof/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => hash_file('sha256', $bundle.'/index.html'),
            'local_index_hash' => hash_file('sha256', $bundle.'/index.html'),
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt);

        $this->assertSame('public_verified', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertSame('verified', data_get($payload, 'public_receipt.status'));
    }

    public function test_verifier_carries_frontend_app_scope_from_bundle_manifest(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-scope-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('local_ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_requires_matching_frontend_app_scope'));
    }

    public function test_verifier_blocks_public_receipt_without_matching_frontend_app_scope(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-scope-missing-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');
        $receipt = $bundle.'/publication-receipt.json';

        File::put($receipt, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend-proof/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => hash_file('sha256', $bundle.'/index.html'),
            'local_index_hash' => hash_file('sha256', $bundle.'/index.html'),
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('public_receipt_invalid', $payload['blockers']);
        $this->assertContains('public_receipt_frontend_app_scope_missing', data_get($payload, 'public_receipt.blockers'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
    }

    public function test_verifier_marks_public_verified_with_matching_frontend_app_scope_receipt(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-scope-public-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');
        $receipt = $bundle.'/publication-receipt.json';

        File::put($receipt, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend-proof/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => hash_file('sha256', $bundle.'/index.html'),
            'local_index_hash' => hash_file('sha256', $bundle.'/index.html'),
            'frontend_app_scope' => [
                'status' => 'subscope_selected',
                'relative_name' => 'apps/web',
                'relative_name_hash' => hash('sha256', 'apps/web'),
            ],
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt);

        $this->assertSame('public_verified', $payload['status']);
        $this->assertSame('apps/web', data_get($payload, 'public_receipt.frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
    }

    public function test_verifier_blocks_public_receipt_with_mismatched_index_hash(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-index-mismatch-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $receipt = $bundle.'/publication-receipt.json';

        File::put($receipt, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend-proof/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => str_repeat('a', 64),
            'local_index_hash' => hash_file('sha256', $bundle.'/index.html'),
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('public_receipt_invalid', $payload['blockers']);
        $this->assertContains('public_receipt_index_content_hash_mismatch', data_get($payload, 'public_receipt.blockers'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
    }

    public function test_verifier_blocks_tampered_bundle_hash(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-tamper-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        File::put($bundle.'/index.html', 'tampered');

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('index_hash_mismatch', $payload['blockers']);
    }

    public function test_verifier_validates_product_site_assets_and_download_manifest(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-product-assets-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('local_ready', $payload['status']);
        $this->assertSame('atlas.frontend.product_proof_site_assets.v1', data_get($payload, 'product_site_assets.schema_version'));
        $this->assertSame('local_ready_publication_pending', data_get($payload, 'product_site_assets.status'));
        $this->assertTrue((bool) data_get($payload, 'product_site_assets.downloads_manifest_present'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'product_site_assets.download_count'));
    }

    public function test_verifier_blocks_tampered_product_site_tutorial(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-tutorial-tamper-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        File::put($bundle.'/getting-started.html', 'tampered');

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('product_site_asset_tutorial_hash_mismatch', $payload['blockers']);
    }

    public function test_verifier_blocks_tampered_downloads_manifest(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-downloads-tamper-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        File::put($bundle.'/downloads.json', json_encode([
            'schema_version' => 'atlas.frontend.product_proof_downloads.v1',
            'status' => 'local_ready_publication_pending',
            'downloads' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('product_site_asset_downloads_manifest_hash_mismatch', $payload['blockers']);
        $this->assertContains('product_site_downloads_missing', $payload['blockers']);
    }

    public function test_verifier_blocks_tampered_manifest_bundle_hash(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-manifest-tamper-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $manifest = json_decode(File::get($bundle.'/manifest.json'), true);
        $manifest['publication_policy']['external_hosting_verified'] = true;
        File::put($bundle.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('bundle_hash_mismatch', $payload['blockers']);
    }

    public function test_receipt_template_is_not_public_verification(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-publication-template-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendPublicationVerifierService::class)->writeReceiptTemplate($dir);

        $this->assertSame('atlas.frontend.publication_receipt_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.template_is_not_public_verification'));
        $this->assertFileExists($dir.'/publication-receipt.json');
    }

    public function test_receipt_template_prefills_bundle_hash_index_hash_and_frontend_app_scope(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publication-template-bundle-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');
        $dir = sys_get_temp_dir().'/atlas-frontend-publication-template-prefill-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendPublicationVerifierService::class)->writeReceiptTemplate($dir, $bundle);
        $receipt = json_decode(File::get($dir.'/publication-receipt.json'), true);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'bundle_context.prefilled_from_bundle'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.prefilled_bundle_hash_is_not_public_verification'));
        $this->assertSame($manifest['bundle_hash'], $receipt['bundle_hash']);
        $this->assertSame(data_get($manifest, 'index.hash'), $receipt['index_content_hash']);
        $this->assertSame(data_get($manifest, 'index.hash'), $receipt['local_index_hash']);
        $this->assertSame('apps/web', data_get($receipt, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($receipt, 'frontend_app_scope.relative_name_hash'));
    }

    public function test_receipt_template_blocks_invalid_bundle_context(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-publication-template-invalid-bundle-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendPublicationVerifierService::class)->writeReceiptTemplate($dir, $dir.'/missing-bundle');

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('bundle_directory_missing', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'bundle_context.prefilled_from_bundle'));
        $this->assertFileExists($dir.'/publication-receipt.json');
    }
}
