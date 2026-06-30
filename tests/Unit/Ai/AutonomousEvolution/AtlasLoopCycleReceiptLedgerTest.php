<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptSigner;
use App\Services\Ai\AutonomousEvolution\Receipts\CycleReceiptChainRejection;
use Tests\TestCase;

final class AtlasLoopCycleReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-cycle-chain-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopCycleReceiptLedger
    {
        return new AtlasLoopCycleReceiptLedger(new AtlasLoopCycleReceiptSigner, $this->path);
    }

    private function signed(string $cycleId): array
    {
        return (new AtlasLoopCycleReceiptSigner)->sign([
            'schema_version' => AtlasLoopCycleReceiptSigner::BODY_SCHEMA,
            'cycle_id' => $cycleId,
            'facts' => ['impact' => [['target' => 'app/Foo.php', 'impact_score' => 5.0]]],
        ]);
    }

    public function test_three_appends_chain_in_order(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->signed('c1'));
        $ledger->append($this->signed('c2'));
        $ledger->append($this->signed('c3'));

        $entries = iterator_to_array($ledger->all());
        $this->assertCount(3, $entries);
        $this->assertSame([1, 2, 3], array_map(static fn (array $e): int => $e['seq'], $entries));
        $this->assertSame(AtlasLoopCycleReceiptLedger::GENESIS_PREV, $entries[0]['prev_chain_hash']);
        $this->assertSame($entries[0]['chain_hash'], $entries[1]['prev_chain_hash']);
        $this->assertSame($entries[1]['chain_hash'], $entries[2]['prev_chain_hash']);
    }

    public function test_verify_chain_clean_then_detects_a_rewrite_of_entry_two(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->signed('c1'));
        $ledger->append($this->signed('c2'));
        $ledger->append($this->signed('c3'));

        $this->assertTrue($ledger->verifyChain()['ok']);

        // Rewrite entry 2's body_canonical_sha256 on disk; re-read.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entry2 = json_decode($lines[1], true);
        $entry2['signed_receipt']['body_canonical_sha256'] = str_repeat('a', 64);
        $lines[1] = json_encode($entry2, JSON_UNESCAPED_SLASHES);
        file_put_contents($this->path, implode("\n", $lines)."\n");

        $verdict = $ledger->verifyChain();
        $this->assertFalse($verdict['ok']);
        $this->assertSame(2, $verdict['broken_at']);
    }

    public function test_append_rejects_a_receipt_that_fails_verification(): void
    {
        $signed = $this->signed('c1');
        $signed['body']['cycle_id'] = 'tampered'; // body no longer matches the stored signature

        $this->expectException(CycleReceiptChainRejection::class);
        $this->ledger()->append($signed);
    }

    public function test_zero_fraction_float_verifies_true_after_disk_round_trip(): void
    {
        $signer = new AtlasLoopCycleReceiptSigner;
        // score 2.0 — zero-fraction float that json_encode drops to `2` without PRESERVE_ZERO_FRACTION
        $signed = $signer->sign([
            'schema_version' => AtlasLoopCycleReceiptSigner::BODY_SCHEMA,
            'cycle_id' => 'float-roundtrip',
            'facts' => ['impact' => [['target' => 'app/Foo.php', 'impact_score' => 2.0]]],
        ]);

        $ledger = $this->ledger();
        $ledger->append($signed);

        $entries = iterator_to_array($ledger->all());
        $this->assertCount(1, $entries);
        $this->assertTrue(
            $signer->verify($entries[0]['signed_receipt']),
            'a zero-fraction float receipt must verify true after disk round-trip (no false tamper alarm)',
        );
    }

    public function test_concurrent_appends_from_two_processes_keep_consecutive_seqs(): void
    {
        $base = base_path();
        $script = sys_get_temp_dir().'/atlas-cycle-append-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($script, <<<PHP
<?php
require '{$base}/vendor/autoload.php';
\$app = require '{$base}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
\$signer = new App\\Services\\Ai\\AutonomousEvolution\\Receipts\\AtlasLoopCycleReceiptSigner;
\$signed = \$signer->sign(['schema_version' => App\\Services\\Ai\\AutonomousEvolution\\Receipts\\AtlasLoopCycleReceiptSigner::BODY_SCHEMA, 'cycle_id' => \$argv[2]]);
\$ledger = new App\\Services\\Ai\\AutonomousEvolution\\Receipts\\AtlasLoopCycleReceiptLedger(\$signer, \$argv[1]);
\$ledger->append(\$signed);
PHP);

        $procs = [];
        foreach (['proc-a', 'proc-b'] as $cid) {
            $procs[] = proc_open(
                ['php', $script, $this->path, $cid],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
        }
        foreach ($procs as $p) {
            if (is_resource($p)) {
                proc_close($p);
            }
        }
        @unlink($script);

        $entries = iterator_to_array($this->ledger()->all());
        $seqs = array_map(static fn (array $e): int => (int) $e['seq'], $entries);
        sort($seqs);

        $this->assertCount(2, $entries, 'both processes appended without a torn write');
        $this->assertSame([1, 2], $seqs, 'concurrent appends got consecutive seqs');
        $this->assertTrue($this->ledger()->verifyChain()['ok']);
    }
}
