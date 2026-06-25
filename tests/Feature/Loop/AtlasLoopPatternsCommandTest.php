<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopPatternsCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-patterns-receipts-'.bin2hex(random_bytes(6)).'.ndjson';

        // 5 episodes with shared tokens so the miner emits patterns.
        $episodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $episodes[] = [
                'episode_id' => 'c-'.$i,
                'tokens' => ['shared', 'shared_two', 'unique_'.$i],
            ];
        }
        app()->instance('atlas.loop.patterns.episodes', static fn (): array => $episodes);
        app()->instance(AtlasLoopCrossCyclePatternReceiptLedger::class, new AtlasLoopCrossCyclePatternReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:patterns', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_mine_is_byte_identical_across_two_runs(): void
    {
        $a = $this->runCmd(['action' => 'mine', '--n' => 5, '--json' => true]);
        $b = $this->runCmd(['action' => 'mine', '--n' => 5, '--json' => true]);

        self::assertSame(0, $a['exit']);
        self::assertSame($a['output'], $b['output']);
    }

    public function test_stable_emits_only_patterns_with_run_at_least_k_and_appends_one_receipt(): void
    {
        $r = $this->runCmd(['action' => 'stable', '--n' => 5, '--k' => 3, '--json' => true]);

        self::assertSame(0, $r['exit'], 'stable should succeed: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        foreach ((array) ($payload['stable_patterns'] ?? []) as $pattern) {
            self::assertGreaterThanOrEqual(3, (int) $pattern['longest_consecutive_run']);
        }

        self::assertFileExists($this->ledgerPath);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines, 'stable must append exactly ONE receipt line');
    }

    public function test_history_streams_ledger_receipts_chronologically(): void
    {
        // Seed two receipts via the stable subcommand.
        $this->runCmd(['action' => 'stable', '--n' => 5, '--k' => 3, '--json' => true]);
        $this->runCmd(['action' => 'stable', '--n' => 5, '--k' => 3, '--json' => true]);

        $r = $this->runCmd(['action' => 'history', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertGreaterThanOrEqual(2, count($payload['receipts']));
    }

    public function test_unknown_action_fails_with_usage(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
