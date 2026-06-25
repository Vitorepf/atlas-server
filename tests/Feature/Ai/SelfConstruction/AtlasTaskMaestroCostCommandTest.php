<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AtlasTaskMaestroCostCommandTest extends TestCase
{
    private string $base = '';

    private string $costPath = '';

    private string $budgetPath = '';

    private string $envPath = '';

    private ?string $prevOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-maestro-cost-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->base, 0o755, true);
        $this->costPath = $this->base.'/cost.jsonl';
        $this->budgetPath = $this->base.'/budget.jsonl';
        $this->envPath = $this->base.'/.env';
        $this->prevOverride = AtlasLoopMasterSwitch::$envPathOverride;
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        $this->setMaster(true);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = $this->prevOverride;
        foreach ([$this->costPath, $this->budgetPath, $this->envPath] as $f) {
            @unlink($f);
        }
        @rmdir($this->base);
        parent::tearDown();
    }

    private function setMaster(bool $on): void
    {
        file_put_contents($this->envPath, AtlasLoopMasterSwitch::KEY.'='.($on ? '1' : '0')."\n");
    }

    private function runCli(string $action, array $extra = []): array
    {
        $args = [
            'action' => $action,
            '--ledger-path' => $this->costPath,
            '--budget-receipt-path' => $this->budgetPath,
            '--json' => true,
        ] + $extra;
        $exit = Artisan::call('atlas:task:maestro:cost', $args);

        return ['exit' => $exit, 'out' => trim(Artisan::output())];
    }

    public function test_ledger_action_emits_frozen_schema_envelope_with_status_empty_on_no_facts(): void
    {
        $r = $this->runCli('ledger');
        $p = json_decode($r['out'], true);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('atlas.maestro.cost.ledger.v1', $p['schema']);
        $this->assertSame('empty', $p['status']);
        $this->assertIsArray($p['payload']);
    }

    public function test_aggregate_action_emits_envelope(): void
    {
        $r = $this->runCli('aggregate', ['--by' => 'task_class']);
        $p = json_decode($r['out'], true);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('atlas.maestro.cost.aggregate.v1', $p['schema']);
        $this->assertContains($p['status'], ['ok', 'empty']);
    }

    public function test_budget_action_emits_envelope(): void
    {
        $r = $this->runCli('budget', ['--task' => 'pkt-1', '--provider' => 'codex', '--task-class' => 'refactor']);
        $p = json_decode($r['out'], true);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('atlas.maestro.cost.budget.v1', $p['schema']);
        $this->assertArrayHasKey('verdict', $p['payload']);
    }

    public function test_history_action_emits_envelope(): void
    {
        $r = $this->runCli('history', ['--task' => 'pkt-1']);
        $p = json_decode($r['out'], true);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('atlas.maestro.cost.history.v1', $p['schema']);
    }

    public function test_unknown_action_yields_error_status_and_exit_1(): void
    {
        $r = $this->runCli('bogus');
        $p = json_decode($r['out'], true);
        $this->assertNotSame(0, $r['exit']);
        $this->assertSame('error', $p['status']);
    }

    public function test_master_off_returns_disabled_with_empty_payload_and_zero_db_queries(): void
    {
        $this->setMaster(false);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = $this->runCli('ledger');
        $p = json_decode($r['out'], true);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('disabled', $p['status']);
        $this->assertSame([], $p['payload']);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_json_output_is_byte_stable_across_two_invocations(): void
    {
        $a = $this->runCli('ledger');
        $b = $this->runCli('ledger');
        $this->assertSame($a['out'], $b['out']);
    }

    public function test_command_source_has_no_write_tokens(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasTaskMaestroCostCommand.php'));
        foreach (['persist(', 'record(', 'DB::insert', 'DB::update', 'DB::delete'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "CLI must NOT contain {$forbidden}");
        }
    }
}
