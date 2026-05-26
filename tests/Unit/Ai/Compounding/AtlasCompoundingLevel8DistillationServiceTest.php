<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasCompoundingLevel8DistillationService;
use Tests\TestCase;

class AtlasCompoundingLevel8DistillationServiceTest extends TestCase
{
    public function test_distill_returns_canonical_envelope(): void
    {
        $svc = $this->app->make(AtlasCompoundingLevel8DistillationService::class);
        $r = $svc->distill(false);
        $this->assertSame(AtlasCompoundingLevel8DistillationService::SCHEMA_VERSION, $r['schema_version']);
        $this->assertContains($r['level'], [
            AtlasCompoundingLevel8DistillationService::LEVEL_L7,
            AtlasCompoundingLevel8DistillationService::LEVEL_L8,
            AtlasCompoundingLevel8DistillationService::LEVEL_L9,
        ]);
        $this->assertStringStartsWith('sha256:', $r['envelope_hash']);
        $this->assertStringStartsWith('sha256:', $r['kernel_hash']);
    }

    public function test_claim_policy_safe(): void
    {
        $svc = $this->app->make(AtlasCompoundingLevel8DistillationService::class);
        $r = $svc->distill(false);
        $this->assertFalse($r['claim_policy']['benchmark_claim_allowed']);
        $this->assertFalse($r['claim_policy']['rivals_claim_allowed']);
        $this->assertFalse($r['claim_policy']['superiority_claim_allowed']);
    }

    public function test_observation_and_proposal_counts_are_nonneg(): void
    {
        $svc = $this->app->make(AtlasCompoundingLevel8DistillationService::class);
        $r = $svc->distill(false);
        $this->assertGreaterThanOrEqual(0, $r['observation_count']);
        $this->assertGreaterThanOrEqual(0, $r['proposal_count']);
    }
}
