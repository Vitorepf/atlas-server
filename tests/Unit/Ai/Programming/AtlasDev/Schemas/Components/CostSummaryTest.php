<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CostSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CostSummaryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip_with_nulls(): void
    {
        $c = new CostSummary(providerCalls: 1, tokensIn: null, tokensOut: null, estimatedCostUsd: null, wallTimeMs: null);
        $rebuilt = CostSummary::fromArray($c->toCanonicalArray());
        $this->assertHashStable($c, $rebuilt);
        $this->assertContractSurface($c);
    }

    public function test_round_trip_with_values(): void
    {
        $c = new CostSummary(providerCalls: 2, tokensIn: 100, tokensOut: 50, estimatedCostUsd: 0.025, wallTimeMs: 1000);
        $rebuilt = CostSummary::fromArray($c->toCanonicalArray());
        $this->assertHashStable($c, $rebuilt);
        $this->assertSame(0.025, $rebuilt->estimatedCostUsd);
    }

    public function test_negative_provider_calls_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CostSummary(providerCalls: -1, tokensIn: null, tokensOut: null, estimatedCostUsd: null, wallTimeMs: null);
    }
}
