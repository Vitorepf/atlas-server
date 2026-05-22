<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\DualCore;

use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiDomainHandoff;
use App\Models\AiDualCoreRouteDecision;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiWorkOrder;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\AtlasForgeHandoffAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingAdapterSmokeService;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainKernelCanon;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainManifestSeeder;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainRuntimeAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingEvidenceBridge;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\ObjectiveRoutingService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\Concerns\CreatesDualCoreRouteDecisionTables;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

/**
 * Atlas AI canonical runtime E2E.
 *
 * Why this test exists. The Atlas critical judgment report
 * (`atlas-architecture-critical-judgment-report.md`) and the AiWorker→Kernel
 * integration ADR (`atlas-aiworker-kernel-integration-adr.md`, status:
 * active / graph_status: active) govern the HTTP path
 * (`AiInteractionController` → `AiGatewayService` → `AiJob` → `AiWorker` →
 * `AiProviderManager`) through the canonical Kernel envelope, PermissionGate,
 * Mission Evidence and certification/completion signals.
 *
 * This test therefore exercises the highest **canonical seam currently
 * implementable**: the Programming Adapter (Meta 7) wired to Router Runtime
 * (Meta 6), DualCore route_decision.v1, Mission Foundation (Meta 1), Domain
 * Runtime (Meta 2), Evidence Runtime (Meta 4) and the `dev_to_forge`
 * escalation packet (Phase 1 of the consolidation plan). It is NOT a unit
 * test of any DTO — it walks a prompt across 7 services and asserts each
 * persisted contract (intent classification, router decision, flow route,
 * dispatch, route_decision.v1, mission, work_order, evidence_ref,
 * certification, escalation_packet.v1, AiDomainHandoff) by schema and
 * receipt hash.
 *
 * The HTTP→AiWorker→Kernel gap is asserted explicitly in
 * {@see self::test_http_to_kernel_integration_remains_a_documented_blocker}
 * so the test surface honestly tracks the ADR until the integration lands.
 *
 * No mocks are used for any canonical contract. Mocks would be permitted
 * only for external provider drivers; the canonical chain runs against the
 * real services backed by in-memory schema traits.
 */
class AtlasCanonicalRuntimeE2ETest extends TestCase
{
    use CreatesDomainRuntimeTables;
    use CreatesDualCoreRouteDecisionTables;
    use CreatesEvidenceRuntimeTables;
    use CreatesMissionFoundationTables;
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createDomainRuntimeTables();
        $this->createRouterRuntimeTables();
        $this->createDualCoreRouteDecisionTables();
        $this->createEvidenceRuntimeTables();
        app(ProgrammingDomainManifestSeeder::class)->seed();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        $this->dropDualCoreRouteDecisionTables();
        $this->dropRouterRuntimeTables();
        $this->dropDomainRuntimeTables();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_dev_prompt_walks_full_canonical_chain_to_passed_certification(): void
    {
        $prompt = 'implemente fixe do bug pequeno no endpoint /healthz com escreva teste de regressao em phpunit';

        // 1. Intent classification (RouterRuntime Meta 6).
        $intent = app(IntentKernelService::class)->classify($prompt);
        $this->assertSame(RouterRuntimeCanon::INTENT_PROGRAMMING, $intent->intent_type);

        // 2. Objective routing (read-only projection for the router decision).
        $objective = app(ObjectiveRoutingService::class)->resolveObjective($intent);
        $this->assertSame('programming', $objective['derived_domain']);

        // 3. Router decision + flow route + dispatch (Meta 6 canonical chain).
        $routerDecision = $this->buildRouterDecision($intent, RouterRuntimeCanon::MODE_STANDARD);
        $flow = app(FlowRouterService::class)->decideFlow($routerDecision, $intent);
        $this->assertSame('atlas_dev', $flow->flow_id);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($routerDecision, $flow, $intent);
        $this->assertNotSame(RouterRuntimeCanon::DISPATCH_BLOCKED, $dispatch->dispatch_status);

        // 4. atlas.dual_core.route_decision.v1 row persisted (Phase 1).
        $route = app(DualCoreRouteDecisionService::class)->recordFromFlowRoute($flow, $routerDecision, $intent);
        $this->assertInstanceOf(AiDualCoreRouteDecision::class, $route);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV, $route->route);
        $this->assertSame(DualCoreRouteDecisionCanon::SCHEMA_VERSION, $route->schema_version);
        $this->assertSame(64, strlen((string) $route->decision_hash));

        // 5. Programming Adapter (Meta 7) turns the prompt into Mission+Objective+WorkOrder.
        $adapted = app(AtlasDevMissionAdapter::class)->adapt($prompt);
        /** @var AiMission $mission */
        $mission = $adapted['mission'];
        /** @var AiWorkOrder $workOrder */
        $workOrder = $adapted['work_orders']->first();
        $this->assertNotNull($mission);
        $this->assertNotNull($workOrder);
        $this->assertSame('programming.repair', $adapted['capability']);
        $this->assertSame(64, strlen((string) $workOrder->receipt_hash));

