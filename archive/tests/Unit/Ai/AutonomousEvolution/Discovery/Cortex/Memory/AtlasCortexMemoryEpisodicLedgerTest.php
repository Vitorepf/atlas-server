<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the Cortex episodic ledger: (1) duplicate cycle_id is fail-closed AND the file carries exactly
 * one line for that cycle_id (append-only invariant); (2) iterate(limit=3, since=T0) yields the 3 oldest
 * with captured_at>=T0 in (captured_at, cycle_id) lex order; (3) two independent ledger instances over
 * the same file produce byte-identical digest_of(cycle_id) (deterministic FACT replay).
 */
final class AtlasCortexMemoryEpisodicLedgerTest extends TestCase
{
    private string $ledgerPath;

    private string $masterEnvPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_cortex_episodes_'.bin2hex(random_bytes(6)).'.ndjson';
        // Keep the master switch ON for all tests except those that explicitly disable it.
        $this->masterEnvPath = (string) tempnam(sys_get_temp_dir(), 'atlas-master-on-');
        file_put_contents($this->masterEnvPath, "ATLAS_LOOP_MASTER_ENABLED=1\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->masterEnvPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->masterEnvPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function ledger(): AtlasCortexMemoryEpisodicLedger
    {
        return new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
    }

    private function episode(string $cycleId, int $capturedAt = 1000): AtlasCortexMemoryEpisodeRecord
    {
        return new AtlasCortexMemoryEpisodeRecord(
            cycleId: $cycleId,
            capturedAt: $capturedAt,
            scopeRoot: '/atlas/scope',
            snapshotDigest: 'sha256:'.str_repeat('a', 8),
            inventoryItems: [['item_id' => 'i1', 'kind' => 'class', 'fingerprint' => 'fp1']],
            blindSpots: [['gap_id' => 'g1', 'kind' => 'untyped']],
            intentInterpretations: [['intent_id' => 'in1', 'canonical_form' => 'understand_scope']],
            sourceFactsOnly: true,
        );
    }

    public function test_duplicate_cycle_id_fail_closed_and_file_carries_exactly_one_line_for_that_cycle(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->episode('cycle-dup', 1000));

        try {
            $ledger->append($this->episode('cycle-dup', 2000));
            $this->fail('expected duplicate cycle_id exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('duplicate cycle_id', $e->getMessage());
        }

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $matching = 0;
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded) && ($decoded['cycle_id'] ?? null) === 'cycle-dup') {
                $matching++;
            }
        }
        $this->assertSame(1, $matching, 'append-only: duplicate must not write a second line');
    }

    public function test_iterate_limit_3_since_T0_yields_three_oldest_in_captured_at_cycle_lex_order(): void
    {
        $ledger = $this->ledger();
        // Mix of captured_at, intentionally out of order:
        $ledger->append($this->episode('z', 1000));
        $ledger->append($this->episode('a', 500));      // pre-T0 — filtered out
        $ledger->append($this->episode('b', 2000));
        $ledger->append($this->episode('c', 1500));
        $ledger->append($this->episode('d', 1000));     // same ts as 'z' → 'd' sorts BEFORE 'z' (lex)
        $ledger->append($this->episode('e', 3000));

        $T0 = 1000;
        $rows = iterator_to_array($ledger->iterate(limit: 3, sinceUnix: $T0), false);

        $cycleIds = array_map(static fn (array $r): string => (string) $r['cycle_id'], $rows);
        $this->assertSame(['d', 'z', 'c'], $cycleIds, 'oldest-3 ≥ T0 in (captured_at, cycle_id) lex order');
    }

    public function test_two_independent_ledger_instances_over_the_same_file_produce_byte_identical_digest(): void
    {
        $ledgerA = $this->ledger();
        $ledgerA->append($this->episode('alpha', 1000));
        $ledgerA->append($this->episode('beta', 2000));

        $ledgerB = $this->ledger(); // independent instance, same file
        $digestA = $ledgerA->digest_of('alpha');
        $digestB = $ledgerB->digest_of('alpha');

        $this->assertNotNull($digestA);
        $this->assertSame($digestA, $digestB, 'identical file ⇒ identical digest across instances');
        $this->assertSame(64, strlen((string) $digestA), 'SHA-256 hex digest');
    }

    public function test_count_reflects_lines_in_file(): void
    {
        $ledger = $this->ledger();
        $this->assertSame(0, $ledger->count());
        $ledger->append($this->episode('a', 100));
        $ledger->append($this->episode('b', 200));
        $this->assertSame(2, $ledger->count());
    }

    public function test_digest_of_unknown_cycle_returns_null(): void
    {
        $this->assertNull($this->ledger()->digest_of('nonexistent'));
    }

    public function test_master_switch_off_append_is_byte_identical_noop(): void
    {
        // Point the master switch at a temp .env without the ENABLED key → enabled()=false
        $tmpEnv = (string) tempnam(sys_get_temp_dir(), 'atlas-master-test-');
        file_put_contents($tmpEnv, "# no master key\n");
        AtlasLoopMasterSwitch::$envPathOverride = $tmpEnv;

        try {
            $ledger = $this->ledger();
            $ep = $this->episode('noop-cycle', 5000);
            $returned = $ledger->append($ep);

            $this->assertSame($ep, $returned, 'append() must return the episode even when no-op');
            $this->assertFileDoesNotExist($this->ledgerPath, 'master-off append must write NO file');
        } finally {
            AtlasLoopMasterSwitch::$envPathOverride = null;
            @unlink($tmpEnv);
        }
    }
}
