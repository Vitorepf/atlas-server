<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EscalationSummaryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip_not_recommended(): void
    {
        $e = new EscalationSummary(recommended: false, target: null, reasons: [], decisionRef: null);
        $rebuilt = EscalationSummary::fromArray($e->toCanonicalArray());
        $this->assertHashStable($e, $rebuilt);
        $this->assertContractSurface($e);
    }

    public function test_round_trip_recommended(): void
    {
        $e = new EscalationSummary(
            recommended: true,
            target: EscalationSummary::TARGET_FORGE,
            reasons: ['scope_explosion'],
            decisionRef: 'storage/atlas-dev/receipts/r1/escalation_decision.json',
        );
        $rebuilt = EscalationSummary::fromArray($e->toCanonicalArray());
        $this->assertHashStable($e, $rebuilt);
    }

    public function test_recommended_requires_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EscalationSummary(recommended: true, target: null, reasons: ['r'], decisionRef: null);
    }

    public function test_recommended_requires_reasons(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EscalationSummary(recommended: true, target: EscalationSummary::TARGET_FORGE, reasons: [], decisionRef: null);
    }
}
