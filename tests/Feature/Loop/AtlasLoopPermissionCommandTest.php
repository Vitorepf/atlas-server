<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopPermissionCommand;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopPermissionCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->ledgerPath = sys_get_temp_dir().'/atlas-perm-cli-'.$tag.'.jsonl';
        config()->set('atlas.loop.master_enabled', true);
        config()->set('atlas.loop.permission_gradient.enabled', true);
        app()->instance(
            AtlasLoopPermissionLevelReceiptLedger::class,
            new AtlasLoopPermissionLevelReceiptLedger($this->ledgerPath, fn (): bool => true),
        );
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
        $exit = $kernel->call('atlas:loop:permission', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_inspect_prints_deterministic_byte_identical_table(): void
    {
        $r1 = $this->runCmd(['action' => 'inspect', '--json' => true]);
        $r2 = $this->runCmd(['action' => 'inspect', '--json' => true]);

        self::assertSame(0, $r1['exit']);
        self::assertSame(0, $r2['exit']);
        self::assertSame(hash('sha256', $r1['output']), hash('sha256', $r2['output']));
        $payload = json_decode(trim($r1['output']), true);
        self::assertCount(9, $payload);
        $phases = array_column($payload, 'phase');
        $sorted = $phases;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $phases);
    }

    public function test_enforce_allowed_path_returns_exit_zero_and_records_dry_run_receipt(): void
    {
        $r = $this->runCmd(['action' => 'enforce', '--phase' => 'merge', '--level' => 'MERGE', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('allow', $payload['decision']);
        self::assertTrue($payload['dry_run']);

        self::assertFileExists($this->ledgerPath);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
    }

    public function test_enforce_denied_path_returns_nonzero_with_typed_reason(): void
    {
        $r = $this->runCmd(['action' => 'enforce', '--phase' => 'observe', '--level' => 'MERGE', '--json' => true]);
        self::assertSame(AtlasLoopPermissionCommand::EXIT_DENY, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('deny', $payload['decision']);
        self::assertStringContainsString('operation_level_exceeds_phase_authority', $payload['reason']);
    }

    public function test_history_filters_by_phase_and_decision(): void
    {
        // Seed three receipts via three enforces.
        $this->runCmd(['action' => 'enforce', '--phase' => 'merge', '--level' => 'MERGE', '--json' => true]);
        $this->runCmd(['action' => 'enforce', '--phase' => 'observe', '--level' => 'MERGE', '--json' => true]);
        $this->runCmd(['action' => 'enforce', '--phase' => 'implement', '--level' => 'WRITE', '--json' => true]);

        $rDeny = $this->runCmd(['action' => 'history', '--decision' => 'deny', '--json' => true]);
        $deny = json_decode(trim($rDeny['output']), true);
        self::assertCount(1, $deny);
        self::assertSame('observe', $deny[0]['phase']);

        $rObs = $this->runCmd(['action' => 'history', '--phase' => 'observe', '--json' => true]);
        $obs = json_decode(trim($rObs['output']), true);
        self::assertCount(1, $obs);
        self::assertSame('observe', $obs[0]['phase']);
    }

    public function test_history_limit_zero_prints_nothing(): void
    {
        $this->runCmd(['action' => 'enforce', '--phase' => 'merge', '--level' => 'MERGE', '--json' => true]);
        $r = $this->runCmd(['action' => 'history', '--limit' => 0, '--json' => true]);
        self::assertSame(0, $r['exit']);
        self::assertSame([], json_decode(trim($r['output']), true));
    }

    public function test_history_with_no_ledger_returns_empty_array(): void
    {
        @unlink($this->ledgerPath);
        $r = $this->runCmd(['action' => 'history', '--json' => true]);
        self::assertSame(0, $r['exit']);
        self::assertSame([], json_decode(trim($r['output']), true));
    }

    public function test_unknown_action_returns_usage_exit(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertSame(AtlasLoopPermissionCommand::EXIT_USAGE, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_enforce_missing_phase_or_level_returns_usage(): void
    {
        $r = $this->runCmd(['action' => 'enforce', '--phase' => 'observe']);
        self::assertSame(AtlasLoopPermissionCommand::EXIT_USAGE, $r['exit']);
        self::assertStringContainsString('enforce_requires_phase_and_level', $r['output']);
    }
}
