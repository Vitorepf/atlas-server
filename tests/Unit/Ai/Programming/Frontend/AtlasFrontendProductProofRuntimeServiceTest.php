<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use Tests\TestCase;

class AtlasFrontendProductProofRuntimeServiceTest extends TestCase
{
    public function test_catalog_defines_multi_company_demo_proofs_without_public_claim(): void
    {
        $payload = app(AtlasFrontendProductProofRuntimeService::class)->catalog();

        $this->assertSame('atlas.frontend.product_proof_runtime.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('local_product_demo_catalog', $payload['proof_type']);
        $this->assertSame(5, $payload['demo_count']);
        $this->assertContains('external_hosted_product_site_required_for_public_distribution_claim', $payload['remaining_gaps']);
        $this->assertNotContains('local_demo_catalog_missing_required_proofs', $payload['remaining_gaps']);
        $this->assertTrue((bool) data_get($payload, 'publication_policy.public_site_claim_requires_hosted_demo'));
        $this->assertTrue((bool) data_get($payload, 'publication_policy.local_catalog_is_not_public_distribution'));
        $this->assertFalse((bool) data_get($payload, 'publication_policy.raw_customer_source_returned'));
        $this->assertContains('live_mode_repair_loop', collect($payload['demos'])->pluck('id')->all());
        $this->assertSame('ready', data_get($payload, 'checks.0.status'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['product_proof_hash']);
    }

    public function test_build_static_bundle_creates_publishable_local_artifacts_without_hosting_claim(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-proof-bundle-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($output);

        $this->assertSame('atlas.frontend.product_proof_bundle.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'publication_policy.publishable_static_bundle_created'));
        $this->assertFalse((bool) data_get($payload, 'publication_policy.external_hosting_verified'));
        $this->assertFileExists($output.'/index.html');
        $this->assertFileExists($output.'/manifest.json');
        $this->assertCount(5, $payload['assets']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['bundle_hash']);
    }
}
