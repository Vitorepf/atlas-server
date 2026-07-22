<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptChain;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptVerificationReport;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the Unified Receipt verifier against the REAL chain (not a mock): a clean 10-node chain verifies; a
 * one-byte tamper in source_facts_json pinpoints the broken seq with PAYLOAD_HASH_MISMATCH; removing a middle
 * node trips PREV_LINK_BROKEN or SEQ_NON_MONOTONIC; an empty/missing file is treated as a valid empty chain;
 * verifyRange() confines the audit to the requested window.
 */
final class AtlasLoopUnifiedReceiptVerifierTest extends TestCase
{
    private string $chainFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chainFile = sys_get_temp_dir().'/atlas_unified_receipts_'.bin2hex(random_bytes(8)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->chainFile)) {
            @unlink($this->chainFile);
        }
        parent::tearDown();
    }

    private function chain(): AtlasLoopUnifiedReceiptChain
    {
        return new AtlasLoopUnifiedReceiptChain($this->chainFile);
    }

    private function verifier(): AtlasLoopUnifiedReceiptVerifier
    {
        return new AtlasLoopUnifiedReceiptVerifier($this->chainFile);
    }

    private function appendN(int $n): void
    {
        $chain = $this->chain();
        for ($i = 1; $i <= $n; $i++) {
            $chain->append([
                'source_ledger' => 'ledger_'.$i,
                'receipt_id' => 'receipt_'.$i,
                'facts' => ['n' => $i, 'tag' => 'fact_'.$i, 'nested' => ['k' => 'v_'.$i]],
            ]);
        }
    }

    public function test_clean_chain_of_ten_nodes_verifies_ok(): void
    {
        $this->appendN(10);

        $report = $this->verifier()->verify();

        $this->assertTrue($report->ok, 'a freshly-appended chain must verify');
        $this->assertSame(10, $report->totalNodes);
        $this->assertNull($report->firstBreakSeq);
        $this->assertNull($report->breakReason);
    }

    public function test_single_byte_tamper_in_source_facts_json_at_seq_5_yields_payload_hash_mismatch(): void
    {
        $this->appendN(10);

        $lines = file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $line5 = (array) json_decode((string) $lines[4], true);
        // Mutate ONE byte inside source_facts_json (the embedded JSON string) — payload_hash will diverge.
        $line5['source_facts_json'] = str_replace('"fact_5"', '"FACT_5"', (string) $line5['source_facts_json']);
        $lines[4] = (string) json_encode($line5, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->chainFile, implode("\n", $lines)."\n");

        $report = $this->verifier()->verify();

        $this->assertFalse($report->ok);
        $this->assertSame(5, $report->firstBreakSeq);
        $this->assertSame(AtlasLoopUnifiedReceiptVerificationReport::PAYLOAD_HASH_MISMATCH, $report->breakReason);
        $this->assertNotEmpty($report->brokenNodeId);
    }

    public function test_removing_a_middle_node_yields_prev_link_broken_or_seq_non_monotonic(): void
    {
        $this->appendN(10);

        // Drop the seq=5 line — line 6 still records the OLD seq=5's node_hash as its prev_hash, so the chain
        // breaks at line 6 (it now has seq=6 with prev_hash referencing the absent line). Either
        // PREV_LINK_BROKEN or SEQ_NON_MONOTONIC is a valid pétreo signal of the dropped node.
        $lines = file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        unset($lines[4]);
        $lines = array_values($lines);
        file_put_contents($this->chainFile, implode("\n", $lines)."\n");

        $report = $this->verifier()->verify();

        $this->assertFalse($report->ok);
        $this->assertContains(
            $report->breakReason,
            [
                AtlasLoopUnifiedReceiptVerificationReport::PREV_LINK_BROKEN,
                AtlasLoopUnifiedReceiptVerificationReport::SEQ_NON_MONOTONIC,
            ],
            'dropping a middle node must trip a link or sequence invariant'
        );
        $this->assertSame(6, $report->firstBreakSeq, 'first divergence is the line where seq jumped or prev did not match');
    }

    public function test_empty_or_missing_chain_file_returns_ok_true_zero_nodes(): void
    {
        // missing
        $missing = $this->verifier()->verify();
        $this->assertTrue($missing->ok);
        $this->assertSame(0, $missing->totalNodes);

        // empty
        file_put_contents($this->chainFile, '');
        $empty = $this->verifier()->verify();
        $this->assertTrue($empty->ok);
        $this->assertSame(0, $empty->totalNodes);
    }

    public function test_verify_range_confines_audit_to_the_window(): void
    {
        $this->appendN(10);
        $lines = file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Tamper at seq=9 — outside the [3,7] window.
        $line9 = (array) json_decode((string) $lines[8], true);
        $line9['source_facts_json'] = str_replace('"fact_9"', '"FACT_9"', (string) $line9['source_facts_json']);
        $lines[8] = (string) json_encode($line9, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->chainFile, implode("\n", $lines)."\n");

        $inWindow = $this->verifier()->verifyRange(3, 7);
        $this->assertTrue($inWindow->ok, 'seq=9 tamper is OUTSIDE the [3,7] window — partial audit must be clean');

        // And confirm a full verify catches it.
        $full = $this->verifier()->verify();
        $this->assertFalse($full->ok);
        $this->assertSame(9, $full->firstBreakSeq);
    }

    public function test_verifier_is_final_stateless_and_has_no_provider_or_network_dependency(): void
    {
        $reflection = new ReflectionClass(AtlasLoopUnifiedReceiptVerifier::class);
        $this->assertTrue($reflection->isFinal(), 'verifier must be final');

        // Stateless = the constructor takes only the chain file path.
        $params = $reflection->getConstructor()?->getParameters() ?? [];
        $this->assertCount(1, $params, 'constructor takes only the chain file path');
        $this->assertSame('chainFile', $params[0]->getName());

        // Provider-free: source proves no Hermes/Http/network call.
        $source = (string) file_get_contents($reflection->getFileName());
        foreach (['Hermes', 'Http::', '\\Http\\', 'Guzzle', 'curl_exec'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "verifier must not use $banned (provider/network call)");
        }
    }
}
