<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityReceiptImmutabilityException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the Trinity contract receipt ledger: deterministic replay (two identical appends differ only in
 * seq+ts; replay reproduces the in-memory state); refuses mutation/deletion via TrinityReceiptImmutabilityException;
 * no public update/delete/truncate method exists (reflection); breachesThisWeekFor consults the ledger.
 */
final class AtlasLoopTrinityContractReceiptLedgerTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_trinity_receipts_'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            foreach (glob($this->storageRoot.'/*.ndjson') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->storageRoot);
        }
        parent::tearDown();
    }

    private function ledger(int $atTs = 1700000000): AtlasLoopTrinityContractReceiptLedger
    {
        return new AtlasLoopTrinityContractReceiptLedger($this->storageRoot, static fn (): int => $atTs);
    }

    public function test_deterministic_replay_two_appends_yield_two_receipts_with_strictly_increasing_seq(): void
    {
        $ledger = $this->ledger(1700000000);
        $partial = ['kind' => 'audit', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp1', 'outcome' => 'CLEAN', 'source_command_sha' => 'sha1'];

        $a = $ledger->append($partial);
        $b = $ledger->append($partial);

        $this->assertSame(1, $a['seq']);
        $this->assertSame(2, $b['seq']);
        // Apart from seq + ts they are identical (ts in this test is fixed by the clock seam, so even ts matches).
        unset($a['seq'], $b['seq']);
        $this->assertSame($a, $b, 'apart from seq, the two receipts are byte-identical');

        $replay = $this->ledger()->replay();
        $this->assertCount(2, $replay);
        $this->assertSame([1, 2], [(int) $replay[0]['seq'], (int) $replay[1]['seq']]);
    }

    public function test_refuse_overwrite_throws_immutability_exception_for_a_receipt_file(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);
        $files = glob($this->storageRoot.'/*.ndjson') ?: [];
        $this->assertNotEmpty($files);

        $this->expectException(TrinityReceiptImmutabilityException::class);
        $ledger->refuseOverwrite($files[0]);
    }

    public function test_no_public_update_delete_or_truncate_method_exists(): void
    {
        $reflection = new ReflectionClass(AtlasLoopTrinityContractReceiptLedger::class);
        $publicNames = array_map(static fn (ReflectionMethod $m): string => strtolower($m->getName()), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));

        foreach (['update', 'delete', 'truncate', 'remove', 'edit', 'patch'] as $banned) {
            $this->assertNotContains($banned, $publicNames, "append-only: must not expose public {$banned}()");
        }
        $this->assertContains('append', $publicNames);
        $this->assertContains('replay', $publicNames);
    }

    public function test_breaches_this_week_for_returns_count_consulting_the_ledger(): void
    {
        $monday = (int) strtotime('2024-01-08 12:00:00 UTC'); // a Monday
        $ledger = $this->ledger($monday);

        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp', 'outcome' => 'BREACH']);
        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'side' => 'consume', 'counterpart' => 'maestro', 'contract_fingerprint' => 'fp', 'outcome' => 'BREACH']);
        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);
        // A breach for a DIFFERENT primitive — not counted.
        $ledger->append(['kind' => 'audit', 'primitive' => 'cortex', 'side' => 'emit', 'counterpart' => 'loop', 'contract_fingerprint' => 'fp', 'outcome' => 'BREACH']);

        $this->assertSame(2, $ledger->breachesThisWeekFor('loop', $monday));
        $this->assertSame(1, $ledger->breachesThisWeekFor('cortex', $monday));
        $this->assertSame(0, $ledger->breachesThisWeekFor('maestro', $monday));
    }

    public function test_refuses_unknown_kind(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->ledger()->append(['kind' => 'gossip', 'primitive' => 'loop', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);
    }
}
