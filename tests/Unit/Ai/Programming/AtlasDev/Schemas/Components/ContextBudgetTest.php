<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use PHPUnit\Framework\TestCase;

final class ContextBudgetTest extends TestCase
{
    use SchemaContractAssertions;

    private function example(): ContextBudget
    {
        return new ContextBudget(
            maxChars: 12000,
            maxDocs: 4,
            maxCandidateFiles: 6,
            maxPlanSteps: 4,
            maxProviderCalls: 1,
            maxRepairAttempts: 1,
        );
    }

    public function test_canonical_array_is_sorted_and_full(): void
    {
        $budget = $this->example();
        $this->assertSame(
            ['max_candidate_files', 'max_chars', 'max_docs', 'max_plan_steps', 'max_provider_calls', 'max_repair_attempts'],
            array_keys($budget->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($budget);
    }

    public function test_round_trip_preserves_state(): void
    {
        $budget = $this->example();
        $rebuilt = ContextBudget::fromArray(json_decode($budget->toJson(), true));
        $this->assertHashStable($budget, $rebuilt);
        $this->assertJsonRoundtripStable($budget);
    }

    public function test_hash_changes_when_any_field_changes(): void
    {
        $a = $this->example();
        $b = new ContextBudget(maxChars: 12001, maxDocs: 4, maxCandidateFiles: 6, maxPlanSteps: 4, maxProviderCalls: 1, maxRepairAttempts: 1);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }

    public function test_schema_version_is_canonical(): void
    {
        $this->assertSame('atlas.dev.components.context_budget.v1', $this->example()->schemaVersion());
    }
}
