<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Truncation;
use PHPUnit\Framework\TestCase;

final class TruncationTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_is_sorted(): void
    {
        $truncation = new Truncation(truncated: true, reasons: ['budget_exceeded', 'core_only']);
        $this->assertSame(['reasons', 'truncated'], array_keys($truncation->toCanonicalArray()));
        $this->assertCanonicalArrayKeysSorted($truncation);
    }

    public function test_reasons_preserve_order_and_round_trip(): void
    {
        $truncation = new Truncation(truncated: true, reasons: ['z', 'a', 'm']);
        $rebuilt = Truncation::fromArray(json_decode($truncation->toJson(), true));
        $this->assertSame(['z', 'a', 'm'], $rebuilt->reasons);
        $this->assertHashStable($truncation, $rebuilt);
        $this->assertJsonRoundtripStable($truncation);
    }

    public function test_hash_differs_when_truncated_flips(): void
    {
        $a = new Truncation(truncated: false, reasons: []);
        $b = new Truncation(truncated: true, reasons: []);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
