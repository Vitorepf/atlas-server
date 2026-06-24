<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleCoordinationProtocol;
use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleSubScopePartitioner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopMultiCycleCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-loop-multicycle-command-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);

        $this->app->instance(AtlasLoopMultiCycleSubScopePartitioner::class, new AtlasLoopMultiCycleSubScopePartitioner);
        $this->app->instance(AtlasLoopMultiCycleCoordinationProtocol::class, new AtlasLoopMultiCycleCoordinationProtocol($this->tmpDir.'/journal.jsonl'));
        $this->app->instance(AtlasLoopMultiCycleReceiptLedger::class, new AtlasLoopMultiCycleReceiptLedger($this->tmpDir.'/receipts.ndjson'));
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_partition_returns_two_subscopes_with_the_full_union(): void
    {
        $exit = Artisan::call('atlas:loop:multi-cycle', [
            'action' => 'partition',
            '--scope' => ['a.php', 'b.php', 'c.php', 'd.php'],
            '--cycle-count' => 2,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $payload);

        $union = [];
        foreach ($payload as $partition) {
            foreach ($partition['files'] as $path) {
                $union[$path] = true;
            }
        }
        $files = array_keys($union);
        sort($files, SORT_STRING);

        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php'], $files);
    }

    public function test_claim_denial_names_current_holder_and_history_lists_both_events(): void
    {
        $hash = 'abc';

        $firstExit = Artisan::call('atlas:loop:multi-cycle', [
            'action' => 'claim',
            '--cycle-id' => 'cyc-A',
            '--sub-scope-hash' => $hash,
            '--json' => true,
        ]);
        $firstPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $secondExit = Artisan::call('atlas:loop:multi-cycle', [
            'action' => 'claim',
            '--cycle-id' => 'cyc-B',
            '--sub-scope-hash' => $hash,
            '--json' => true,
        ]);
        $secondPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $historyExit = Artisan::call('atlas:loop:multi-cycle', [
            'action' => 'history',
            '--json' => true,
        ]);
        $history = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstExit);
        $this->assertSame('cyc-A', $firstPayload['cycle_id']);
        $this->assertSame(1, $secondExit);
        $this->assertSame('cyc-A', $secondPayload['holding_cycle_id']);
        $this->assertSame(0, $historyExit);
        $this->assertSame(['claim_granted', 'claim_denied'], array_slice(array_column($history, 'event_type'), -2));
    }
}
