<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Console\Commands\AtlasLoopCortexMemoryCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryBlindSpotTracker;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryConvergenceObserver;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRecurrencyDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRetrievalService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopCortexMemoryCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-cortex-mem-cli-'.bin2hex(random_bytes(6)).'.ndjson';

        $ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
        $episodes = [
            ['c-001', 1000, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [['gap_id' => 'g1', 'kind' => 'orphan']], [['intent_id' => 'I1', 'canonical_form' => 'B']]],
            ['c-002', 1100, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [['gap_id' => 'g1', 'kind' => 'orphan']], [['intent_id' => 'I1', 'canonical_form' => 'B']]],
            ['c-003', 1200, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [['gap_id' => 'g1', 'kind' => 'orphan']], [['intent_id' => 'I1', 'canonical_form' => 'B']]],
            ['c-004', 1300, [['item_id' => 'Y', 'kind' => 'class', 'fingerprint' => 'fp9']], [], [['intent_id' => 'I1', 'canonical_form' => 'B']]],
        ];
        foreach ($episodes as [$cid, $at, $inv, $bs, $intents]) {
            $ledger->append(new AtlasCortexMemoryEpisodeRecord(
                cycleId: $cid, capturedAt: $at, scopeRoot: 'atlas-server',
                snapshotDigest: 'd-'.$cid,
                inventoryItems: $inv, blindSpots: $bs, intentInterpretations: $intents,
            ));
        }

        $this->app->instance(AtlasCortexMemoryEpisodicLedger::class, $ledger);
        $this->app->instance(AtlasCortexMemoryRecurrencyDetector::class, new AtlasCortexMemoryRecurrencyDetector($ledger));
        $this->app->instance(AtlasCortexMemoryBlindSpotTracker::class, new AtlasCortexMemoryBlindSpotTracker($ledger));
        $this->app->instance(AtlasCortexMemoryConvergenceObserver::class, new AtlasCortexMemoryConvergenceObserver($ledger));
        $this->app->instance(AtlasCortexMemoryRetrievalService::class, new AtlasCortexMemoryRetrievalService($ledger));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:cortex:memory', $args);

        return [$exit, $kernel->output()];
    }

    public function test_recurrent_json_matches_service_output(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'recurrent', '--min-runs' => 3, '--json' => true]);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $svc = $this->app->make(AtlasCortexMemoryRecurrencyDetector::class);
        $this->assertSame($svc->recurrentItems(3), $decoded['recurrent_items']);
    }

    public function test_recall_with_inventory_fact_matches_service_output(): void
    {
        [$exit, $out] = $this->runCmd([
            'action' => 'recall', '--item-id' => 'X', '--fingerprint' => 'fp7', '--limit' => 2, '--json' => true,
        ]);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $svc = $this->app->make(AtlasCortexMemoryRetrievalService::class);
        $expected = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'X', 'fingerprint' => 'fp7'], 2);
        // assertEquals: key order may differ because the CLI canonicalizes (sorted keys) for
        // deterministic capture, while the service returns rows in their natural order.
        $this->assertEquals($expected, $decoded['recall']);
    }

    public function test_recall_without_fact_flags_exits_two(): void
    {
        [$exit] = $this->runCmd(['action' => 'recall']);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_MISSING_RECALL_FACT, $exit);
    }

    public function test_blindspots_and_convergence_end_to_end(): void
    {
        [$exitBs, $outBs] = $this->runCmd(['action' => 'blindspots', '--min-runs' => 3, '--json' => true]);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_OK, $exitBs);
        $this->assertArrayHasKey('persistent_blind_spots', json_decode(trim($outBs), true));

        [$exitConv, $outConv] = $this->runCmd(['action' => 'convergence', '--min-runs' => 3, '--json' => true]);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_OK, $exitConv);
        $this->assertArrayHasKey('stabilized_intents', json_decode(trim($outConv), true));
    }

    public function test_invalid_action_exits_one(): void
    {
        [$exit] = $this->runCmd(['action' => 'BOGUS']);
        $this->assertSame(AtlasLoopCortexMemoryCommand::EXIT_INVALID_ACTION, $exit);
    }
}
