<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiLedgerProjectionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropProjectionTables();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        $this->createProjectionTables();
    }

    protected function tearDown(): void
    {
        $this->dropProjectionTables();

        parent::tearDown();
    }

    public function test_command_projects_ledger_events_as_dry_run_without_writing(): void
    {
        $this->recordLedgerEvent(
            eventId: '01HPROJECTDRYRUN000000000001',
            envelopeId: 'env_projection_dry_run',
            eventType: LedgerEventType::ToolInvoked,
            payload: [
                'tool_slug' => 'phpstan',
                'surface' => 'atlas_dev',
                'run_context_id' => 'env_projection_dry_run',
            ],
            emitterStage: 'super_tool_runtime',
        );

        $exit = Artisan::call('atlas:ai:ledger-project', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.ledger_projection_worker.v1', data_get($payload, 'ledger_projection.schema_version'));
        $this->assertTrue((bool) data_get($payload, 'ledger_projection.dry_run'));
        $this->assertSame(1, data_get($payload, 'ledger_projection.event_count'));
        $this->assertSame(1, data_get($payload, 'ledger_projection.projected_count'));
        $this->assertSame('would_project', data_get($payload, 'ledger_projection.projection_results.0.status'));
        $this->assertSame(0, AtlasToolRun::query()->count());
    }

    public function test_command_projects_ledger_events_into_operational_read_models(): void
    {
        $this->recordLedgerEvent(
            eventId: '01HPROJECTPROVIDER000000001',
            envelopeId: 'env_projection_real',
            eventType: LedgerEventType::ProviderReturned,
            payload: [
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'operator_input' => 'Implement architecture kernel.',
                'workspace_label' => 'atlas-server',
                'provider_strategy' => ['mode' => 'auto_best_allowed'],
            ],
            emitterStage: 'provider_driver',
            traceId: '11111111-1111-4111-8111-111111111111',
        );
        $this->recordLedgerEvent(
            eventId: '01HPROJECTTOOL000000000001',
            envelopeId: 'env_projection_real',
            eventType: LedgerEventType::ToolReturned,
            payload: [
                'tool_slug' => 'phpstan',
                'surface' => 'atlas_dev',
                'run_context_id' => 'env_projection_real',
                'result' => ['exit_code' => 0],
            ],
            emitterStage: 'super_tool_runtime',
        );

        $exit = Artisan::call('atlas:ai:ledger-project', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(2, data_get($payload, 'ledger_projection.event_count'));
        $this->assertSame(3, data_get($payload, 'ledger_projection.projected_count'));

        $trace = AiTrace::query()->firstOrFail();
        $engineeringRun = AtlasEngineeringRun::query()->firstOrFail();
        $toolRun = AtlasToolRun::query()->firstOrFail();

        $this->assertSame('codex_cli', $trace->provider);
        $this->assertSame('gpt-5.2', $trace->model);
        $this->assertSame(LedgerEventType::ProviderReturned->value, data_get($trace->metadata, 'ledger_event_type'));
        $this->assertSame('atlas_engineering_runs', data_get($engineeringRun->metadata, 'projection_id'));
        $this->assertSame('phpstan', $toolRun->tool_slug);
        $this->assertSame('completed', $toolRun->status);
        $this->assertSame(LedgerEventType::ToolReturned->value, data_get($toolRun->metadata_json, 'ledger_event_type'));
    }

    public function test_command_reports_ledger_unavailable_when_source_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:ledger-project', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'ledger_projection.available'));
        $this->assertSame('ledger_missing', data_get($payload, 'ledger_projection.status'));
    }

    public function test_projection_worker_is_registered_as_recurring_maintenance(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('atlas:ai:ledger-project --hours=24 --limit=500 --json', $output);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordLedgerEvent(
        string $eventId,
        string $envelopeId,
        LedgerEventType $eventType,
        array $payload,
        string $emitterStage,
        ?string $traceId = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_projection_test',
            'operator_id' => 'operator_projection_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => 'receipt_'.$envelopeId,
            'trace_id' => $traceId,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => $eventType->value,
            'emitter_stage' => $emitterStage,
            'emitter_version' => 'projection-test-v1',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'occurred_at' => now(),
        ]);
    }

    private function createProjectionTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->string('status');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('trace_id')->nullable();
            $table->string('workspace_path_hash');
            $table->string('workspace_label');
            $table->json('provider_strategy_json')->nullable();
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tool_slug');
            $table->string('surface');
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->boolean('required')->default(false);
            $table->string('failure_policy');
            $table->string('policy_decision');
            $table->json('summary_json')->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestampsTz();
        });
    }

    private function dropProjectionTables(): void
    {
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