        // 6. Domain Runtime record (Meta 2) + evidence (Meta 4 bridge into Mission Foundation).
        $runtimeAdapter = app(ProgrammingDomainRuntimeAdapter::class);
        $runtimeRecord = $runtimeAdapter->plan($mission, $workOrder, ['capability' => $adapted['capability']]);
        $this->assertNotNull($runtimeRecord);

        // AtlasDevMissionAdapter::adapt() leaves the mission in `planned`;
        // we only need to advance to `running` here.
        app(MissionLifecycleService::class)->transition($mission, MissionLifecycleService::STATUS_RUNNING);

        app(ProgrammingEvidenceBridge::class)->attach(
            $mission,
            MissionEvidenceService::TYPE_TEST,
            'e2e:canonical:phpunit',
            ['scope' => 'e2e', 'flow_id' => $flow->flow_id],
            $workOrder,
        );
        $this->assertGreaterThan(0, $mission->evidenceRefs()->count(), 'evidence_ref persisted');

        // 7. Certification (Mission Foundation Meta 1 + quality-aware checks).
        app(MissionLifecycleService::class)->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);
        $certReport = $runtimeAdapter->certify($mission, $runtimeRecord);

        $this->assertSame('passed', $certReport['mission_certification_status']);
        $this->assertNotEmpty($certReport['mission_certification_hash']);

        $latestCert = $mission->latestCertification()->first();
        $this->assertInstanceOf(AiMissionCertification::class, $latestCert);
        $this->assertSame(MissionCertificationService::STATUS_PASSED, $latestCert->status);
        $this->assertNotEmpty($latestCert->checked_requirements);

        // Completion guard (Mission Foundation lifecycle) accepts the transition.
        app(MissionLifecycleService::class)->transition($mission, MissionLifecycleService::STATUS_COMPLETED);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->refresh()->status);
        $this->assertNotNull($mission->completed_at);
    }

    public function test_dev_to_forge_prompt_emits_route_decision_v1_and_escalation_packet_v1(): void
    {
        $prompt = 'implemente refactor de obra multi-modulo com sdd e multiagente; codigo do provider router precisa ser reescrito';

        // 1-2. Intent → Router decision in FORGE mode.
        $intent = app(IntentKernelService::class)->classify($prompt);
        $this->assertSame(RouterRuntimeCanon::INTENT_PROGRAMMING, $intent->intent_type);

        $routerDecision = $this->buildRouterDecision($intent, RouterRuntimeCanon::MODE_FORGE);
        $flow = app(FlowRouterService::class)->decideFlow($routerDecision, $intent);
        $this->assertSame('atlas_forge', $flow->flow_id);

        // 3. Route decision v1 — FORGE routing path.
        $route = app(DualCoreRouteDecisionService::class)->recordFromFlowRoute($flow, $routerDecision, $intent);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_FORGE, $route->route);

        // 4. Adapter recognizes obra mission_type and produces the canonical handoff.
        $adapted = app(AtlasDevMissionAdapter::class)->adapt($prompt);
        /** @var AiMission $mission */
        $mission = $adapted['mission'];
        /** @var AiWorkOrder $workOrder */
        $workOrder = $adapted['work_orders']->first();
        $this->assertSame('obra', $mission->mission_type);
        $this->assertSame(64, strlen((string) $workOrder->receipt_hash));

        $forgeAdapter = app(AtlasForgeHandoffAdapter::class);
        $reason = $forgeAdapter->shouldPromote($mission) ?? 'scope_too_large';
        $bundle = $forgeAdapter->promoteWithPacket($mission, $workOrder, $reason, [
            'capability' => $adapted['capability'],
            'workspace' => 'atlas-server',
            'risk_register' => [
                'high_risk_keywords' => ProgrammingDomainKernelCanon::detectHighRiskActions($prompt),
            ],
        ]);

        // 5. Canonical packet v1 asserted by schema, source/target, packet_hash.
        $packet = $bundle['escalation_packet_v1'];
        $this->assertIsArray($packet);
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame(EscalationPacket::SOURCE_CORE, $packet['source_core']);
        $this->assertSame(EscalationPacket::TARGET_CORE, $packet['target_core']);
        $this->assertContains(
            $packet['recommended_forge_mode'],
            EscalationPacket::ALLOWED_RECOMMENDED_FORGE_MODES,
        );
        $this->assertNotEmpty($packet['promotion_triggers']);
        $this->assertSame(64, strlen((string) $packet['packet_hash']));

        // 6. AiDomainHandoff (Meta 2) row persisted.
        $handoff = $bundle['handoff'];
        $this->assertInstanceOf(AiDomainHandoff::class, $handoff);
        $this->assertSame('programming', $handoff->source_domain_id);
        $this->assertSame('programming', $handoff->target_domain_id);
        $this->assertSame(64, strlen((string) $handoff->receipt_hash));

        // 7. atlas.dual_core.route_decision.v1 row persisted by the adapter
        //    with route=dev_to_forge and actor=programming_adapter.
        $adapterRouteMeta = $bundle['route_decision_v1'];
        $this->assertTrue($adapterRouteMeta['recorded']);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, $adapterRouteMeta['route']);
        $row = AiDualCoreRouteDecision::query()->where('uuid', $adapterRouteMeta['uuid'])->first();
        $this->assertNotNull($row);
        $this->assertSame('programming_adapter', (string) $row->actor_type);

        // 8. Mission event carries the packet so Forge consumers can audit.
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
    }

    public function test_programming_adapter_smoke_service_proves_full_canonical_pipeline(): void
    {
        // The canonical end-to-end CLI smoke that lives in
        // ProgrammingAdapterSmokeService::run() walks the same chain this
        // test asserts, plus the Tool bridge advisory + control-plane
        // snapshot. We invoke it here so the smoke entrypoint is gated by
        // CI: any regression in the canonical chain breaks this test.
        $report = app(ProgrammingAdapterSmokeService::class)->run();

        $this->assertTrue($report['ok'], 'smoke pipeline must complete with cert=passed + handoff receipt; got: '.json_encode($report));
        $this->assertSame(
            MissionLifecycleService::STATUS_COMPLETED,
            $report['dev']['mission_status'],
        );
        $this->assertSame('passed', $report['dev']['certification_status']);
        $this->assertNotNull($report['forge']['handoff_receipt_hash']);
        $this->assertSame('programming', $report['manifest']['domain_id']);
    }

    public function test_blocked_router_decision_propagates_to_dispatch_without_creating_mission(): void
    {
        // Honest fallback path: when Router decides BLOCKED, no Dev/Forge
        // execution should happen. The canonical chain MUST surface the
        // blocker explicitly via Dispatch.
        $intent = app(IntentKernelService::class)->classify('xyz random gibberish nonsense');
        $this->assertSame(RouterRuntimeCanon::INTENT_UNKNOWN, $intent->intent_type);

        $routerDecision = $this->buildRouterDecision(
            $intent,
            RouterRuntimeCanon::MODE_BLOCKED,
            ['ambiguity_keywords' => ['clarification_needed:high_ambiguity']],
        );
        $flow = app(FlowRouterService::class)->decideFlow($routerDecision, $intent);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($routerDecision, $flow, $intent);

        $this->assertSame(RouterRuntimeCanon::DISPATCH_BLOCKED, $dispatch->dispatch_status);
        $this->assertIsArray($dispatch->blockers);
        $this->assertContains('routing_mode_blocked', $dispatch->blockers);

        // No mission is created from a blocked dispatch (the adapter is only
        // invoked downstream of a non-blocked decision).
        $this->assertSame(0, AiMission::query()->count());
    }

    public function test_http_to_kernel_integration_is_documented_as_active(): void
    {
        // The ADR is the source of truth for the HTTP→Kernel integration.
        // It must now describe an active path, not preserve the old planned
        // blocker after AiGatewayService/AiWorker started emitting the Kernel
        // envelope, PermissionGate, Mission Evidence and certification state.
        $adrPath = base_path('docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md');
        $this->assertFileExists($adrPath, 'AiWorker→Kernel ADR must exist as canonical authority');
        $contents = (string) file_get_contents($adrPath);

        $this->assertStringContainsString(
            'graph_status: active',
            $contents,
            'ADR must be active after HTTP path integration ships.',
        );
        $this->assertStringContainsString(
            'kernel_mission_completion',
            $contents,
            'ADR must document the AiWorker completion/certification signal.',
        );
    }

    /**
     * Build a router decision row with the canonical shape exposed by
     * `ai_atlas_router_decisions`. Uses MissionCanonicalHash for receipt
     * parity with the production Router Runtime.
     *
     * @param  array<string,mixed>  $extra
     */
    private function buildRouterDecision(
        AiAtlasIntentClassification $intent,
        string $routingMode,
        array $extra = [],
    ): AiAtlasRouterDecision {
        $reasons = array_merge(
            ['intent_type:'.$intent->intent_type, 'routing_mode:'.$routingMode],
            (array) ($extra['ambiguity_keywords'] ?? []),
        );

        return AiAtlasRouterDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => null,
            'work_order_id' => null,
            'intent_classification_id' => $intent->id,
            'primary_domain' => 'programming',
            'secondary_domains' => [],
            'routing_mode' => $routingMode,
            'decision_reason' => ['reasons' => $reasons],
            'policy_required' => true,
            'evidence_required' => true,
            'tool_plan_required' => false,
            'status' => 'routed',
            'receipt_hash' => MissionCanonicalHash::sha256([
                'intent_id' => $intent->id,
                'routing_mode' => $routingMode,
                'reasons' => $reasons,
            ]),
        ]);
    }
}
