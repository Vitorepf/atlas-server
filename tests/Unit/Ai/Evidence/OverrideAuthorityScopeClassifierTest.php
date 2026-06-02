<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Evidence;

use App\Services\Ai\Evidence\OverrideAuthorityScopeClassifier;
use PHPUnit\Framework\TestCase;

final class OverrideAuthorityScopeClassifierTest extends TestCase
{
    private OverrideAuthorityScopeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OverrideAuthorityScopeClassifier();
    }

    public function testOperatorOverAgentRequiredIsInScope(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'vitor',
            'actor_tier' => 'operator',
            'target' => ['required_authority_tier' => 'agent'],
        ]);

        $this->assertSame('in_scope', $result['verdict']);
        $this->assertSame('operator', $result['actor_tier']);
        $this->assertSame('agent', $result['required_tier']);
        $this->assertSame(3, $result['tier_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testAgentUnderOperatorRequiredIsOutOfScopeWithBelowReason(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'codex-bot',
            'actor_tier' => 'agent',
            'target' => ['required_authority_tier' => 'operator'],
        ]);

        $this->assertSame('out_of_scope', $result['verdict']);
        $this->assertSame('agent', $result['actor_tier']);
        $this->assertSame('operator', $result['required_tier']);
        $this->assertSame(1, $result['tier_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertContains('actor_tier_below_required', $result['reasons']);
    }

    public function testMissingActorIsUnscoped(): void
    {
        $result = $this->classifier->classify([
            'actor_tier' => 'operator',
            'target' => ['required_authority_tier' => 'agent'],
        ]);

        $this->assertSame('unscoped', $result['verdict']);
        $this->assertSame('operator', $result['actor_tier']);
        $this->assertSame('agent', $result['required_tier']);
        $this->assertSame(3, $result['tier_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertContains('actor_missing', $result['reasons']);
    }

    public function testRequiredTierPresentButUnknownActorTierFailsClosed(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'mystery-actor',
            'actor_tier' => 'ghost',
            'target' => ['required_authority_tier' => 'maintainer'],
        ]);

        $this->assertSame('out_of_scope', $result['verdict']);
        $this->assertSame('ghost', $result['actor_tier']);
        $this->assertSame('maintainer', $result['required_tier']);
        $this->assertSame(0, $result['tier_rank']);
        $this->assertTrue($result['fail_closed']);
        $this->assertNotContains('actor_tier_below_required', $result['reasons']);
    }

    public function testMissingRequiredTierIsUnscoped(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'vitor',
            'actor_tier' => 'operator',
            'target' => [],
        ]);

        $this->assertSame('unscoped', $result['verdict']);
        $this->assertNull($result['required_tier']);
        $this->assertSame('operator', $result['actor_tier']);
        $this->assertFalse($result['fail_closed']);
        $this->assertContains('required_tier_missing', $result['reasons']);
    }

    public function testMaintainerMeetingMaintainerRequiredIsInScopeAtEqualRank(): void
    {
        $result = $this->classifier->classify([
            'actor' => 'release-eng',
            'decided_by_tier' => 'maintainer',
            'required_tier' => 'maintainer',
        ]);

        $this->assertSame('in_scope', $result['verdict']);
        $this->assertSame('maintainer', $result['actor_tier']);
        $this->assertSame('maintainer', $result['required_tier']);
        $this->assertSame(2, $result['tier_rank']);
        $this->assertFalse($result['fail_closed']);
    }

    public function testMaintainerUnderOperatorRequiredIsOutOfScope(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'release-eng',
            'actor_tier' => 'maintainer',
            'target' => ['required_tier' => 'operator'],
        ]);

        $this->assertSame('out_of_scope', $result['verdict']);
        $this->assertSame(2, $result['tier_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertContains('actor_tier_below_required', $result['reasons']);
    }

    public function testTierResolutionIsCaseInsensitive(): void
    {
        $result = $this->classifier->classify([
            'decided_by' => 'vitor',
            'actor_tier' => 'OPERATOR',
            'target' => ['required_authority_tier' => 'Maintainer'],
        ]);

        $this->assertSame('in_scope', $result['verdict']);
        $this->assertSame('operator', $result['actor_tier']);
        $this->assertSame('maintainer', $result['required_tier']);
        $this->assertSame(3, $result['tier_rank']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $override = [
            'decided_by' => 'codex-bot',
            'actor_tier' => 'agent',
            'target' => ['required_authority_tier' => 'operator'],
        ];

        $first = $this->classifier->classify($override);
        $second = $this->classifier->classify($override);

        $this->assertSame($first, $second);
    }
}
