<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationAttestationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use Tests\TestCase;

class AtlasFrontendPublicationAttestationServiceTest extends TestCase
{
    public function test_attests_local_bundle_as_pending_public_distribution(): void
    {
        $payload = app(AtlasFrontendPublicationAttestationService::class)->attest([
            'schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
            'status' => 'local_ready',
            'publication_hash' => str_repeat('a', 64),
            'bundle_hash' => str_repeat('b', 64),
            'claim_policy' => [
                'public_distribution_claim_allowed' => false,
            ],
        ], [
            'rerun_action' => 'rerun_custom_surface',
        ]);

        $this->assertSame(AtlasFrontendPublicationAttestationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('local_bundle_ready_publication_pending', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_bundle_is_not_public_distribution'));
        $this->assertContains('rerun_custom_surface', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['attestation_hash']);
    }

    public function test_attests_public_verified_without_raw_public_url(): void
    {
        $payload = app(AtlasFrontendPublicationAttestationService::class)->attest([
            'schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
            'status' => 'public_verified',
            'publication_hash' => str_repeat('a', 64),
            'bundle_hash' => str_repeat('b', 64),
            'public_receipt' => [
                'status' => 'verified',
                'receipt_hash' => str_repeat('c', 64),
                'public_url_hash' => str_repeat('d', 64),
            ],
            'claim_policy' => [
                'public_distribution_claim_allowed' => true,
            ],
        ], [
            'world_best_claim_allowed' => true,
        ]);

        $this->assertSame('public_verified', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertNull(data_get($payload, 'public_url'));
        $this->assertSame([], $payload['required_next_actions']);
    }

    public function test_attests_invalid_publication_schema_before_claims(): void
    {
        $payload = app(AtlasFrontendPublicationAttestationService::class)->attest([
            'schema_version' => 'wrong.schema',
            'status' => 'local_ready',
            'claim_policy' => [
                'public_distribution_claim_allowed' => true,
            ],
        ]);

        $this->assertSame('invalid_report_schema', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
    }
}
