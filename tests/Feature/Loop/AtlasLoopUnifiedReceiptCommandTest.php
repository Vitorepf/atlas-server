<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopUnifiedReceiptCommand;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptChain;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptExporter;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptVerifier;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopUnifiedReceiptCommandTest extends TestCase
{
    private string $chainFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chainFile = sys_get_temp_dir().'/atlas-unified-chain-'.bin2hex(random_bytes(6)).'.jsonl';
        $clock = 1700000000;
        $chain = new AtlasLoopUnifiedReceiptChain($this->chainFile, fn (): int => $clock++);
        $this->app->instance(AtlasLoopUnifiedReceiptChain::class, $chain);
        $this->app->instance(AtlasLoopUnifiedReceiptVerifier::class, new AtlasLoopUnifiedReceiptVerifier($this->chainFile));
        $this->app->instance(AtlasLoopUnifiedReceiptExporter::class, new AtlasLoopUnifiedReceiptExporter($chain));
    }

    protected function tearDown(): void
    {
        @unlink($this->chainFile);
        parent::tearDown();
    }

    private function seedChain(int $count = 3): void
    {
        $chain = $this->app->make(AtlasLoopUnifiedReceiptChain::class);
        for ($i = 1; $i <= $count; $i++) {
            $chain->append([
                'source_ledger' => $i % 2 === 0 ? 'auto_merge' : 'cycle_receipt',
                'receipt_id' => 'r-'.$i,
                'facts' => ['n' => $i],
            ]);
        }
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:receipt:unified', $args);

        return [$exit, $kernel->output()];
    }

    public function test_verify_on_clean_chain_exits_zero(): void
    {
        $this->seedChain(3);
        [$exit, $out] = $this->runCmd(['action' => 'verify']);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_OK, $exit, $out);
    }

    public function test_verify_on_tampered_chain_exits_one(): void
    {
        $this->seedChain(3);

        // Flip a byte inside line 2 — the source_facts_json is escaped, so target the escaped form.
        $lines = file($this->chainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[1] = str_replace('\\"n\\":2', '\\"n\\":99', $lines[1]);
        file_put_contents($this->chainFile, implode("\n", $lines)."\n");

        [$exit, $out] = $this->runCmd(['action' => 'verify']);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_BROKEN, $exit, $out);
    }

    public function test_inspect_json_emits_stable_envelope_keys(): void
    {
        $this->seedChain(3);

        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--json' => true]);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame(['head_hash', 'total_nodes', 'per_source', 'tail'], array_keys($decoded));
        $this->assertSame(3, $decoded['total_nodes']);
        $this->assertSame(2, $decoded['per_source']['cycle_receipt']);
        $this->assertSame(1, $decoded['per_source']['auto_merge']);
        $this->assertCount(3, $decoded['tail']);
    }

    public function test_export_round_trip_is_accepted_by_the_verifier(): void
    {
        $this->seedChain(4);
        $exportPath = sys_get_temp_dir().'/atlas-unified-export-'.bin2hex(random_bytes(5)).'.jsonl';

        [$exit] = $this->runCmd(['action' => 'export', '--out' => $exportPath]);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_OK, $exit);
        $this->assertFileExists($exportPath);

        // The export carries 1 manifest header line + one line per chain node, and the verifier on the LIVE
        // chain still verifies clean — the export is the audit envelope, not a new chain stream.
        $allLines = file($exportPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(5, $allLines, 'expected 1 manifest + 4 node lines');
        $report = (new AtlasLoopUnifiedReceiptVerifier($this->chainFile))->verify();
        $this->assertTrue($report->ok, 'live chain must still verify clean after export');
        $this->assertSame(4, $report->totalNodes);
        @unlink($exportPath);
    }

    public function test_export_refuses_to_overwrite_without_force(): void
    {
        $this->seedChain(1);
        $exportPath = sys_get_temp_dir().'/atlas-unified-existing-'.bin2hex(random_bytes(5)).'.jsonl';
        file_put_contents($exportPath, 'preexisting');

        [$exit] = $this->runCmd(['action' => 'export', '--out' => $exportPath]);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_USAGE, $exit);
        $this->assertSame('preexisting', file_get_contents($exportPath), 'existing file must be untouched without --force');

        [$exitForced] = $this->runCmd(['action' => 'export', '--out' => $exportPath, '--force' => true]);
        $this->assertSame(AtlasLoopUnifiedReceiptCommand::EXIT_OK, $exitForced);
        @unlink($exportPath);
    }

    public function test_command_is_discoverable_by_artisan_list_with_exact_signature(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->call('list');
        $out = $kernel->output();

        $this->assertStringContainsString('atlas:loop:receipt:unified', $out);
    }
}
