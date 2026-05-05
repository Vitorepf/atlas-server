<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRepairCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_plans_repair_as_json_without_execution(): void
    {
        $exit = Artisan::call('atlas:ai:repair', [
            '--envelope' => 'env_repair_command',
            '--receipt' => 'receipt_repair_command',
            '--failure' => 'provider.timeout',
            '--signal' => ['provider timed out'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('planned_scaffold', $payload['status']);
        $this->assertSame('env_repair_command', data_get($payload, 'request.envelope_id'));
        $this->assertSame('provider.timeout', data_get($payload, 'request.failure_classification.failure_domain'));
        $this->assertSame('repair_allowed', data_get($payload, 'repair.status'));
        $this->assertSame('retry_provider', data_get($payload, 'repair.strategy'));
        $this->assertSame(['repair_planned'], data_get($payload, 'repair.reasons'));
        $this->assertFalse(data_get($payload, 'repair.metadata.executes_repair'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.recorded'));
        $this->assertSame(LedgerEventType::RepairInitiated->value, data_get($payload, 'evidence_ledger.event_type'));
        $this->assertSame('atlas_cli.repair', data_get($payload, 'evidence_ledger.emitter_stage'));
        $this->assertFalse(data_get($payload, 'compliance.execution_enabled'));
        $this->assertTrue(data_get($payload, 'compliance.ok'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_command',
            'receipt_id' => 'receipt_repair_command',
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas_cli.repair',
        ]);
    }

    public function test_command_attempt_is_scaffolded_and_blocked_by_dry_run(): void
    {
        $exit = Artisan::call('atlas:ai:repair', [
            '--envelope' => 'env_repair_attempt',
            '--failure' => 'provider.timeout',
            '--attempt-repair' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('attempted_scaffold', $payload['status']);
        $this->assertSame('repair_allowed', data_get($payload, 'repair.decision.status'));
        $this->assertFalse(data_get($payload, 'repair.executed'));
        $this->assertFalse(data_get($payload, 'repair.attempt.executed'));
        $this->assertTrue(data_get($payload, 'repair.attempt.dry_run'));
        $this->assertSame(['execution_blocked_by_dry_run'], data_get($payload, 'repair.attempt.reasons'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.recorded'));
        $this->assertSame(LedgerEventType::RepairInitiated->value, data_get($payload, 'evidence_ledger.event_type'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.completed.recorded'));
        $this->assertSame(LedgerEventType::RepairCompleted->value, data_get($payload, 'evidence_ledger.completed.event_type'));
        $this->assertSame('atlas_cli.repair', data_get($payload, 'evidence_ledger.completed.emitter_stage'));
        $this->assertFalse(data_get($payload, 'compliance.execution_enabled'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_attempt',
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas_cli.repair',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_attempt',
            'event_type' => LedgerEventType::RepairCompleted->value,
            'emitter_stage' => 'atlas_cli.repair',
        ]);
    }

    public function test_command_blocks_heavy_repair_without_evidence(): void
    {
        $exit = Artisan::call('atlas:ai:repair', [
            '--envelope' => 'env_heavy_repair',
            '--failure' => 'harness.failed',
            '--strategy' => ['rerun_harness'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('repair_blocked', data_get($payload, 'repair.status'));
        $this->assertSame('rerun_harness', data_get($payload, 'repair.strategy'));
        $this->assertSame(['heavy_repair_requires_evidence_refs'], data_get($payload, 'repair.reasons'));
    }

    public function test_command_routes_compliance_violations_to_human_review(): void
    {
        $exit = Artisan::call('atlas:ai:repair', [
            '--envelope' => 'env_compliance_repair',
            '--failure' => 'compliance.violation',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('needs_human_review', data_get($payload, 'repair.status'));
        $this->assertSame('human_review', data_get($payload, 'repair.strategy'));
        $this->assertSame(['failure_domain_requires_human_review'], data_get($payload, 'repair.reasons'));
    }
}
