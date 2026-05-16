<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use PHPUnit\Framework\TestCase;

final class VerificationPlanTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_is_sorted(): void
    {
        $plan = new VerificationPlan(profile: 'php_laravel', commands: ['composer test']);
        $this->assertSame(
            ['commands', 'no_test_reason', 'profile'],
            array_keys($plan->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($plan);
        $this->assertSame('atlas.dev.components.verification_plan.v1', $plan->schemaVersion());
    }

    public function test_no_test_reason_required_for_generic_profile_round_trip(): void
    {
        $plan = new VerificationPlan(
            profile: 'generic_no_test',
            commands: [],
            noTestReason: 'docs only',
        );

        $rebuilt = VerificationPlan::fromArray(json_decode($plan->toJson(), true));
        $this->assertHashStable($plan, $rebuilt);
        $this->assertSame('docs only', $rebuilt->noTestReason);
        $this->assertJsonRoundtripStable($plan);
    }

    public function test_hash_changes_with_command_change(): void
    {
        $a = new VerificationPlan(profile: 'ts_react', commands: ['pnpm test']);
        $b = new VerificationPlan(profile: 'ts_react', commands: ['pnpm test --filter=x']);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
