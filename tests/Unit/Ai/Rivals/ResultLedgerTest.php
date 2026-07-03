<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ResultLedger;
use Tests\TestCase;

class ResultLedgerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_ledger_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    public function test_appends_chained_entries_and_verifies(): void
    {
        $ledger = new ResultLedger;
        $first = $ledger->append('run_a', ['verdict' => 'valid']);
        $second = $ledger->append('run_b', ['verdict' => 'invalid']);

        $this->assertSame('genesis', $first['prev_hash']);
        $this->assertSame($first['entry_hash'], $second['prev_hash']);

        $chain = $ledger->verifyChain();
        $this->assertTrue($chain['verified']);
        $this->assertSame(2, $chain['entries']);
    }

    public function test_corrupting_a_line_breaks_chain_verification(): void
    {
        $ledger = new ResultLedger;
        $ledger->append('run_a', ['verdict' => 'valid']);
        $ledger->append('run_b', ['verdict' => 'valid']);

        $path = $this->storage.'/ledger.jsonl';
        // adultera 1 caractere do payload da primeira linha
        $lines = explode(PHP_EOL, file_get_contents($path));
        $lines[0] = str_replace('run_a', 'run_X', $lines[0]);
        file_put_contents($path, implode(PHP_EOL, $lines));

        $chain = $ledger->verifyChain();
        $this->assertFalse($chain['verified']);
        $this->assertNotEmpty($chain['failures']);
    }
}
