<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CompletionSummaryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $c = new CompletionSummary(status: CompletionSummary::STATUS_NEEDS_REVIEW, honestyFlags: ['scope_expanded'], residualRisks: []);
        $rebuilt = CompletionSummary::fromArray($c->toCanonicalArray());
        $this->assertHashStable($c, $rebuilt);
        $this->assertContractSurface($c);
    }

    public function test_passed_with_honesty_flags_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompletionSummary(status: CompletionSummary::STATUS_PASSED, honestyFlags: ['foo'], residualRisks: []);
    }

    public function test_invalid_status_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompletionSummary(status: 'invented', honestyFlags: [], residualRisks: []);
    }
}
