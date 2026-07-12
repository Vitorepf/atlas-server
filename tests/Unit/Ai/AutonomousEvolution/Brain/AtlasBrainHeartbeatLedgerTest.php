<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHeartbeatLedger;
use Tests\TestCase;

final class AtlasBrainHeartbeatLedgerTest extends TestCase
{
    public function test_testing_environment_default_write_does_not_touch_live_heartbeat_root(): void
    {
        $liveRoot = storage_path('app/atlas/brain/heartbeat');
        $livePath = $liveRoot.'/autonomous.ndjson';
        $ledger = new AtlasBrainHeartbeatLedger;

        $root = (fn (): string => $this->root)->call($ledger);
        $this->assertNotSame($liveRoot, $root);

        $before = is_file($livePath) ? hash_file('sha256', $livePath) : null;
        $row = $ledger->record('autonomous', [
            'actor' => 'asi_05_phpunit_negative_case',
            'command' => 'heartbeat',
            'status' => 'ok',
        ]);
        $after = is_file($livePath) ? hash_file('sha256', $livePath) : null;

        $this->assertIsArray($row);
        $this->assertSame($before, $after);
        $this->assertSame([$row], $ledger->tail('autonomous', 1));
    }
}
