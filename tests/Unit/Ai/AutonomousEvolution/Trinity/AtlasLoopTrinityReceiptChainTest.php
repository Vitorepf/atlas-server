<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityReceiptChain;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\TrinityCycleResult;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\TrinityReceiptChainDuplicateCycleException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity receipt chain: an append-only, tamper-evident hash chain over Trinity cycles. A chain of
 * honestly appended entries verifies; mutating one byte of an intermediate entry breaks verifyChain(); a
 * duplicate cycleId is refused; and ordering + latest() survive reopening the file (process-restart proxy).
 */
final class AtlasLoopTrinityReceiptChainTest extends TestCase
{
    private string $chainFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chainFile = sys_get_temp_dir().'/atlas_trinity_receipt_'.bin2hex(random_bytes(8)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->chainFile)) {
            @unlink($this->chainFile);
        }
        parent::tearDown();
    }

    private function chain(): AtlasLoopTrinityReceiptChain
    {
        return new AtlasLoopTrinityReceiptChain($this->chainFile);
    }

    private function cycle(string $cycleId): TrinityCycleResult
    {
        return new TrinityCycleResult(
            $cycleId,
            'loop-receipt-'.$cycleId,
            'cortex-receipt-'.$cycleId,
            'maestro-receipt-'.$cycleId,
            [
                ['factId' => 'L-'.$cycleId, 'source' => 'loop', 'cycleId' => $cycleId, 'payload' => ['n' => $cycleId]],
                ['factId' => 'C-'.$cycleId, 'source' => 'cortex', 'cycleId' => $cycleId, 'payload' => ['n' => $cycleId]],
            ],
        );
    }

    /**
     * A duck-typed stand-in for TrinityFeedbackAuditResult — append() only needs toArray():array. Avoids
     * coupling the test to that class (which is co-located in the auditor file and not PSR-4 autoloadable by name).
     */
    private function audit(string $cycleId): object
    {
        return new class($cycleId)
        {
            public function __construct(private readonly string $cycleId)
            {
            }

            /** @return array<string,mixed> */
            public function toArray(): array
            {
                return ['cycle_id' => $this->cycleId, 'is_static' => false, 'violations' => []];
            }
        };
    }

    private function appendThree(): AtlasLoopTrinityReceiptChain
    {
        $chain = $this->chain();
        foreach (['c1', 'c2', 'c3'] as $cycleId) {
            $chain->append($this->cycle($cycleId), $this->audit($cycleId));
        }

        return $chain;
    }

    public function test_verify_chain_true_for_three_honestly_appended_entries(): void
    {
        $chain = $this->appendThree();

        $this->assertTrue($chain->verifyChain());
        // First entry is genesis-linked; each subsequent entry links to the prior entry's hash.
        $this->assertSame(AtlasLoopTrinityReceiptChain::GENESIS_PREV_HASH, $this->lines()[0]['prevCycleHash']);
        $this->assertNotSame(AtlasLoopTrinityReceiptChain::GENESIS_PREV_HASH, $this->lines()[1]['prevCycleHash']);
    }

    public function test_verify_chain_false_when_intermediate_entry_is_tampered(): void
    {
        $this->appendThree();

        // Mutate one field of the MIDDLE entry (index 1) — its hash changes, so entry #3's prevCycleHash link breaks.
        $lines = $this->lines();
        $lines[1]['loopReceiptId'] = $lines[1]['loopReceiptId'].'X';
        $this->writeLines($lines);

        $this->assertFalse($this->chain()->verifyChain(), 'a tampered intermediate entry must break the chain');
    }

    public function test_verify_chain_false_when_prev_hash_is_tampered(): void
    {
        $this->appendThree();

        // Directly corrupt the stored link of the middle entry.
        $lines = $this->lines();
        $lines[1]['prevCycleHash'] = str_repeat('0', 64);
        $this->writeLines($lines);

        $this->assertFalse($this->chain()->verifyChain());
    }

    public function test_append_refuses_duplicate_cycle_id(): void
    {
        $chain = $this->chain();
        $chain->append($this->cycle('c1'), $this->audit('c1'));

        $this->expectException(TrinityReceiptChainDuplicateCycleException::class);
        $chain->append($this->cycle('c1'), $this->audit('c1'));
    }

    public function test_latest_and_ordering_are_stable_across_reopen(): void
    {
        $this->appendThree();

        // Reopen the same file with a fresh instance — process-restart proxy.
        $reopened = $this->chain();
        $latest = $reopened->latest();

        $this->assertNotNull($latest);
        $this->assertSame('c3', $latest->cycleId, 'latest() returns the chronologically last entry');
        $this->assertTrue($reopened->verifyChain(), 'chain still verifies after reopening');
        $this->assertSame(['c1', 'c2', 'c3'], array_column($this->lines(), 'cycleId'), 'insertion order is file order');
    }

    public function test_latest_is_null_for_empty_chain(): void
    {
        $this->assertNull($this->chain()->latest());
        $this->assertTrue($this->chain()->verifyChain(), 'an empty chain verifies vacuously');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function lines(): array
    {
        $out = [];
        foreach (file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = (array) json_decode((string) $line, true);
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     */
    private function writeLines(array $lines): void
    {
        $encoded = array_map(static fn (array $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $lines);
        file_put_contents($this->chainFile, implode("\n", $encoded)."\n");
    }
}
