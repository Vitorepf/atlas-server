<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\IntelligenceFactory;

use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryCertificationService;
use Tests\Concerns\CreatesIntelligenceFactoryTables;
use Tests\TestCase;

final class AtlasIntelligenceFactoryCertificationServiceTest extends TestCase
{
    use CreatesIntelligenceFactoryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIntelligenceFactoryTables();
    }

    protected function tearDown(): void
    {
        $this->dropIntelligenceFactoryTables();
        parent::tearDown();
    }

    public function test_certification_passes_with_runtime_surface_and_claim_policy(): void
    {
        $payload = app(AtlasIntelligenceFactoryCertificationService::class)->certify();

        $this->assertSame(AtlasIntelligenceFactoryCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(7, $payload['summary']['pass']);
        $this->assertFalse($payload['claim_policy']['external_execution_performed']);
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertTrue($payload['claim_policy']['auto_trust_disabled']);
        $this->assertNotEmpty($payload['certification_hash']);
    }

    public function test_certification_reports_no_blockers_when_surface_is_ready(): void
    {
        $payload = app(AtlasIntelligenceFactoryCertificationService::class)->certify();

        $this->assertSame([], $payload['blockers']);
        $this->assertNotEmpty($payload['certification_hash']);
    }
}
