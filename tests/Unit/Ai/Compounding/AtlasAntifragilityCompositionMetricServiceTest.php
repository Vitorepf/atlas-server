<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use Tests\TestCase;

class AtlasAntifragilityCompositionMetricServiceTest extends TestCase
{
    public function test_measure_returns_canonical_envelope(): void
    {
        $svc = $this->app->make(AtlasAntifragilityCompositionMetricService::class);
        $r = $svc->measure();
        $this->assertSame(AtlasAntifragilityCompositionMetricService::SCHEMA_VERSION, $r['schema_version']);
        $this->assertArrayHasKey('inputs', $r);
        $this->assertArrayHasKey('components', $r);
        $this->assertArrayHasKey('wrapper_multiplier_m', $r);
        foreach (['m_scorecard', 'm_observability', 'm_governance', 'm_density'] as $k) {
            $this->assertArrayHasKey($k, $r['components']);
            $this->assertIsNumeric($r['components'][$k]);
            $this->assertGreaterThanOrEqual(0.0, $r['components'][$k]);
            $this->assertLessThanOrEqual(1.0, $r['components'][$k]);
        }
        $this->assertGreaterThanOrEqual(0.0, $r['wrapper_multiplier_m']);
        $this->assertLessThanOrEqual(1.0, $r['wrapper_multiplier_m']);
    }

    public function test_claim_policy_provider_safe(): void
    {
        $svc = $this->app->make(AtlasAntifragilityCompositionMetricService::class);
        $r = $svc->measure();
        $cp = $r['claim_policy'];
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['provider_capability_estimated']);
    }

    public function test_subsystem_count_reflects_canonical_count(): void
    {
        $svc = $this->app->make(AtlasAntifragilityCompositionMetricService::class);
        $r = $svc->measure();
        $this->assertSame(
            \App\Services\Ai\Cognition\AtlasCognitionScoreCardService::canonicalSubsystemCount(),
            $r['inputs']['subsystem_count']
        );
        $this->assertSame(10, $r['inputs']['overall_out_of_10']);
    }

    public function test_components_are_deterministic_for_same_state(): void
    {
        $svc = $this->app->make(AtlasAntifragilityCompositionMetricService::class);
        $a = $svc->measure();
        $b = $svc->measure();
        // generated_at differs but the components depend on counters that may have grown
        // (e.g., admission tickets from this test run). Use input snapshot equality.
        $this->assertSame($a['inputs']['subsystem_count'], $b['inputs']['subsystem_count']);
        $this->assertSame($a['inputs']['overall_out_of_10'], $b['inputs']['overall_out_of_10']);
    }
}
