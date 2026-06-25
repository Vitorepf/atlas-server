<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceReceiptLedger;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCoherenceCommandTest extends TestCase
{
    private string $ledgerPath = '';

    private string $sourceFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->ledgerPath = sys_get_temp_dir().'/atlas-coh-cli-'.$tag.'.jsonl';
        $this->sourceFile = sys_get_temp_dir().'/atlas-coh-cli-src-'.$tag.'.php';
        file_put_contents($this->sourceFile, "<?php\nclass Foo {}\n");

        $ledger = new AtlasLoopPostEditCoherenceReceiptLedger($this->ledgerPath);
        app()->instance(AtlasLoopPostEditCoherenceReceiptLedger::class, $ledger);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        @unlink($this->sourceFile);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:coherence', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_scan_emits_receipt_then_inspect_returns_byte_for_byte_match(): void
    {
        $r = $this->runCmd([
            'action' => 'scan',
            '--edit-set' => [$this->sourceFile],
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $scan = json_decode($r['output'], true);
        self::assertIsArray($scan);
        self::assertArrayHasKey('receipt_id', $scan);
        self::assertArrayHasKey('scanner_findings', $scan);
        self::assertArrayHasKey('orphan_findings', $scan);

        $r2 = $this->runCmd(['action' => 'inspect', '--id' => $scan['receipt_id'], '--json' => true]);
        self::assertSame(0, $r2['exit']);
        $inspect = json_decode($r2['output'], true);
        self::assertSame($scan, $inspect);
    }

    public function test_history_caps_at_limit_in_chronological_order(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->runCmd(['action' => 'scan', '--edit-set' => [$this->sourceFile], '--json' => true]);
        }
        $r = $this->runCmd(['action' => 'history', '--limit' => 3, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertCount(3, $payload['rows']);
        $tsList = array_column($payload['rows'], 'ran_at');
        $sorted = $tsList;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $tsList);
    }

    public function test_unknown_action_returns_non_zero_with_clear_error(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_command_class_contains_no_scan_or_parse_logic(): void
    {
        $reflection = new ReflectionClass(\App\Console\Commands\AtlasLoopCoherenceCommand::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        self::assertStringNotContainsString('token_get_all', $source);
        self::assertStringNotContainsString('PhpParser', $source);
    }

    public function test_command_is_listed_by_artisan_list(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('list', [], $buf);
        self::assertStringContainsString('atlas:loop:coherence', $buf->fetch());
    }
}
