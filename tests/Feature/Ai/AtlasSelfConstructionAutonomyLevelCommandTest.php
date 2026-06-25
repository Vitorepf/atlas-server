<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:autonomy-level covers levels / promote / degrade / history with
 * deterministic JSON receipts, facts-only inputs and zero provider/git shell-out.
 */
final class AtlasSelfConstructionAutonomyLevelCommandTest extends TestCase
{
    private string $factsPath = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_autonomy_facts_'.bin2hex(random_bytes(6)).'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_autonomy_ledger_'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_levels_action_lists_autonomy_ladder(): void
    {
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'levels', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertNotEmpty($p['order']);
        $this->assertNotEmpty($p['levels']);
    }

    public function test_promote_action_holds_when_facts_are_incomplete_and_appends_ledger(): void
    {
        $this->writeJson([
            'from_level' => 'bootstrap',
            'to_level' => 'assisted',
            'facts' => [
                'queue_health_green' => true,
                // native_implementation_ready missing → HOLD
            ],
            'ledger_path' => $this->ledgerPath,
            'lane' => 'lane-T',
            'created_at_unix' => 1700000300,
        ]);
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'promote', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('hold', $p['decision']);
        $this->assertNotEmpty($p['reasons']);
        $this->assertNotNull($p['ledger_event_hash']);
        $this->assertFileExists($this->ledgerPath);
    }

    public function test_degrade_action_returns_action_and_appends_ledger(): void
    {
        $this->writeJson([
            'facts' => [
                'false_green_detected' => true,
            ],
            'ledger_path' => $this->ledgerPath,
            'lane' => 'lane-T',
            'level' => 'atlas_supervised',
            'created_at_unix' => 1700000400,
        ]);
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'degrade', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertNotSame('no_action', $p['decision']);
        $this->assertNotEmpty($p['reasons']);
        $this->assertNotNull($p['ledger_event_hash']);
    }

    public function test_history_action_returns_events_for_lane(): void
    {
        // Seed two ledger events via promote/degrade.
        $this->writeJson([
            'from_level' => 'bootstrap', 'to_level' => 'assisted',
            'facts' => ['queue_health_green' => true],
            'ledger_path' => $this->ledgerPath, 'lane' => 'lane-H', 'created_at_unix' => 1700000500,
        ]);
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'promote', '--facts' => $this->factsPath, '--json' => true]);
        Artisan::output();

        $this->writeJson([
            'facts' => ['false_green_detected' => true],
            'ledger_path' => $this->ledgerPath, 'lane' => 'lane-H', 'level' => 'atlas_supervised', 'created_at_unix' => 1700000600,
        ]);
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'degrade', '--facts' => $this->factsPath, '--json' => true]);
        Artisan::output();

        // Now query history.
        $this->writeJson(['ledger_path' => $this->ledgerPath, 'lane' => 'lane-H']);
        Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'history', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame(2, $p['count']);
        $this->assertSame('lane-H', $p['events'][0]['lane']);
        $this->assertNotNull($p['latest']);
    }

    public function test_history_without_ledger_path_yields_usage_error(): void
    {
        $this->writeJson(['lane' => 'lane-Z']);
        $exit = Artisan::call('atlas:self-construction:autonomy-level', ['action' => 'history', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_command_source_does_not_shell_out_or_call_providers(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionAutonomyLevelCommand.php'));
        foreach (['shell_exec', 'proc_open', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "command source must NOT contain {$forbidden}");
        }
    }
}
