<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasBrainDoneSetServedDoesNotBurnTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-done-set-served-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_served_status_does_not_burn_target_for_seed_dedup(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('autonomous', $this->root);
        $target = 'app/Services/Ai/ExampleServedTarget.php';

        $ledger->record([
            'snapshot_id' => 'snap-1',
            'status' => 'served',
            'produced' => true,
            'action' => 'originate',
            'target_path' => $target,
            'task_packet_id' => 'pkt-served-1',
            'refusal' => false,
        ]);

        $this->assertFalse(
            $ledger->isDone($target),
            'served must not burn the target; seed must still be able to enqueue',
        );
    }

    public function test_seeded_status_still_burns_target(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('autonomous', $this->root);
        $target = 'app/Services/Ai/ExampleSeededTarget.php';

        $ledger->record([
            'snapshot_id' => 'snap-2',
            'status' => 'seeded',
            'produced' => true,
            'action' => 'seed',
            'target_path' => $target,
            'task_packet_id' => 'pkt-seeded-1',
            'refusal' => false,
        ]);

        $this->assertTrue($ledger->isDone($target));
    }
}
