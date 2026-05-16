<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EscalationSignalsTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $s = new EscalationSignals(
            fileCount: 7,
            layersTouched: 3,
            riskKeywords: ['security', 'migration'],
            contextRequiredChars: 12000,
            threadMessages: 5,
            priorFailureCount: 1,
        );
        $rebuilt = EscalationSignals::fromArray($s->toCanonicalArray());
        $this->assertHashStable($s, $rebuilt);
        $this->assertContractSurface($s);
    }

    public function test_negative_counters_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EscalationSignals(fileCount: -1, layersTouched: 0, riskKeywords: [], contextRequiredChars: null, threadMessages: null, priorFailureCount: 0);
    }
}
