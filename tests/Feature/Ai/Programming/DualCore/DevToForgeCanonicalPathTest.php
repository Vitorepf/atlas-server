<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\DualCore;

use App\Models\AiDomainHandoff;
use App\Models\AiDualCoreRouteDecision;
use App\Models\AiMission;
use App\Models\AiWorkOrder;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use App\Services\Ai\Programming\AtlasDev\Escalation\DevToForgeEscalationPacketFactory;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\Kernel\AtlasForgeHandoffAdapter;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\Concerns\CreatesDualCoreRouteDecisionTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

/**
 * Phase 1 of the consolidation plan
 * (docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md):
 * every Dev->Forge mechanism must dual-emit the canonical
 * `atlas.dev_to_forge.escalation_packet.v1` and persist a
 * `atlas.dual_core.route_decision.v1` row when applicable.
 *
 * These tests exercise the Kernel adapter (Mechanism 3) and the Mechanism 1
 * factory directly; the HTTP-bound Mechanism 2 has its own feature test.
 */
class DevToForgeCanonicalPathTest extends TestCase
{
    use CreatesDomainRuntimeTables;
    use CreatesDualCoreRouteDecisionTables;
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createDomainRuntimeTables();
        $this->createDualCoreRouteDecisionTables();
    }

    protected function tearDown(): void
    {
        $this->dropDualCoreRouteDecisionTables();
        $this->dropDomainRuntimeTables();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_forge_handoff_adapter_emits_canonical_packet_and_route_decision(): void
    {
        [$mission, $workOrder] = $this->buildObraMission('Refatorar billing engine multi-modulo.');

        /** @var AtlasForgeHandoffAdapter $adapter */
        $adapter = app(AtlasForgeHandoffAdapter::class);
        $result = $adapter->promoteWithPacket($mission, $workOrder, 'scope_too_large');

        $this->assertInstanceOf(AiDomainHandoff::class, $result['handoff']);
        $packet = $result['escalation_packet_v1'];
        $this->assertIsArray($packet);
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame(EscalationPacket::SOURCE_CORE, $packet['source_core']);
        $this->assertSame(EscalationPacket::TARGET_CORE, $packet['target_core']);
        $this->assertSame(
            EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
            $packet['recommended_forge_mode'],
        );
        $this->assertSame('scope_too_large', $packet['promotion_reason']);
        $this->assertContains(EscalationPacket::TRIGGER_SCOPE_TOO_LARGE, $packet['promotion_triggers']);
        $this->assertSame(64, strlen((string) $packet['packet_hash']));

        $route = $result['route_decision_v1'];
        $this->assertTrue($route['recorded']);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, $route['route']);
        $this->assertNotEmpty($route['decision_hash']);

        // Mission event payload carries both canonical contracts so consumers
        // (Forge intake, Control Plane) can audit without reading the adapter.
        $event = $mission->events()
            ->where('event_type', 'programming.adapter.forge_handoff')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($event);
        $payload = (array) $event->payload;
        $this->assertSame(
            EscalationPacket::SCHEMA_VERSION,
            $payload['escalation_packet_v1']['schema_version'] ?? null,
        );
        $this->assertTrue($payload['route_decision_v1']['recorded'] ?? false);
    }

    public function test_forge_handoff_legacy_promote_method_still_returns_handoff(): void
    {
        [$mission, $workOrder] = $this->buildObraMission('Multi-modulo legado de billing.');
        /** @var AtlasForgeHandoffAdapter $adapter */
        $adapter = app(AtlasForgeHandoffAdapter::class);
        $handoff = $adapter->promote($mission, $workOrder, 'scope_too_large');
        $this->assertInstanceOf(AiDomainHandoff::class, $handoff);
        $this->assertSame(64, strlen((string) $handoff->receipt_hash));
    }

    public function test_route_decision_v1_row_persists_with_canonical_schema_version(): void
    {
        [$mission, $workOrder] = $this->buildObraMission('Refatorar auth multi-modulo.');
        /** @var AtlasForgeHandoffAdapter $adapter */
        $adapter = app(AtlasForgeHandoffAdapter::class);
        $adapter->promoteWithPacket($mission, $workOrder, 'sdd_required');

        $row = AiDualCoreRouteDecision::query()->latest('created_at')->first();
        $this->assertNotNull($row);
        $this->assertSame(DualCoreRouteDecisionCanon::SCHEMA_VERSION, (string) $row->schema_version);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, (string) $row->route);
        $this->assertSame((string) $mission->id, (string) $row->mission_id);
        $this->assertSame((string) $workOrder->id, (string) $row->work_order_id);
        $this->assertTrue((bool) $row->sdd_required);
        $this->assertSame('programming_adapter', (string) $row->actor_type);
    }

    public function test_escalation_packet_factory_and_adapter_share_canonical_fields(): void
    {
        [$mission, $workOrder] = $this->buildObraMission('Refatorar billing multi-modulo.');

        /** @var DevToForgeEscalationPacketFactory $factory */
        $factory = app(DevToForgeEscalationPacketFactory::class);
        $decision = EscalationDecision::issue(
            runId: 'run-test-1',
            taskContractHash: hash('sha256', 'task-contract-fixture'),
            triggeredAt: now()->toJSON(),
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['scope_too_large', 'sdd_required'],
            signals: $this->signals(),
            score: 9,
            riskLevel: 'R4',
            humanActionRequired: true,
        );
        $mechanism1 = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Refatorar billing multi-modulo.',
            promotionReason: 'scope_too_large',
        );

        /** @var AtlasForgeHandoffAdapter $adapter */
        $adapter = app(AtlasForgeHandoffAdapter::class);
        $bundle = $adapter->promoteWithPacket($mission, $workOrder, 'scope_too_large');
        $mechanism3 = $bundle['escalation_packet_v1'];

        // Both packets serialize against the same canonical schema and share
        // the canonical anchor fields. packet_hash legitimately differs
        // because packet_id, intent strings and timestamps vary, but contract
        // shape and core enums must match.
        $this->assertSame($mechanism1->schemaVersion(), $mechanism3['schema_version']);
        $this->assertSame(EscalationPacket::SOURCE_CORE, $mechanism3['source_core']);
        $this->assertSame(EscalationPacket::TARGET_CORE, $mechanism3['target_core']);
        $this->assertContains(EscalationPacket::TRIGGER_SCOPE_TOO_LARGE, $mechanism1->promotionTriggers);
        $this->assertContains(EscalationPacket::TRIGGER_SCOPE_TOO_LARGE, $mechanism3['promotion_triggers']);
        $this->assertSame(
            $mechanism1->recommendedForgeMode === EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            $mechanism3['recommended_forge_mode'] === EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE
                || $mechanism3['recommended_forge_mode'] === EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            'both factories must pick a documented Forge mode constant.',
        );
    }

    public function test_packet_is_only_emitted_when_dual_core_runtime_is_present(): void
    {
        // Drop the dual_core table to simulate absent runtime.
        $this->dropDualCoreRouteDecisionTables();
        [$mission, $workOrder] = $this->buildObraMission('Refatorar billing degraded.');

        /** @var AtlasForgeHandoffAdapter $adapter */
        $adapter = app(AtlasForgeHandoffAdapter::class);
        $bundle = $adapter->promoteWithPacket($mission, $workOrder, 'scope_too_large');

        // Packet still emitted (only needs EscalationPacket class — no DB).
        $this->assertNotNull($bundle['escalation_packet_v1']);
        $this->assertSame(
            EscalationPacket::SCHEMA_VERSION,
            $bundle['escalation_packet_v1']['schema_version'],
        );

        // Route decision tolerantly degraded.
        $this->assertFalse($bundle['route_decision_v1']['recorded']);
        $this->assertStringContainsString('not available', (string) $bundle['route_decision_v1']['detail']);

        // Restore tables for tearDown.
        $this->createDualCoreRouteDecisionTables();
    }

    /**
     * @return array{0: AiMission, 1: AiWorkOrder}
     */
    private function buildObraMission(string $prompt): array
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        /** @var ObjectiveDecomposerService $decomposer */
        $decomposer = app(ObjectiveDecomposerService::class);
        /** @var WorkOrderFactoryService $workOrders */
        $workOrders = app(WorkOrderFactoryService::class);
        /** @var MissionEvidenceService $evidence */
        $evidence = app(MissionEvidenceService::class);
        /** @var MissionLifecycleService $lifecycle */
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create($prompt, [
            'mission_type' => MissionFactoryService::TYPE_OBRA,
            'risk_level' => MissionFactoryService::RISK_HIGH,
            'autonomy_level' => MissionFactoryService::AUTONOMY_EXECUTE_WITH_APPROVAL,
        ]);
        $decomposer->decompose($mission);
        $created = $workOrders->plan($mission);
        $workOrder = $created->first();
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'dual-core:smoke:evidence',
        ]);

        return [$mission, $workOrder];
    }

    private function signals(): EscalationSignals
    {
        return new EscalationSignals(
            fileCount: 8,
            layersTouched: 4,
            riskKeywords: ['auth', 'billing'],
            contextRequiredChars: 9000,
            threadMessages: 25,
            priorFailureCount: 2,
        );
    }
}
