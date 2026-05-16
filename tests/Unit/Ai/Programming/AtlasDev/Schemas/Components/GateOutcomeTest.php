<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GateOutcomeTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $g = new GateOutcome(
            name: 'scope_guard_light',
            status: GateOutcome::STATUS_PASSED,
            required: true,
            evidenceRef: 'path/to/ref',
            fresh: true,
            waiverReason: null,
        );
        $rebuilt = GateOutcome::fromArray($g->toCanonicalArray());
        $this->assertHashStable($g, $rebuilt);
        $this->assertContractSurface($g);
    }

    public function test_waived_requires_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GateOutcome(
            name: 'g',
            status: GateOutcome::STATUS_WAIVED,
            required: false,
            evidenceRef: null,
            fresh: false,
            waiverReason: null,
        );
    }

    public function test_invalid_status_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GateOutcome('g', 'invented', true, null, true, null);
    }
}
