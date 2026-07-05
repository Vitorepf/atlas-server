<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierCostWasteCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierCostWasteCircuitBreakerTest extends TestCase
{
    private AtlasExternalBrainAmplifierCostWasteCircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->breaker = new AtlasExternalBrainAmplifierCostWasteCircuitBreaker;
    }

    public function test_high_cost_low_lift_blocked(): void
    {
        $result = $this->breaker->evaluate([
            'effort_spent' => 0.9,
            'accepted_quality_lift' => 0.1,
            'downstream_success_rate' => 0.1,
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame('high_cost_low_lift', $result['reason']);
    }

    public function test_low_cost_high_lift_passes(): void
    {
        $result = $this->breaker->evaluate([
            'effort_spent' => 0.1,
            'accepted_quality_lift' => 0.8,
            'downstream_success_rate' => 0.8,
        ]);

        $this->assertFalse($result['blocked']);
    }

    public function test_zero_effort_passes(): void
    {
        $result = $this->breaker->evaluate([]);

        $this->assertFalse($result['blocked']);
    }

    public function test_schema_present(): void
    {
        $result = $this->breaker->evaluate([]);
        $this->assertSame(AtlasExternalBrainAmplifierCostWasteCircuitBreaker::SCHEMA, $result['schema']);
    }
}
