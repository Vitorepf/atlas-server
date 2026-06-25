<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryConvergenceObserver;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Cortex convergence observer: I1 = 'A'@c1 then 'B'@c2..5 ⇒ stable_runs=4 (canonical='B',
 * first_stable_cycle_id='c2'). I2 = 'X' in all 5 cycles ⇒ stable_runs=5. minStableRuns=4 ⇒ both qualify.
 * A late flip resets the run to 1 on the next call (no state leak between calls).
 */
final class AtlasCortexMemoryConvergenceObserverTest extends TestCase
{
    private string $ledgerPath;

    private AtlasCortexMemoryEpisodicLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_cortex_conv_'.bin2hex(random_bytes(6)).'.ndjson';
        $this->ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /**
     * @param  list<array{intent_id:string, canonical_form:string}>  $intents
     */
    private function episode(string $cycleId, int $capturedAt, array $intents): AtlasCortexMemoryEpisodeRecord
    {
        return new AtlasCortexMemoryEpisodeRecord(
            cycleId: $cycleId,
            capturedAt: $capturedAt,
            scopeRoot: '/atlas/scope',
            snapshotDigest: 'sha256:'.str_repeat('a', 8),
            inventoryItems: [],
            blindSpots: [],
            intentInterpretations: $intents,
            sourceFactsOnly: true,
        );
    }

    public function test_i1_flips_at_c2_then_stable_4_and_i2_stable_5_both_qualify_at_min4(): void
    {
        $this->ledger->append($this->episode('c1', 1, [['intent_id' => 'I1', 'canonical_form' => 'A'], ['intent_id' => 'I2', 'canonical_form' => 'X']]));
        $this->ledger->append($this->episode('c2', 2, [['intent_id' => 'I1', 'canonical_form' => 'B'], ['intent_id' => 'I2', 'canonical_form' => 'X']]));
        $this->ledger->append($this->episode('c3', 3, [['intent_id' => 'I1', 'canonical_form' => 'B'], ['intent_id' => 'I2', 'canonical_form' => 'X']]));
        $this->ledger->append($this->episode('c4', 4, [['intent_id' => 'I1', 'canonical_form' => 'B'], ['intent_id' => 'I2', 'canonical_form' => 'X']]));
        $this->ledger->append($this->episode('c5', 5, [['intent_id' => 'I1', 'canonical_form' => 'B'], ['intent_id' => 'I2', 'canonical_form' => 'X']]));

        $observer = new AtlasCortexMemoryConvergenceObserver($this->ledger);
        $rows = $observer->stabilizedIntents(minStableRuns: 4);

        $this->assertCount(2, $rows);
        // I2 (5) first, then I1 (4).
        $this->assertSame('I2', $rows[0]['intent_id']);
        $this->assertSame(5, $rows[0]['stable_runs']);
        $this->assertSame('X', $rows[0]['canonical_form']);

        $this->assertSame('I1', $rows[1]['intent_id']);
        $this->assertSame(4, $rows[1]['stable_runs']);
        $this->assertSame('B', $rows[1]['canonical_form']);
        $this->assertSame('c2', $rows[1]['first_stable_cycle_id']);
        $this->assertSame('c5', $rows[1]['last_seen_cycle_id']);
    }

    public function test_recent_flip_is_excluded_at_min_threshold(): void
    {
        // I3 flipped at c4 ⇒ stable_runs=2 by c5, fails minStableRuns=4
        $this->ledger->append($this->episode('c1', 1, [['intent_id' => 'I3', 'canonical_form' => 'A']]));
        $this->ledger->append($this->episode('c2', 2, [['intent_id' => 'I3', 'canonical_form' => 'A']]));
        $this->ledger->append($this->episode('c3', 3, [['intent_id' => 'I3', 'canonical_form' => 'A']]));
        $this->ledger->append($this->episode('c4', 4, [['intent_id' => 'I3', 'canonical_form' => 'Z']]));
        $this->ledger->append($this->episode('c5', 5, [['intent_id' => 'I3', 'canonical_form' => 'Z']]));

        $observer = new AtlasCortexMemoryConvergenceObserver($this->ledger);
        $this->assertSame([], $observer->stabilizedIntents(minStableRuns: 4), 'recent-flipped intent excluded');
    }

    public function test_two_calls_same_ledger_byte_identical_json_and_appended_flip_resets_run(): void
    {
        $this->ledger->append($this->episode('c1', 1, [['intent_id' => 'I', 'canonical_form' => 'A']]));
        $this->ledger->append($this->episode('c2', 2, [['intent_id' => 'I', 'canonical_form' => 'A']]));
        $this->ledger->append($this->episode('c3', 3, [['intent_id' => 'I', 'canonical_form' => 'A']]));

        $observer = new AtlasCortexMemoryConvergenceObserver($this->ledger);
        $a = json_encode($observer->stabilizedIntents(minStableRuns: 1), JSON_UNESCAPED_SLASHES);
        $b = json_encode($observer->stabilizedIntents(minStableRuns: 1), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b, 'pure: identical ledger ⇒ identical output');

        // Append a flip; follow-up call should reset stable_runs to 1.
        $this->ledger->append($this->episode('c4', 4, [['intent_id' => 'I', 'canonical_form' => 'B']]));
        $after = $observer->stabilizedIntents(minStableRuns: 1);
        $this->assertCount(1, $after);
        $this->assertSame(1, $after[0]['stable_runs'], 'flip resets the run on the next call');
        $this->assertSame('B', $after[0]['canonical_form']);
        $this->assertSame('c4', $after[0]['first_stable_cycle_id']);
    }
}
