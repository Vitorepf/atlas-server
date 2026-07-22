<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\IntentReceiptDecision;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopOperatorIntentReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-intent-receipts-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopOperatorIntentReceiptLedger
    {
        return new AtlasLoopOperatorIntentReceiptLedger($this->path);
    }

    public function test_three_records_produce_three_lines_and_never_truncate(): void
    {
        $ledger = $this->ledger();
        $ledger->record('fact-1', IntentReceiptDecision::ACCEPTED, 'ok');
        $ledger->record('fact-2', IntentReceiptDecision::REJECTED_VAGUE, 'both_axes_missing');
        $ledger->record('fact-3', IntentReceiptDecision::QUEUED_FOR_DECIDER, 'awaiting_pricing', 'task-99');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(3, $lines);
        $history = $ledger->history();
        $this->assertCount(3, $history);
        $this->assertSame('fact-1', $history[0]->factId);
        $this->assertSame('fact-3', $history[2]->factId);
        $this->assertSame('task-99', $history[2]->downstreamRef);
    }

    public function test_history_filtered_by_fact_id_returns_only_matching_receipts(): void
    {
        $ledger = $this->ledger();
        $ledger->record('alpha', IntentReceiptDecision::ACCEPTED, 'r1');
        $ledger->record('beta', IntentReceiptDecision::REJECTED_SCHEMA, 'r2');
        $ledger->record('alpha', IntentReceiptDecision::QUEUED_FOR_DECIDER, 'r3');

        $alpha = $ledger->history('alpha');
        $this->assertCount(2, $alpha);
        $this->assertSame(['accepted', 'queued_for_decider'], array_map(static fn ($r) => $r->decision, $alpha));
    }

    public function test_receipt_id_equals_sha256_of_fact_recorded_decision(): void
    {
        $ledger = $this->ledger();
        $ledger->setClock(fn () => CarbonImmutable::parse('2026-06-24T12:00:00+00:00'));

        $receipt = $ledger->record('factX', IntentReceiptDecision::ACCEPTED, 'happy');

        $expected = hash('sha256', 'factX|'.$receipt->recordedAt.'|accepted');
        $this->assertSame($expected, $receipt->receiptId);
    }

    public function test_concurrent_writers_via_separate_processes_keep_all_records(): void
    {
        $base = base_path();
        $script = sys_get_temp_dir().'/atlas-receipt-append-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($script, <<<PHP
<?php
require '{$base}/vendor/autoload.php';
\$app = require '{$base}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
\$ledger = new App\\Services\\Ai\\AutonomousEvolution\\Quaternity\\IntentIngest\\AtlasLoopOperatorIntentReceiptLedger(\$argv[1]);
for (\$i = 0; \$i < 5; \$i++) {
    \$ledger->record('fact-'.\$argv[2].'-'.\$i, App\\Services\\Ai\\AutonomousEvolution\\Quaternity\\IntentIngest\\IntentReceiptDecision::ACCEPTED, 'concurrent');
}
PHP);

        $procs = [];
        foreach (['a', 'b'] as $w) {
            $procs[] = proc_open(['php', $script, $this->path, $w], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            if (is_resource($p)) {
                proc_close($p);
            }
        }
        @unlink($script);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(10, $lines, 'flock + FILE_APPEND must keep all 10 records (no torn writes)');
    }
}
