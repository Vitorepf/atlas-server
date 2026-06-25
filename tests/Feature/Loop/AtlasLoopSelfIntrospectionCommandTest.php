<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfIntrospectionReceiptLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopSelfIntrospectionCommandTest extends TestCase
{
    private string $ledgerRoot = '';

    private string $envPath = '';

    private ?string $prevOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-self-introspect-'.bin2hex(random_bytes(6));
        $this->ledgerRoot = $base.'/ledger';
        @mkdir($this->ledgerRoot, 0o755, true);
        AtlasLoopSelfIntrospectionReceiptLedger::setRootForTesting($this->ledgerRoot);
        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, AtlasLoopMasterSwitch::KEY."=1\n");
        $this->prevOverride = AtlasLoopMasterSwitch::$envPathOverride;
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        Carbon::setTestNow('2026-06-25T12:00:00Z');
    }

    protected function tearDown(): void
    {
        AtlasLoopSelfIntrospectionReceiptLedger::setRootForTesting(null);
        AtlasLoopMasterSwitch::$envPathOverride = $this->prevOverride;
        Carbon::setTestNow();
        $this->rrmdir(\dirname($this->ledgerRoot));
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function countReceipts(): int
    {
        return count((array) glob($this->ledgerRoot.'/*/*/*.json'));
    }

    public function test_architecture_writes_one_receipt_and_emits_json_payload(): void
    {
        $before = $this->countReceipts();
        $exit = Artisan::call('atlas:loop:self', ['action' => 'architecture', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('architecture', $p['action']);
        $this->assertNotEmpty($p['payload']['rows']);
        $this->assertSame($before + 1, $this->countReceipts());
    }

    public function test_deps_and_coverage_each_grow_the_ledger_by_one(): void
    {
        $before = $this->countReceipts();
        Artisan::call('atlas:loop:self', ['action' => 'deps', '--json' => true]);
        Artisan::output();
        Artisan::call('atlas:loop:self', ['action' => 'coverage', '--json' => true]);
        Artisan::output();
        $this->assertSame($before + 2, $this->countReceipts());
    }

    public function test_double_run_same_action_is_idempotent_at_ledger_layer(): void
    {
        Artisan::call('atlas:loop:self', ['action' => 'architecture', '--json' => true]);
        Artisan::output();
        $afterFirst = $this->countReceipts();
        Artisan::call('atlas:loop:self', ['action' => 'architecture', '--json' => true]);
        Artisan::output();
        $afterSecond = $this->countReceipts();
        $this->assertSame($afterFirst, $afterSecond, 'identical introspection payload must be idempotent');
    }

    public function test_history_lists_receipts_within_since_window(): void
    {
        Artisan::call('atlas:loop:self', ['action' => 'architecture', '--json' => true]);
        Artisan::output();

        Artisan::call('atlas:loop:self', ['action' => 'history', '--since' => '-1day', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('history', $p['action']);
        $this->assertGreaterThanOrEqual(1, $p['count']);
    }

    public function test_history_on_empty_ledger_returns_zero_count_exit_0(): void
    {
        Artisan::call('atlas:loop:self', ['action' => 'history', '--since' => '-1day', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $p['count']);
        $this->assertSame([], $p['receipts']);
    }

    public function test_command_runs_when_master_switch_off(): void
    {
        file_put_contents($this->envPath, AtlasLoopMasterSwitch::KEY."=0\n");
        $exit = Artisan::call('atlas:loop:self', ['action' => 'architecture', '--json' => true]);
        $this->assertSame(0, $exit, 'introspection MUST run even when master switch is off');
    }

    public function test_unknown_action_returns_failure_and_lists_allowed_actions(): void
    {
        $exit = Artisan::call('atlas:loop:self', ['action' => 'bogus', '--json' => true]);
        $this->assertNotSame(0, $exit);
        $output = Artisan::output();
        foreach (['architecture', 'deps', 'coverage', 'history'] as $allowed) {
            $this->assertStringContainsString($allowed, $output, "error output must mention allowed action {$allowed}");
        }
    }
}
