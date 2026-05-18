<?php

namespace Tests\Feature\Ai\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\AiGatewayMissionBridge;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

/**
 * Phase 1 contract tests for the AiWorker → Kernel bridge.
 *
 * The bridge is the seam that records `atlas.ai.aiworker.kernel_envelope.v1`
 * into trace + job metadata so subsequent phases can attach Policy /
 * Evidence / Certification without re-deriving the mission. These tests
 * prove the audit-mandated invariants:
 *
 *   - Feature flag default false → bridge returns null, gateway keeps legacy.
 *   - Feature flag on + tables present → mission row created, envelope built.
 *   - Programming-style prompt routes via Atlas Dev's task path (decompose
 *     + work_orders + planned transition).
 *   - Bridge failure NEVER bubbles back to gateway; emits structured Log +
 *     stub envelope so legacy path proceeds and failure stays auditable.
 *   - Envelope shape is stable and contains the canonical kernel keys.
 *
 * Canon: docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
 */
class AiGatewayMissionBridgeTest extends TestCase
{
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        config()->set('atlas_ai.kernel_http_integration.enabled', false);
        config()->set('atlas_ai.kernel_http_integration.trivial_skips_kernel', true);
    }

    protected function tearDown(): void
    {
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     * Flag off → legacy path preserved
     * ------------------------------------------------------------------- */

    public function test_flag_off_returns_null_and_creates_no_mission(): void
    {
        $bridge = app(AiGatewayMissionBridge::class);

        $envelope = $bridge->buildEnvelope('Refactor the auth subsystem to support SSO');

        $this->assertNull($envelope);
        $this->assertSame(0, AiMission::query()->count());
        $this->assertFalse($bridge->enabled());
    }

    public function test_flag_on_with_missing_tables_returns_null_and_logs(): void
    {
        Log::spy();
        config()->set('atlas_ai.kernel_http_integration.enabled', true);
        $this->dropMissionFoundationTables();

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope('Refactor');

        $this->assertNull($envelope);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg): bool => $msg === 'atlas.ai_gateway.mission_bridge.skipped_missing_tables')
            ->atLeast()->once();
    }

    /* ---------------------------------------------------------------------
     * Flag on + tables → canonical envelope + mission row
     * ------------------------------------------------------------------- */

    public function test_flag_on_records_mission_and_returns_canonical_envelope(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope(
            'Implementar refactor da autenticacao para suportar SSO em multiplos provedores',
        );

        $this->assertIsArray($envelope);
        $this->assertSame(AiGatewayMissionBridge::ENVELOPE_SCHEMA, $envelope['schema']);
        $this->assertSame(AiGatewayMissionBridge::ENVELOPE_SOURCE, $envelope['source']);
        $this->assertTrue($envelope['enabled_by_flag']);
        $this->assertNotNull($envelope['mission_id']);
        $this->assertSame(1, AiMission::query()->count());
        $mission = AiMission::query()->first();
        $this->assertSame($mission->id, $envelope['mission_id']);
        $this->assertSame($mission->uuid, $envelope['mission_uuid']);
        $this->assertNotEmpty($envelope['recorded_at']);
    }

    public function test_task_prompt_decomposes_and_transitions_to_planned(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope(
            'Implementar correcao do endpoint /healthz com cobertura phpunit',
        );

        $this->assertNotEmpty($envelope['mission_id']);
        $this->assertContains($envelope['mission_type'], [
            MissionFactoryService::TYPE_TASK,
            MissionFactoryService::TYPE_MISSION,
        ]);
        // Non-trivial path runs the decomposer + work_order plan + transition.
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $envelope['mission_status']);
        $this->assertNotNull($envelope['objective_id']);
        $this->assertNotNull($envelope['work_order_id']);
        $this->assertGreaterThanOrEqual(1, $envelope['objectives_count'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $envelope['work_orders_count'] ?? 0);
    }

    /* ---------------------------------------------------------------------
     * Trivial prompt → skipped envelope, no mission row
     * ------------------------------------------------------------------- */

    public function test_trivial_prompt_is_skipped_when_trivial_skips_enabled(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);
        config()->set('atlas_ai.kernel_http_integration.trivial_skips_kernel', true);

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope('oi');

        $this->assertIsArray($envelope);
        $this->assertSame(MissionFactoryService::TYPE_TRIVIAL, $envelope['mission_type']);
        $this->assertSame('skipped', $envelope['mission_status']);
        $this->assertSame('trivial_skips_kernel', $envelope['skipped_reason']);
        $this->assertNull($envelope['mission_id']);
        $this->assertSame(0, AiMission::query()->count());
    }

    public function test_trivial_prompt_creates_mission_when_trivial_skips_disabled(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);
        config()->set('atlas_ai.kernel_http_integration.trivial_skips_kernel', false);

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope('oi');

        $this->assertNotNull($envelope['mission_id']);
        $this->assertSame(MissionFactoryService::TYPE_TRIVIAL, $envelope['mission_type']);
        $this->assertSame(1, AiMission::query()->count());
        // Trivial keeps lifecycle at draft (no decomposition).
        $this->assertSame(MissionLifecycleService::STATUS_DRAFT, $envelope['mission_status']);
        $this->assertNull($envelope['objective_id']);
        $this->assertNull($envelope['work_order_id']);
    }

    /* ---------------------------------------------------------------------
     * Empty + corner cases
     * ------------------------------------------------------------------- */

    public function test_empty_input_returns_null_without_inserting_mission(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope('   ');

        $this->assertNull($envelope);
        $this->assertSame(0, AiMission::query()->count());
    }

    /* ---------------------------------------------------------------------
     * Failure isolation · audit invariant "never bubble back"
     * ------------------------------------------------------------------- */

    public function test_factory_failure_returns_stub_envelope_and_logs_without_throwing(): void
    {
        Log::spy();
        config()->set('atlas_ai.kernel_http_integration.enabled', true);

        // Force a controlled failure INSIDE the bridge's try/catch by
        // binding a fake MissionFactoryService that throws. All canonical
        // tables remain present so `tablesAvailable()` passes — proving the
        // try/catch isolates real runtime failures from the gateway path.
        $this->app->bind(MissionFactoryService::class, function () {
            return new class extends MissionFactoryService {
                public function __construct()
                {
                    // skip parent constructor; this stub never persists
                }

                public function classify(string $rawPrompt): string
                {
                    return parent::TYPE_MISSION;
                }

                public function create(string $rawPrompt, array $options = []): \App\Models\AiMission
                {
                    throw new \RuntimeException('synthetic_factory_failure');
                }
            };
        });

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope(
            'Implementar refactor enorme da autenticacao com SDD',
        );

        $this->assertIsArray($envelope);
        $this->assertSame(AiGatewayMissionBridge::ENVELOPE_SCHEMA, $envelope['schema']);
        $this->assertSame('bridge_error', $envelope['mission_status']);
        $this->assertArrayHasKey('kernel_bridge_error', $envelope);
        $this->assertSame('legacy_path', $envelope['kernel_bridge_error']['fallback']);
        $this->assertSame('synthetic_factory_failure', $envelope['kernel_bridge_error']['reason']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg): bool => $msg === 'atlas.ai_gateway.mission_bridge.failed')
            ->atLeast()->once();
    }

    public function test_decompose_failure_keeps_mission_id_and_marks_envelope_with_error(): void
    {
        Log::spy();
        config()->set('atlas_ai.kernel_http_integration.enabled', true);

        // Force decomposition to fail by re-creating ai_objectives without
        // the `outcome` column the decomposer writes to. The factory still
        // creates the mission row, but decomposition throws — the bridge
        // must catch it and keep the envelope linked to the mission.
        Schema::dropIfExists('ai_objectives');
        Schema::create('ai_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 64)->unique();
            // intentionally missing required columns → factory write fails
            $table->timestamps();
        });

        $envelope = app(AiGatewayMissionBridge::class)->buildEnvelope(
            'Implementar refactor da autenticacao para multiplos provedores',
        );

        $this->assertIsArray($envelope);
        $this->assertNotNull($envelope['mission_id']);
        $this->assertArrayHasKey('decompose_error', $envelope);
        $this->assertNotEmpty($envelope['decompose_error']);
        // Status stays at draft (transition never happened).
        $this->assertSame(MissionLifecycleService::STATUS_DRAFT, $envelope['mission_status']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg): bool => $msg === 'atlas.ai_gateway.mission_bridge.decompose_failed')
            ->atLeast()->once();
    }

    /* ---------------------------------------------------------------------
     * Envelope reverse lookup
     * ------------------------------------------------------------------- */

    public function test_find_mission_for_envelope_resolves_the_recorded_row(): void
    {
        config()->set('atlas_ai.kernel_http_integration.enabled', true);
        $bridge = app(AiGatewayMissionBridge::class);

        $envelope = $bridge->buildEnvelope('Implementar refactor');

        $mission = $bridge->findMissionForEnvelope($envelope);
        $this->assertNotNull($mission);
        $this->assertSame($envelope['mission_id'], $mission->id);

        $this->assertNull($bridge->findMissionForEnvelope(null));
        $this->assertNull($bridge->findMissionForEnvelope(['mission_id' => null]));
    }

    /* ---------------------------------------------------------------------
     * Sanity: bridge wired into AiGatewayService DI
     * ------------------------------------------------------------------- */

    public function test_ai_gateway_service_resolves_with_bridge_dependency_injected(): void
    {
        // Smoke: if the bridge is not in the constructor, the container
        // throws when resolving. This guards Phase 1's wire from regression.
        $gateway = app(\App\Services\Ai\AiGatewayService::class);
        $this->assertNotNull($gateway);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * Cheap accessor for the decomposer service so tests don't need to
     * resolve a full Mission lifecycle stack.
     */
    private function decomposer(): ObjectiveDecomposerService
    {
        return app(ObjectiveDecomposerService::class);
    }
}
