<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:trinity:contract-receipts CLI replays the ledger and optionally filters by
 * primitive / kind, emitting deterministic JSON.
 */
final class AtlasLoopTrinityContractReceiptsCommandTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_trinity_receipts_cli_'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0775, true);
        $root = $this->storageRoot;
        $this->app->instance(AtlasLoopTrinityContractReceiptLedger::class, new AtlasLoopTrinityContractReceiptLedger($root, static fn (): int => 1700000000));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageRoot.'/*.ndjson') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageRoot);
        parent::tearDown();
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:trinity:contract-receipts', Artisan::all());
    }

    public function test_replays_appended_receipts_as_json(): void
    {
        $ledger = $this->app->make(AtlasLoopTrinityContractReceiptLedger::class);
        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);
        $ledger->append(['kind' => 'drift', 'primitive' => 'maestro', 'side' => 'consume', 'counterpart' => 'loop', 'contract_fingerprint' => 'fp', 'outcome' => 'BREACH']);

        $exit = Artisan::call('atlas:loop:trinity:contract-receipts', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $decoded['receipts']);
        $this->assertSame(1, $decoded['receipts'][0]['seq']);
        $this->assertSame(2, $decoded['receipts'][1]['seq']);
    }

    public function test_filters_by_primitive_and_kind(): void
    {
        $ledger = $this->app->make(AtlasLoopTrinityContractReceiptLedger::class);
        $ledger->append(['kind' => 'audit', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);
        $ledger->append(['kind' => 'drift', 'primitive' => 'loop', 'side' => 'emit', 'counterpart' => 'cortex', 'contract_fingerprint' => 'fp', 'outcome' => 'BREACH']);
        $ledger->append(['kind' => 'audit', 'primitive' => 'cortex', 'side' => 'consume', 'counterpart' => 'loop', 'contract_fingerprint' => 'fp', 'outcome' => 'CLEAN']);

        Artisan::call('atlas:loop:trinity:contract-receipts', ['--primitive' => 'loop', '--kind' => 'audit', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $decoded['receipts']);
        $this->assertSame('loop', $decoded['receipts'][0]['primitive']);
        $this->assertSame('audit', $decoded['receipts'][0]['kind']);
    }
}
