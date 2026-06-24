<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCycleReceiptCommand;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptSigner;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopCycleReceiptCommandTest extends TestCase
{
    private string $chainPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chainPath = sys_get_temp_dir().'/atlas-cycle-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        // Override the singleton-bound ledger with one pinned to a temp chain path.
        $this->app->instance(
            AtlasLoopCycleReceiptLedger::class,
            new AtlasLoopCycleReceiptLedger(new AtlasLoopCycleReceiptSigner, $this->chainPath),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->chainPath);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopCycleReceiptLedger
    {
        return $this->app->make(AtlasLoopCycleReceiptLedger::class);
    }

    private function signed(string $cycleId): array
    {
        return (new AtlasLoopCycleReceiptSigner)->sign([
            'schema_version' => AtlasLoopCycleReceiptSigner::BODY_SCHEMA,
            'cycle_id' => $cycleId,
            'facts' => ['impact' => [['target' => 'app/Foo.php', 'impact_score' => 5.0]]],
        ]);
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:receipt', $args);

        return [$exit, $kernel->output()];
    }

    public function test_latest_json_against_two_entries_returns_seq_two(): void
    {
        $signedTwo = $this->signed('cycle-2');
        $this->ledger()->append($this->signed('cycle-1'));
        $this->ledger()->append($signedTwo);

        [$exit, $out] = $this->runCmd(['action' => 'latest', '--json' => true]);
        $this->assertSame(AtlasLoopCycleReceiptCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $decoded['seq']);
        $this->assertSame($signedTwo['body_canonical_sha256'], $decoded['signed_receipt']['body_canonical_sha256']);
    }

    public function test_verify_against_clean_chain_exits_zero_and_prints_ok(): void
    {
        $this->ledger()->append($this->signed('cycle-1'));
        $this->ledger()->append($this->signed('cycle-2'));

        [$exit, $out] = $this->runCmd(['action' => 'verify']);

        $this->assertSame(AtlasLoopCycleReceiptCommand::EXIT_OK, $exit);
        $this->assertStringContainsString('ok', $out);
    }

    public function test_verify_against_tampered_chain_exits_one_and_prints_broken_seq(): void
    {
        $this->ledger()->append($this->signed('cycle-1'));
        $this->ledger()->append($this->signed('cycle-2'));
        $this->ledger()->append($this->signed('cycle-3'));

        // Tamper entry 2 on disk.
        $lines = file($this->chainPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $e2 = json_decode($lines[1], true);
        $e2['signed_receipt']['body_canonical_sha256'] = str_repeat('a', 64);
        $lines[1] = json_encode($e2, JSON_UNESCAPED_SLASHES);
        file_put_contents($this->chainPath, implode("\n", $lines)."\n");

        [$exit, $out] = $this->runCmd(['action' => 'verify']);
        $this->assertSame(AtlasLoopCycleReceiptCommand::EXIT_BROKEN, $exit);
        $this->assertStringContainsString('seq=2', $out);
    }

    public function test_chain_limit_5_against_7_entries_renders_5_rows_oldest_first(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->ledger()->append($this->signed('cycle-'.$i));
        }

        [$exit, $out] = $this->runCmd(['action' => 'chain', '--limit' => 5, '--json' => true]);
        $this->assertSame(AtlasLoopCycleReceiptCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(5, $decoded['entries']);
        $seqs = array_map(static fn (array $e): int => (int) $e['seq'], $decoded['entries']);
        $this->assertSame([3, 4, 5, 6, 7], $seqs, 'last 5 entries in seq order');
    }

    public function test_provider_binds_ledger_and_signer_as_singletons(): void
    {
        // Use a fresh app where the test's override isn't in play so we observe the AppServiceProvider binding.
        $this->refreshApplication();

        $a1 = $this->app->make(AtlasLoopCycleReceiptLedger::class);
        $a2 = $this->app->make(AtlasLoopCycleReceiptLedger::class);
        $this->assertSame($a1, $a2, 'ledger is a singleton');

        $s1 = $this->app->make(AtlasLoopCycleReceiptSigner::class);
        $s2 = $this->app->make(AtlasLoopCycleReceiptSigner::class);
        $this->assertSame($s1, $s2, 'signer is a singleton');
    }
}
