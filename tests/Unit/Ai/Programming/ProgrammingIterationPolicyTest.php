<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use Tests\TestCase;

class ProgrammingIterationPolicyTest extends TestCase
{
    public function test_normalizes_programming_iterations_with_canonical_caps(): void
    {
        $this->assertSame(ProgrammingIterationPolicy::DEFAULT_DEV_ITERATIONS, ProgrammingIterationPolicy::normalize(null));
        $this->assertSame(ProgrammingIterationPolicy::MIN_ITERATIONS, ProgrammingIterationPolicy::normalize(-10));
        $this->assertSame(ProgrammingIterationPolicy::MAX_ITERATIONS, ProgrammingIterationPolicy::normalize(999));
        $this->assertSame(4, ProgrammingIterationPolicy::normalize('4'));
    }

    public function test_profile_and_execution_policy_minimums_are_explicit(): void
    {
        $this->assertSame(ProgrammingIterationPolicy::MIN_FORGE_ITERATIONS, ProgrammingIterationPolicy::forProfile(1, 'forge'));
        $this->assertSame(ProgrammingIterationPolicy::MIN_COMPLETE_ITERATIONS, ProgrammingIterationPolicy::forExecutionPolicy(1, complete: true, forge: false));
        $this->assertSame(ProgrammingIterationPolicy::DEFAULT_SINGLE_PASS_ITERATIONS, ProgrammingIterationPolicy::forExecutionPolicy(null, complete: false, forge: false));
        $this->assertSame(ProgrammingIterationPolicy::MIN_REPAIR_ITERATIONS, ProgrammingIterationPolicy::normalize(1, ProgrammingIterationPolicy::MIN_REPAIR_ITERATIONS, ProgrammingIterationPolicy::MIN_REPAIR_ITERATIONS));
    }

    public function test_programming_execution_request_uses_same_iteration_policy_for_harness_attempts(): void
    {
        $dev = ProgrammingExecutionRequest::fromArray([
            'profile' => 'dev',
            'complete' => false,
        ]);
        $complete = ProgrammingExecutionRequest::fromArray([
            'profile' => 'dev',
            'complete' => true,
            'max_attempts' => 1,
        ]);
        $forge = ProgrammingExecutionRequest::fromArray([
            'profile' => 'forge',
            'max_attempts' => 1,
        ]);

        $this->assertSame(ProgrammingIterationPolicy::DEFAULT_SINGLE_PASS_ITERATIONS, $dev->harnessOptions()['max_attempts']);
        $this->assertSame(ProgrammingIterationPolicy::MIN_COMPLETE_ITERATIONS, $complete->harnessOptions()['max_attempts']);
        $this->assertSame(ProgrammingIterationPolicy::MIN_FORGE_ITERATIONS, $forge->harnessOptions()['max_attempts']);
    }
}
