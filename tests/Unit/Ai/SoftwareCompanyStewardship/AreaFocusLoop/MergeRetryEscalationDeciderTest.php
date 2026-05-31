<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\MergeRetryEscalationDecider;
use PHPUnit\Framework\TestCase;

final class MergeRetryEscalationDeciderTest extends TestCase
{
    private MergeRetryEscalationDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new MergeRetryEscalationDecider();
    }

    public function testBaseIsAncestorAttemptsMerge(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => true,
        ]);

        $this->assertSame('atlas.software_company_stewardship.merge_retry_escalation_decision.v1', $result['schema_version']);
        $this->assertSame('attempt_merge', $result['action']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('', $result['reason']);
    }

    public function testRebaseFailedAtMaxAttemptsEscalatesWithRebaseFailedReason(): void
    {
        $result = $this->decider->decide([
            'attempts' => 1,
            'max_attempts' => 2,
            'base_is_ancestor' => false,
            'rebase_failed' => true,
        ]);

        $this->assertSame('escalate', $result['action']);
        $this->assertTrue($result['escalate']);
        $this->assertSame('rebase_failed', $result['reason']);
    }

    public function testRebaseFailedBelowMaxAttemptsRetriesRebaseThenMerge(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => false,
            'rebase_failed' => true,
        ]);

        $this->assertSame('attempt_rebase_then_merge', $result['action']);
        $this->assertFalse($result['escalate']);
    }

    public function testNotAncestorWithoutRebaseFailureAttemptsRebaseThenMerge(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => false,
            'rebase_failed' => false,
        ]);

        $this->assertSame('attempt_rebase_then_merge', $result['action']);
        $this->assertFalse($result['escalate']);
    }

    public function testMergeFailureAtMaxAttemptsEscalatesWithLastFailureReason(): void
    {
        $result = $this->decider->decide([
            'attempts' => 1,
            'max_attempts' => 2,
            'base_is_ancestor' => true,
            'last_failure_reason' => 'ff_only_merge_failed',
        ]);

        $this->assertSame('escalate', $result['action']);
        $this->assertTrue($result['escalate']);
        $this->assertSame('ff_only_merge_failed', $result['reason']);
    }

    public function testInvalidMaxAttemptsSkips(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 0,
            'base_is_ancestor' => true,
        ]);

        $this->assertSame('skip', $result['action']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('invalid_max_attempts', $result['reason']);
    }

    public function testNegativeMaxAttemptsSkips(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => -1,
            'base_is_ancestor' => false,
        ]);

        $this->assertSame('skip', $result['action']);
        $this->assertSame('invalid_max_attempts', $result['reason']);
    }

    public function testEscalateIsTrueOnlyWhenActionIsEscalate(): void
    {
        $mergeResult = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => true,
        ]);
        $this->assertFalse($mergeResult['escalate']);

        $rebaseResult = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => false,
        ]);
        $this->assertFalse($rebaseResult['escalate']);

        $escalateResult = $this->decider->decide([
            'attempts' => 1,
            'max_attempts' => 2,
            'base_is_ancestor' => true,
            'last_failure_reason' => 'ff_only_merge_failed',
        ]);
        $this->assertTrue($escalateResult['escalate']);
    }

    public function testResultContainsAllRequiredKeys(): void
    {
        $result = $this->decider->decide([
            'attempts' => 0,
            'max_attempts' => 2,
            'base_is_ancestor' => true,
        ]);

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('escalate', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
