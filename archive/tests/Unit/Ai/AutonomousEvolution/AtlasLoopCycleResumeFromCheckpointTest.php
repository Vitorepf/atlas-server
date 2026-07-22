<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCycleResumeFromCheckpoint;
use Tests\TestCase;

final class AtlasLoopCycleResumeFromCheckpointTest extends TestCase
{
    private string $sentinelPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sentinelPath = sys_get_temp_dir().'/atlas-cycle-resume-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->sentinelPath);
        parent::tearDown();
    }

    private function newFlag(): AtlasLoopCyclePauseFlag
    {
        return new AtlasLoopCyclePauseFlag($this->sentinelPath);
    }

    private function checkpointFor(AtlasLoopCycleResumeFromCheckpoint $svc, string $cycleId, string $phase, array $facts): array
    {
        return [
            'cycle_id' => $cycleId,
            'phase' => $phase,
            'facts' => $facts,
            'checkpoint_hash' => $svc->hashFacts($facts),
        ];
    }

    public function test_resume_succeeds_when_sentinel_and_checkpoint_align(): void
    {
        $flag = $this->newFlag();
        $flag->raise('cyc-7', 'verify', 'operator-paused', '2026-06-25T00:00:00Z');
        $svc = new AtlasLoopCycleResumeFromCheckpoint($flag);

        $checkpoint = $this->checkpointFor($svc, 'cyc-7', 'verify', ['step' => 12, 'attempt' => 1]);
        $result = $svc->resume($checkpoint);

        self::assertSame('resumed', $result['outcome']);
        self::assertSame('verify', $result['resumed_phase']);
        self::assertSame('cyc-7', $result['cycle_id']);
        self::assertSame($checkpoint['checkpoint_hash'], $result['checkpoint_hash']);
        self::assertFalse($flag->isRaised(), 'successful resume lowers the pause sentinel');
    }

    public function test_resume_refuses_missing_pause_sentinel_without_mutation(): void
    {
        $flag = $this->newFlag();
        $svc = new AtlasLoopCycleResumeFromCheckpoint($flag);

        $checkpoint = $this->checkpointFor($svc, 'cyc-1', 'plan', ['x' => 1]);
        $result = $svc->resume($checkpoint);

        self::assertSame('refused', $result['outcome']);
        self::assertSame('missing_pause_sentinel', $result['reason']);
        self::assertFalse($flag->isRaised());
    }

    public function test_resume_refuses_missing_checkpoint_fields(): void
    {
        $flag = $this->newFlag();
        $flag->raise('cyc-3', 'plan', 'r', '2026-06-25T00:00:01Z');
        $svc = new AtlasLoopCycleResumeFromCheckpoint($flag);

        $result = $svc->resume(['cycle_id' => 'cyc-3']);

        self::assertSame('refused', $result['outcome']);
        self::assertSame('missing_checkpoint_fields', $result['reason']);
        self::assertTrue($flag->isRaised(), 'refusal must not lower the sentinel');
    }

    public function test_resume_refuses_cycle_id_mismatch(): void
    {
        $flag = $this->newFlag();
        $flag->raise('cyc-A', 'plan', 'r', '2026-06-25T00:00:02Z');
        $svc = new AtlasLoopCycleResumeFromCheckpoint($flag);

        $checkpoint = $this->checkpointFor($svc, 'cyc-B', 'plan', ['x' => 1]);
        $result = $svc->resume($checkpoint);

        self::assertSame('refused', $result['outcome']);
        self::assertSame('cycle_id_mismatch', $result['reason']);
        self::assertTrue($flag->isRaised());
    }

    public function test_resume_refuses_integrity_drift(): void
    {
        $flag = $this->newFlag();
        $flag->raise('cyc-D', 'verify', 'r', '2026-06-25T00:00:03Z');
        $svc = new AtlasLoopCycleResumeFromCheckpoint($flag);

        $checkpoint = [
            'cycle_id' => 'cyc-D',
            'phase' => 'verify',
            'facts' => ['step' => 1],
            'checkpoint_hash' => 'deadbeef'.str_repeat('0', 56),
        ];
        $result = $svc->resume($checkpoint);

        self::assertSame('refused', $result['outcome']);
        self::assertSame('integrity_drift', $result['reason']);
        self::assertTrue($flag->isRaised());
    }
}
