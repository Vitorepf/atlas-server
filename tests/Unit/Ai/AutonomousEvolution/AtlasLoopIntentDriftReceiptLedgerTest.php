<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopIntentDriftReceiptLedger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopIntentDriftReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-drift-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(string $iso = '2026-06-24T12:00:00+00:00'): AtlasLoopIntentDriftReceiptLedger
    {
        $ledger = new AtlasLoopIntentDriftReceiptLedger($this->path);
        $ledger->setClock(fn () => $iso);

        return $ledger;
    }

    public function test_idempotent_append_returns_existing_id_and_does_not_add_a_line(): void
    {
        $ledger = $this->ledger();
        $fact = ['drift' => true, 'site' => 'X'];
        $recal = ['prior' => 100, 'proposed' => 110, 'reversal_token' => 'tok-1', 'applied' => false];

        $first = $ledger->append($fact, $recal);
        $second = $ledger->append($fact, $recal);

        $this->assertSame($first->receiptId, $second->receiptId);
        $this->assertCount(1, file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_class_exposes_no_public_update_delete_or_truncate_methods(): void
    {
        $rc = new ReflectionClass(AtlasLoopIntentDriftReceiptLedger::class);
        $names = array_map(static fn (ReflectionMethod $m): string => strtolower($m->getName()), $rc->getMethods(ReflectionMethod::IS_PUBLIC));

        $this->assertNotContains('update', $names);
        $this->assertNotContains('delete', $names);
        $this->assertNotContains('truncate', $names);
        $this->assertNotContains('remove', $names);
        $this->assertContains('append', $names);
        $this->assertContains('verify', $names);
    }

    public function test_verify_returns_tampered_receipt_id_when_a_line_is_corrupted(): void
    {
        $ledger = $this->ledger();
        $r1 = $ledger->append(['drift' => true, 'site' => 'A'], ['prior' => 1, 'proposed' => 2, 'reversal_token' => 'a', 'applied' => false]);
        $r2 = $ledger->append(['drift' => true, 'site' => 'B'], ['prior' => 3, 'proposed' => 4, 'reversal_token' => 'b', 'applied' => false]);

        $this->assertSame([], $ledger->verify(), 'untampered ledger must verify clean');

        // Corrupt one byte inside the second line's detector_fact (changes content_hash recomputation).
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[1] = str_replace('"site":"B"', '"site":"X"', $lines[1]);
        file_put_contents($this->path, implode("\n", $lines)."\n");

        $tampered = $ledger->verify();
        $this->assertCount(1, $tampered);
        $this->assertSame($r2->receiptId, $tampered[0]);
        $this->assertNotSame($r1->receiptId, $tampered[0]);
    }

    public function test_appending_two_different_payloads_produces_two_distinct_receipts(): void
    {
        $ledger = $this->ledger();
        $r1 = $ledger->append(['drift' => true, 'site' => 'A'], ['prior' => 1, 'proposed' => 2, 'reversal_token' => 'a', 'applied' => false]);
        $r2 = $ledger->append(['drift' => true, 'site' => 'B'], ['prior' => 3, 'proposed' => 4, 'reversal_token' => 'b', 'applied' => true]);

        $this->assertNotSame($r1->receiptId, $r2->receiptId);
        $this->assertCount(2, $ledger->all());
        $this->assertSame([], $ledger->verify());
    }
}
