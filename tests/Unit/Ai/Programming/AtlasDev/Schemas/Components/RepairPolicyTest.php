<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use PHPUnit\Framework\TestCase;

final class RepairPolicyTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_is_sorted(): void
    {
        $policy = new RepairPolicy(
            maxAttempts: 1,
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: true,
        );
        $this->assertSame(
            ['abort_on_same_signature_twice', 'max_attempts', 'requires_failed_gate_output', 'same_provider'],
            array_keys($policy->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($policy);
    }

    public function test_round_trip(): void
    {
        $policy = new RepairPolicy(maxAttempts: 2, sameProvider: true, requiresFailedGateOutput: true, abortOnSameSignatureTwice: true);
        $rebuilt = RepairPolicy::fromArray(json_decode($policy->toJson(), true));
        $this->assertHashStable($policy, $rebuilt);
        $this->assertJsonRoundtripStable($policy);
    }

    public function test_hash_differs_when_max_attempts_changes(): void
    {
        $a = new RepairPolicy(maxAttempts: 1, sameProvider: true, requiresFailedGateOutput: true, abortOnSameSignatureTwice: true);
        $b = new RepairPolicy(maxAttempts: 2, sameProvider: true, requiresFailedGateOutput: true, abortOnSameSignatureTwice: true);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }

    public function test_from_array_defaults_locked_truths_when_absent(): void
    {
        $rebuilt = RepairPolicy::fromArray(['max_attempts' => 1]);

        $this->assertTrue($rebuilt->sameProvider);
        $this->assertTrue($rebuilt->requiresFailedGateOutput);
        $this->assertTrue($rebuilt->abortOnSameSignatureTwice);
    }
}
