<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepairSummaryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $r = new RepairSummary(attemptCount: 1, failureCapsuleRefs: ['p1'], convertedToGreen: true);
        $rebuilt = RepairSummary::fromArray($r->toCanonicalArray());
        $this->assertHashStable($r, $rebuilt);
        $this->assertContractSurface($r);
    }

    public function test_negative_attempt_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RepairSummary(attemptCount: -1, failureCapsuleRefs: [], convertedToGreen: false);
    }
}
