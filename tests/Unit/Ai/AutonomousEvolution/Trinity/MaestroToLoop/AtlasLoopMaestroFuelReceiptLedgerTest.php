<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopMaestroFuelReceiptLedger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Maestro→Loop fuel receipt ledger: idempotent on (step, packet_id) — recording twice persists
 * once; the four whitelisted steps are accepted, any other string throws; the public surface exposes NO
 * update/delete/truncate method (asserted via get_class_methods).
 */
final class AtlasLoopMaestroFuelReceiptLedgerTest extends TestCase
{
    private string $storageRoot;

    private AtlasLoopMaestroFuelReceiptLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_trinity_fuel_'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0775, true);
        $this->ledger = new AtlasLoopMaestroFuelReceiptLedger($this->storageRoot);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            @unlink($this->storageRoot.'/fuel-receipts.jsonl');
            @rmdir($this->storageRoot);
        }
        parent::tearDown();
    }

    public function test_record_is_idempotent_on_step_plus_packet_id(): void
    {
        $first = $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, 'P1', ['kind' => 'success']);
        $second = $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, 'P1', ['kind' => 'success']);

        $this->assertTrue($first, 'first record writes');
        $this->assertFalse($second, 'second record is a no-op');
        $this->assertSame(1, $this->ledger->count(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, 'P1'));
        $this->assertSame(1, count($this->ledger->all()));
    }

    public function test_distinct_step_or_packet_id_writes_a_new_row(): void
    {
        $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, 'P1');
        $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_GIVEBACK_SEEDED, 'P1');
        $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, 'P2');

        $this->assertSame(3, count($this->ledger->all()));
    }

    public function test_all_four_whitelisted_steps_are_accepted(): void
    {
        $steps = AtlasLoopMaestroFuelReceiptLedger::ALLOWED_STEPS;
        $this->assertCount(4, $steps);
        foreach ($steps as $i => $step) {
            $this->assertTrue($this->ledger->record($step, 'P-'.$i));
        }
    }

    public function test_invalid_step_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown fuel-receipt step/');
        $this->ledger->record('not_a_real_step', 'P1');
    }

    public function test_empty_packet_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ledger->record(AtlasLoopMaestroFuelReceiptLedger::STEP_DIGEST_RECORDED, '');
    }

    public function test_no_public_update_delete_or_truncate_method_exists(): void
    {
        $methods = array_map('strtolower', get_class_methods(AtlasLoopMaestroFuelReceiptLedger::class));
        foreach (['update', 'delete', 'truncate', 'remove', 'edit', 'patch'] as $banned) {
            $this->assertNotContains($banned, $methods, "append-only invariant: must not expose public {$banned}()");
        }
        $this->assertContains('record', $methods);
        $this->assertContains('all', $methods);
    }
}
