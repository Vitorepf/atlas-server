<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseEnforcer;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseRegistry;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopAnchorGatePerPhaseCliTest extends TestCase
{
    private string $ledgerRoot = '';

    private string $payloadPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-anchor-perphase-cli-'.$tag;
        $this->payloadPath = sys_get_temp_dir().'/atlas-anchor-perphase-payload-'.$tag.'.json';
        @mkdir($this->ledgerRoot, 0o755, true);

        $registry = new AtlasLoopAnchorGatePerPhaseRegistry();
        $enforcer = new AtlasLoopAnchorGatePerPhaseEnforcer($registry, enabled: true);
        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->ledgerRoot, enabled: true);
        app()->instance(AtlasLoopAnchorGatePerPhaseRegistry::class, $registry);
        app()->instance(AtlasLoopAnchorGatePerPhaseEnforcer::class, $enforcer);
        app()->instance(AtlasLoopAnchorGatePerPhaseReceiptLedger::class, $ledger);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ledgerRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->ledgerRoot);
        @unlink($this->payloadPath);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:anchor:per-phase', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_inspect_prints_floor_and_must_anchor_kinds(): void
    {
        $r = $this->runCmd(['action' => 'inspect', '--phase' => 'decide', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertGreaterThan(0.0, $payload['anchored_symbols_per_kchar']);
        self::assertNotEmpty($payload['must_anchor_kinds']);
    }

    public function test_enforce_with_below_floor_payload_refuses_and_records_one_ledger_row(): void
    {
        file_put_contents($this->payloadPath, json_encode([
            'text' => str_repeat('x', 10000),
            'anchors' => [['kind' => 'class', 'name' => 'X']],
        ]));

        $before = count(glob($this->ledgerRoot.'/*.jsonl') ?: []) === 0
            ? 0
            : array_sum(array_map(static fn (string $f): int => count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), glob($this->ledgerRoot.'/*.jsonl') ?: []));

        $r = $this->runCmd([
            'action' => 'enforce',
            '--phase' => 'implement',
            '--payload' => $this->payloadPath,
            '--cycle' => 'cyc-1',
            '--json' => true,
        ]);

        $after = array_sum(array_map(static fn (string $f): int => count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), glob($this->ledgerRoot.'/*.jsonl') ?: []));

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('anchor_density_below_floor', $r['output']);
        self::assertSame($before + 1, $after);
    }

    public function test_history_caps_at_limit_and_filters_by_phase(): void
    {
        // Seed 4 verdicts.
        for ($i = 0; $i < 4; $i++) {
            file_put_contents($this->payloadPath, json_encode(['text' => 'x', 'anchors' => []]));
            $this->runCmd([
                'action' => 'enforce',
                '--phase' => 'implement',
                '--payload' => $this->payloadPath,
                '--cycle' => 'cyc-'.$i,
                '--json' => true,
            ]);
        }

        $r = $this->runCmd(['action' => 'history', '--phase' => 'implement', '--limit' => 3, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertCount(3, $payload['rows']);
    }

    public function test_unknown_action_fails_with_usage(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
