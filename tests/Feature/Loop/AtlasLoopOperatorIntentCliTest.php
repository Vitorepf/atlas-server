<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopOperatorIntentCli;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentStreamReader;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopOperatorIntentCliTest extends TestCase
{
    private string $streamPath = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->streamPath = sys_get_temp_dir().'/atlas-intent-stream-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledgerPath = sys_get_temp_dir().'/atlas-intent-receipts-'.bin2hex(random_bytes(6)).'.jsonl';

        $this->app->instance(AtlasLoopOperatorIntentStreamReader::class, new AtlasLoopOperatorIntentStreamReader($this->streamPath));
        $this->app->instance(AtlasLoopOperatorIntentReceiptLedger::class, new AtlasLoopOperatorIntentReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->streamPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:intent', $args);

        return [$exit, $kernel->output()];
    }

    public function test_inspect_parses_remove_keepalive(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--message' => 'tira keepalive', '--json' => true]);

        $this->assertSame(AtlasLoopOperatorIntentCli::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertArrayHasKey('fact', $decoded);
        $this->assertSame('REMOVE', $decoded['fact']['verb']);
        $this->assertSame('keepalive', $decoded['fact']['object']);
    }

    public function test_inspect_vague_message_returns_rejection(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--message' => 'isso e massa', '--json' => true]);

        $this->assertSame(AtlasLoopOperatorIntentCli::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('both', $decoded['rejection']);
    }

    public function test_ingest_against_seeded_jsonl_counts_3_accepted_1_vague_1_schema(): void
    {
        $lines = [
            json_encode(['ts' => 1, 'raw_text' => 'tira keepalive', 'source' => 'chat']),
            json_encode(['ts' => 2, 'raw_text' => 'foco no loop sem mexer em marketing', 'source' => 'goal']),
            json_encode(['ts' => 3, 'raw_text' => 'adiciona um teste novo', 'source' => 'cli']),
            json_encode(['ts' => 4, 'raw_text' => 'isso e massa', 'source' => 'chat']),
            '{ this is not json',
        ];
        file_put_contents($this->streamPath, implode("\n", $lines)."\n");

        [$exit, $out] = $this->runCmd(['action' => 'ingest', '--json' => true]);

        $this->assertSame(AtlasLoopOperatorIntentCli::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(5, $decoded['ingested']);
        $this->assertSame(3, $decoded['accepted']);
        $this->assertSame(1, $decoded['rejected_vague']);
        $this->assertSame(1, $decoded['rejected_schema']);
        $this->assertGreaterThan(0, $decoded['last_offset']);

        $ledger = $this->app->make(AtlasLoopOperatorIntentReceiptLedger::class);
        $this->assertCount(5, $ledger->history(), 'ledger has 5 appended receipts');
    }
}
