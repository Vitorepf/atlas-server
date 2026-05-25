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
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt);

        $this->assertSame('public_verified', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertSame('verified', data_get($payload, 'public_receipt.status'));
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

    public function test_receipt_template_is_not_public_verification(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-publication-template-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendPublicationVerifierService::class)->writeReceiptTemplate($dir);

        $this->assertSame('atlas.frontend.publication_receipt_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.template_is_not_public_verification'));
        $this->assertFileExists($dir.'/publication-receipt.json');
    }
}
