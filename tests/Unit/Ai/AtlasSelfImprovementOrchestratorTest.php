<?php

namespace Tests\Unit\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasSelfImprovementOrchestratorTest extends TestCase
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

    public function test_nightly_review_plan_uses_domain_profile_contract(): void
    {
        $plan = app(AtlasSelfImprovementOrchestrator::class)->nightlyReviewPlan([
            'hours' => 12,
            'limit' => 4,
            'emit' => false,
        ]);

        $this->assertSame('AtlasSelfImprovementOrchestrator', $plan['orchestrator']);
        $this->assertSame('self_improvement', $plan['domain']);
        $this->assertSame('self_improvement.nightly_review', $plan['flow']);
        $this->assertSame('SelfImprovementRuntime', $plan['runtime']);
        $this->assertSame(12, data_get($plan, 'options.hours'));
        $this->assertSame(4, data_get($plan, 'options.limit'));
        $this->assertSame('AtlasSelfImprovementOrchestrator', data_get($plan, 'domain_profile.orchestrator'));
        $this->assertContains('risk_classification', data_get($plan, 'required_gates'));
        $this->assertFalse((bool) $plan['destructive_actions_allowed']);
    }

    public function test_execute_nightly_review_returns_plan_runtime_and_evidence_refs(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationFailed, [
            'envelope_id' => 'env_failed_for_orchestrator',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_failed_for_orchestrator',
            'correlation_id' => 'env_failed_for_orchestrator',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementOrchestrator::class)->executeNightlyReview([
            'hours' => 24,
            'limit' => 3,
            'emit' => false,
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('AtlasSelfImprovementOrchestrator', $result['orchestrator']);
        $this->assertSame('self_improvement.nightly_review', data_get($result, 'plan.flow'));
        $this->assertNotEmpty(data_get($result, 'runtime.run_id'));
        $this->assertNotEmpty($result['evidence_refs']);
        $this->assertTrue(AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.data_get($result, 'runtime.run_id'))
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->exists());
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
