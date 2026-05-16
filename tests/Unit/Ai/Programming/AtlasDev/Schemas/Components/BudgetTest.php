<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Budget;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_is_sorted(): void
    {
        $budget = new Budget(charsRequested: 12000, charsUsed: 8000);
        $this->assertSame(['chars_requested', 'chars_used'], array_keys($budget->toCanonicalArray()));
        $this->assertCanonicalArrayKeysSorted($budget);
        $this->assertSame('atlas.dev.components.budget.v1', $budget->schemaVersion());
    }

    public function test_round_trip(): void
    {
        $budget = new Budget(charsRequested: 12000, charsUsed: 8000);
        $rebuilt = Budget::fromArray(json_decode($budget->toJson(), true));
        $this->assertHashStable($budget, $rebuilt);
        $this->assertJsonRoundtripStable($budget);
    }

    public function test_hash_differs_when_chars_used_changes(): void
    {
        $a = new Budget(charsRequested: 12000, charsUsed: 8000);
        $b = new Budget(charsRequested: 12000, charsUsed: 8001);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
