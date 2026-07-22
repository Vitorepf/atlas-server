<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AttemptLifecycleLedger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Esp01AttemptLifecycleLedgerTest extends TestCase
{
    #[Test]
    public function started_attempt_requires_resolvable_task_and_dedupes_by_attempt_id(): void
    {
        $ledger = new AttemptLifecycleLedger;

        $this->assertTrue($ledger->start('attempt-1', 'task-1')['accepted']);
        $this->assertFalse($ledger->start('attempt-1', 'task-1')['accepted']);
        $this->assertFalse($ledger->start('attempt-2', '')['accepted']);
    }

    #[Test]
    public function outcome_without_attempt_is_rejected(): void
    {
        $ledger = new AttemptLifecycleLedger;

        $out = $ledger->terminal('missing', 'completed');

        $this->assertFalse($out['accepted']);
        $this->assertSame('attempt_missing', $out['reason']);
    }

    #[Test]
    public function census_marks_stale_started_attempts_abandoned(): void
    {
        $ledger = new AttemptLifecycleLedger;
        $ledger->start('attempt-1', 'task-1', startedAt: 100);

        $out = $ledger->census(now: 200, ttlSeconds: 50);

        $this->assertSame('abandoned', $out['attempts']['attempt-1']['state']);
        $this->assertSame(0, $out['unterminated_count']);
    }
}
