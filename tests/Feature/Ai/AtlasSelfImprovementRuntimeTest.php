<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasSelfImprovementRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_nightly_review_detects_ledger_gaps_and_records_its_own_cycle(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ExecutionStarted, [
            'envelope_id' => 'env_without_terminal',
            'job_id' => 'job-1',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_without_terminal',
            'correlation_id' => 'env_without_terminal',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::GateBlocked, [
            'envelope_id' => 'engineering_run:blocked',
            'gate_type' => 'tool_runtime',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'engineering_run:blocked',
            'correlation_id' => 'engineering_run:blocked',
            'emitter_stage' => 'atlas.tools.gate',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(emit: false, hours: 24, limit: 5);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['run_id']);
        $this->assertTrue($result['dry_run'], 'Dry-run should be true when emit=false.');
        $this->assertGreaterThanOrEqual(2, count($result['findings']));
        $this->assertDatabaseHas('atlas_initiative_runs', [
            'id' => $result['run_id'],
            'kind' => 'self_improvement_nightly_review',
            'status' => 'succeeded',
        ]);

        $cycleEvents = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->pluck('event_type')
            ->all();

        $this->assertContains(LedgerEventType::ExecutionStarted->value, $cycleEvents);
        $this->assertContains(LedgerEventType::LearningProposed->value, $cycleEvents);
        $this->assertContains(LedgerEventType::OperationCompleted->value, $cycleEvents);
    }

    public function test_command_outputs_json_payload(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationFailed, [
            'envelope_id' => 'env_failed',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_failed',
            'correlation_id' => 'env_failed',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('AtlasSelfImprovementOrchestrator', $payload['orchestrator']);
        $this->assertSame('self_improvement.nightly_review', data_get($payload, 'plan.flow'));
        $this->assertTrue(data_get($payload, 'runtime.ok'));
        $this->assertSame(24, data_get($payload, 'runtime.hours'));
        $this->assertNotEmpty(data_get($payload, 'runtime.findings'));
        $this->assertNotEmpty($payload['evidence_refs']);
    }

    private function createTables(): void
    {
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 48);
            $table->string('status', 24)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->default('{}');
            $table->json('findings')->default('[]');
            $table->json('emitted_inbox_item_ids')->default('[]');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
