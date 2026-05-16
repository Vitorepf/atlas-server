<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use App\Domain\Captures\CaptureAuditLog;
use App\Domain\Captures\CaptureStateMachine;
use PHPUnit\Framework\TestCase;

final class CaptureStateMachineTest extends TestCase
{
    private const STATES = ['draft', 'submitted', 'accepted', 'archived', 'rejected'];

    private const ALLOWED = [
        ['draft', 'submitted'],
        ['draft', 'rejected'],
        ['submitted', 'accepted'],
        ['submitted', 'rejected'],
        ['accepted', 'archived'],
    ];

    public function test_allowlist_grid_accepts_only_declared_pairs(): void
    {
        $log = new CaptureAuditLog;
        $machine = new CaptureStateMachine($log);
        foreach (self::STATES as $from) {
            foreach (self::STATES as $to) {
                $result = $machine->transition($from, $to, 'operator');
                $isAllowed = in_array([$from, $to], self::ALLOWED, true);
                if ($isAllowed) {
                    $this->assertSame('ok', $result['status'], "{$from}->{$to} should be allowed");
                } else {
                    $this->assertSame('blocked', $result['status'], "{$from}->{$to} should be blocked");
                    $this->assertSame('transition_not_in_allowlist', $result['reason'] ?? '');
                }
            }
        }
    }

    public function test_audit_log_records_every_attempt(): void
    {
        $log = new CaptureAuditLog;
        $machine = new CaptureStateMachine($log);
        $machine->transition('draft', 'submitted', 'alice');
        $machine->transition('draft', 'accepted', 'mallory');

        $entries = $log->entries();
        $this->assertCount(2, $entries);
        $this->assertTrue($entries[0]['accepted']);
        $this->assertFalse($entries[1]['accepted']);
    }

    public function test_replay_is_deterministic(): void
    {
        $logA = new CaptureAuditLog;
        $machineA = new CaptureStateMachine($logA);
        $machineA->transition('draft', 'submitted', 'alice');
        $machineA->transition('submitted', 'accepted', 'bob');
        $machineA->transition('accepted', 'archived', 'bob');

        $logB = new CaptureAuditLog;
        $machineB = new CaptureStateMachine($logB);
        foreach ($logA->entries() as $entry) {
            $machineB->transition($entry['from'], $entry['to'], $entry['by']);
        }

        $this->assertSame($logA->fingerprint(), $logB->fingerprint());
    }
}
