<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ResultLedger;
use App\Services\Ai\Rivals\Support\RunPaths;
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

    public function test_same_logical_decision_is_idempotent(): void
    {
        $ledger = new ResultLedger;
        $first = $ledger->append('run_a', ['verdict' => 'valid']);
        $again = $ledger->append('run_a', ['verdict' => 'valid']);

        $this->assertSame($first['entry_id'], $again['entry_id']);
        $this->assertTrue($again['idempotent_replay']);
        $this->assertSame(1, $ledger->verifyChain()['entries']);
    }

    public function test_semantic_verify_binds_latest_report_to_disk(): void
    {
        $runId = 'run_semantic';
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(RunPaths::evidencePath($runId), '{"evidence":true}');
        file_put_contents(RunPaths::adjudicationPath($runId), '{"verdict":"valid"}');
        file_put_contents(RunPaths::reportPath($runId), '{"pipeline_valid":true}');

        $ledger = new ResultLedger;
        $ledger->append($runId, ['verdict' => 'valid']);
        $ledger->appendReport($runId, [
            'pipeline_valid' => true,
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'not_ready_reasons' => ['sample_inadequate'],
        ]);
        $this->assertTrue($ledger->verifySemantic()['verified']);

        file_put_contents(RunPaths::reportPath($runId), '{"pipeline_valid":false}');
        $semantic = $ledger->verifySemantic();
        $this->assertFalse($semantic['verified']);
        $this->assertNotEmpty(preg_grep('/report_hash/', $semantic['failures']));
    }
}
