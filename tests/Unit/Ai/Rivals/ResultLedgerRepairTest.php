<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ResultLedger;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ResultLedgerRepairTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_ledger_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        RunPaths::ensureDir($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_quarantine_moves_corrupt_chain_to_epoch(): void
    {
        $path = RunPaths::ledgerPath();
        file_put_contents($path, "{not-json\n");
        $out = (new ResultLedger)->quarantineCorruptEpoch();
        $this->assertSame('ok', $out['status']);
        $this->assertArrayHasKey('quarantine_path', $out);
        $this->assertFileExists($out['quarantine_path']);
        $this->assertTrue(is_file($path));
        $chain = (new ResultLedger)->verifyChain();
        $this->assertTrue($chain['verified']);
    }

    public function test_quarantine_noop_when_chain_valid(): void
    {
        file_put_contents(RunPaths::ledgerPath(), '');
        $out = (new ResultLedger)->quarantineCorruptEpoch();
        $this->assertSame('ok', $out['status']);
        $this->assertTrue(in_array($out['reason'] ?? '', ['chain_already_valid', 'ledger_absent'], true)
            || ($out['reason'] ?? null) === 'chain_already_valid'
            || isset($out['entries']));
    }
}
