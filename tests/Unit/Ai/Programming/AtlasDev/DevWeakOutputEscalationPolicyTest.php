<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputEscalationPolicy;
use PHPUnit\Framework\TestCase;

final class DevWeakOutputEscalationPolicyTest extends TestCase
{
    private DevWeakOutputEscalationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DevWeakOutputEscalationPolicy();
    }

    /** @testdox retry_same_tier when weak count is below threshold */
    public function test_retry_same_tier_below_threshold(): void
    {
        $result = $this->policy->decide([['signal' => 'weak']], 'small');

        $this->assertSame('retry_same_tier', $result['action']);
        $this->assertSame('small', $result['next_tier']);
        $this->assertStringContainsString('weak_count_1_below_2_threshold', $result['reason']);
    }

    /** @testdox escalate_tier when weak count reaches threshold */
    public function test_escalate_tier_at_threshold(): void
    {
        $result = $this->policy->decide([['signal' => 'weak'], ['signal' => 'weak']], 'small');

        $this->assertSame('escalate_tier', $result['action']);
        $this->assertSame('medium', $result['next_tier']);
        $this->assertStringContainsString('escalating_to_medium', $result['reason']);
    }

    /** @testdox full ladder climb: small → medium → frontier → stop */
    public function test_full_ladder_climb(): void
    {
        $weak = [['signal' => 'weak'], ['signal' => 'weak']];

        // small → medium
        $r = $this->policy->decide($weak, 'small');
        $this->assertSame('escalate_tier', $r['action']);
        $this->assertSame('medium', $r['next_tier']);

        // medium → frontier
        $r = $this->policy->decide($weak, 'medium');
        $this->assertSame('escalate_tier', $r['action']);
        $this->assertSame('frontier', $r['next_tier']);

        // frontier → stop
        $r = $this->policy->decide($weak, 'frontier');
        $this->assertSame('stop_and_surface', $r['action']);
        $this->assertSame('frontier', $r['next_tier']);
        $this->assertStringContainsString('no_further_escalation', $r['reason']);
    }

    /** @testdox unknown tier surfaces stop_and_surface */
    public function test_unknown_tier_stops(): void
    {
        $result = $this->policy->decide([['signal' => 'weak']], 'unknown_tier');

        $this->assertSame('stop_and_surface', $result['action']);
        $this->assertSame('unknown_tier', $result['next_tier']);
        $this->assertStringContainsString('unknown_tier', $result['reason']);
    }

    /** @testdox config override changes attempts-per-tier threshold */
    public function test_config_override_changes_threshold(): void
    {
        $policy = new DevWeakOutputEscalationPolicy(['attempts_per_tier' => 3]);

        // With threshold=3, two weak signals still retry
        $result = $policy->decide([['signal' => 'weak'], ['signal' => 'weak']], 'small');
        $this->assertSame('retry_same_tier', $result['action']);

        // Three weak signals now escalate
        $result = $policy->decide([['signal' => 'weak'], ['signal' => 'weak'], ['signal' => 'weak']], 'small');
        $this->assertSame('escalate_tier', $result['action']);
        $this->assertSame('medium', $result['next_tier']);
    }

    /** @testdox empty history retries same tier */
    public function test_empty_history_retries(): void
    {
        $result = $this->policy->decide([], 'small');

        $this->assertSame('retry_same_tier', $result['action']);
        $this->assertSame('small', $result['next_tier']);
    }
}
