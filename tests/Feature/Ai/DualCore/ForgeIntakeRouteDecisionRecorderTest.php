<?php

namespace Tests\Feature\Ai\DualCore;

use App\Models\AiDualCoreRouteDecision;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\DualCore\ForgeIntakeRouteDecisionRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForgeIntakeRouteDecisionRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ai_dual_core_route_decisions schema is in production; for SQLite
        // tests we boot it here so the recorder can persist canonically.
        Schema::dropIfExists('ai_dual_core_route_decisions');
        Schema::create('ai_dual_core_route_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default(DualCoreRouteDecisionCanon::SCHEMA_VERSION);
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('router_decision_id')->nullable();
            $table->uuid('intent_classification_id')->nullable();
            $table->string('conversation_id', 80)->nullable();
            $table->string('route', 40);
            $table->text('reason');
            $table->text('intent_summary');
            $table->string('ambiguity_level', 40);
            $table->string('risk_level', 40);
            $table->string('expected_duration', 40);
            $table->unsignedInteger('modules_touched_estimate')->default(1);
            $table->boolean('sdd_required')->default(false);
            $table->json('evidence_required');
            $table->boolean('operator_visible')->default(true);
            $table->json('rejected_routes')->nullable();
            $table->json('routing_signals')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('policy_refs')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->string('actor_type', 60);
            $table->string('decision_hash', 64);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_dual_core_route_decisions');
        parent::tearDown();
    }

    public function test_record_persists_route_decision_v1_for_forge_intake(): void
    {
        $recorder = app(ForgeIntakeRouteDecisionRecorder::class);
        $decision = $recorder->record(
            'forge',
            'works/abc-123/forge/live-executions',
            'abc-123',
            [
                'routing_signals' => ['entry' => 'POST /works/{project}/forge/live-executions'],
            ],
        );

        $this->assertNotNull($decision, 'recorder must return persisted row when table is present');
        $this->assertSame('forge', $decision->route);
        $this->assertSame('forge_http_intake', $decision->actor_type);
        $this->assertStringContainsString('forge_http_direct_intake:', $decision->reason);
        $this->assertSame(['dev'], $decision->rejected_routes);
        $this->assertSame('forge_http_direct', $decision->routing_signals['source']);
        $this->assertSame(4, $decision->routing_signals['mechanism']);
        $this->assertSame('high', $decision->risk_level);
        $this->assertSame('days', $decision->expected_duration);
        $this->assertSame(64, strlen((string) $decision->decision_hash));
        $this->assertSame(1, AiDualCoreRouteDecision::query()->count());
    }

    public function test_record_returns_null_and_logs_when_table_absent(): void
    {
        Schema::dropIfExists('ai_dual_core_route_decisions');

        $recorder = app(ForgeIntakeRouteDecisionRecorder::class);
        $decision = $recorder->record(
            'forge',
            'works/xyz/forge/live-executions/async',
            'xyz',
            [],
        );

        $this->assertNull($decision, 'recorder must degrade gracefully when ai_dual_core_route_decisions is absent');
    }

    public function test_record_uses_canonical_route_constant_when_empty(): void
    {
        $recorder = app(ForgeIntakeRouteDecisionRecorder::class);
        $decision = $recorder->record('', 'works/abc/forge/live-executions', 'abc');

        $this->assertNotNull($decision);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_FORGE, $decision->route);
    }
}
