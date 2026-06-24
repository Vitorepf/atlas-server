<?php

declare(strict_types=1);

namespace Tests\Unit\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use PHPUnit\Framework\TestCase;

final class AtlasLoopKeepaliveSupervisorTerminateConfirmTest extends TestCase
{
    public function test_term_shutdown_is_confirmed_before_respawn_without_sigkill(): void
    {
        $cmd = new class([
            ['101'],
            ['101'],
            [],
        ]) extends AtlasLoopKeepaliveCommand
        {
            use KeepaliveSupervisorTerminateConfirmHarness;
        };
        $out = [];

        $confirmation = $cmd->terminateForTest('campaign-a', 'code_drift_recycle', $out);

        $this->assertNotNull($confirmation);
        $this->assertTrue($confirmation['confirmed']);
        $this->assertFalse($confirmation['escalated']);
        $this->assertSame([['TERM', '101']], $cmd->sentSignals);
        $this->assertSame(['campaign-a'], $cmd->respawned);
        $this->assertArrayNotHasKey('kill_failed', $out);
    }

    public function test_sigkill_is_used_when_term_deadline_expires_and_respawn_waits_for_clear_pids(): void
    {
        $cmd = new class([
            ['202'],
            ['202'],
            [],
        ]) extends AtlasLoopKeepaliveCommand
        {
            use KeepaliveSupervisorTerminateConfirmHarness;
        };
        $out = [];

        $confirmation = $cmd->terminateForTest('campaign-b', 'frozen_kill', $out, 0);

        $this->assertNotNull($confirmation);
        $this->assertTrue($confirmation['confirmed']);
        $this->assertTrue($confirmation['escalated']);
        $this->assertSame(['202'], $confirmation['escalated_pids']);
        $this->assertSame([['TERM', '202'], ['KILL', '202']], $cmd->sentSignals);
        $this->assertSame(['campaign-b'], $cmd->respawned);
        $this->assertArrayNotHasKey('kill_failed', $out);
    }

    public function test_persistent_pids_after_sigkill_record_kill_failed_and_skip_respawn(): void
    {
        $cmd = new class([
            ['303'],
            ['303'],
        ], true) extends AtlasLoopKeepaliveCommand
        {
            use KeepaliveSupervisorTerminateConfirmHarness;
        };
        $out = [];

        $confirmation = $cmd->terminateForTest('campaign-c', 'frozen_kill', $out, 0);

        $this->assertNull($confirmation);
        $this->assertSame([['TERM', '303'], ['KILL', '303']], $cmd->sentSignals);
        $this->assertSame([], $cmd->respawned);
        $this->assertSame([
            [
                'campaign_id' => 'campaign-c',
                'lane' => 'frozen_kill',
                'pids' => ['303'],
                'reason' => 'supervisor_still_alive_after_sigkill',
                'still_alive' => true,
                'escalated' => true,
            ],
        ], $out['kill_failed']);
    }
}

trait KeepaliveSupervisorTerminateConfirmHarness
{
    /** @var list<list<string>> */
    private array $pidSnapshots;

    /** @var list<string> */
    private array $lastPids = [];

    /** @var list<array{0:string,1:string}> */
    public array $sentSignals = [];

    /** @var list<string> */
    public array $respawned = [];

    /**
     * @param  list<list<string>>  $pidSnapshots
     */
    public function __construct(array $pidSnapshots, private bool $repeatLastSnapshot = false)
    {
        parent::__construct();
        $this->pidSnapshots = $pidSnapshots;
    }

    /**
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>|null
     */
    public function terminateForTest(string $campaignId, string $lane, array &$out, int $deadlineSeconds = 10): ?array
    {
        return $this->terminateSupervisorBeforeRespawn($campaignId, $lane, $out, $deadlineSeconds);
    }

    protected function supervisorPids(string $campaignId): array
    {
        if ($this->pidSnapshots !== []) {
            $this->lastPids = array_shift($this->pidSnapshots);

            return $this->lastPids;
        }

        return $this->repeatLastSnapshot ? $this->lastPids : [];
    }

    protected function sendSupervisorSignal(string $pid, string $signal): void
    {
        $this->sentSignals[] = [$signal, $pid];
    }

    protected function sleepBeforeSupervisorConfirmPoll(): void
    {
        // Unit tests model each poll as the next fixture snapshot; no wall-clock wait.
    }

    protected function fleetCapBlocksRespawn(string $campaignId, string $lane, array &$out): bool
    {
        return false;
    }

    protected function respawn(string $campaignId): void
    {
        $this->respawned[] = $campaignId;
    }
}
