<?php

namespace Tests\Feature\Ai\DualCore;

use App\Models\AiDualCoreRouteDecision;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\DualCore\DualCoreRouteDecisionException;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use Tests\Concerns\CreatesDualCoreRouteDecisionTables;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class DualCoreRouteDecisionServiceTest extends TestCase
{
    use CreatesDualCoreRouteDecisionTables;
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDualCoreRouteDecisionTables();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        $this->dropDualCoreRouteDecisionTables();
        parent::tearDown();
    }

    public function test_records_dev_route_decision(): void
    {
        $decision = app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'small local bug fix in /healthz',
            'corrigir 404 retornado pelo endpoint /healthz quando memcached cai',
            [
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_LOW,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_MINUTES,
                'modules_touched_estimate' => 1,
                'routing_signals' => ['intent_type' => 'programming', 'matched_keywords' => ['corrigir', 'endpoint']],
                'confidence' => 0.91,
            ],
        );

        $this->assertSame(DualCoreRouteDecisionCanon::SCHEMA_VERSION, $decision->schema_version);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV, $decision->route);
        $this->assertFalse($decision->sdd_required);
        $this->assertSame(['plan', 'receipt', 'verification'], $decision->evidence_required);
        $this->assertTrue($decision->operator_visible);
        $this->assertSame(64, strlen($decision->decision_hash));
        $this->assertSame('system', $decision->actor_type);
        $this->assertEqualsWithDelta(0.91, (float) $decision->confidence, 0.0001);
    }

    public function test_records_forge_route_decision_with_work_order_and_evidence_refs(): void
    {
        $workOrderId = $this->fakeUuid();

        $decision = app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_FORGE,
            'obra de reescrita do provider router com sdd + multiagente',
            'reescrever provider router para suportar multi-provider com fallback governado',
            [
                'work_order_id' => $workOrderId,
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_HIGH,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_CRITICAL,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_WEEKS,
                'modules_touched_estimate' => 12,
                'sdd_required' => true,
                'rejected_routes' => [
                    ['route' => DualCoreRouteDecisionCanon::ROUTE_DEV, 'reason' => 'scope_too_large'],
                ],
                'evidence_refs' => [
                    'sdd_intake' => 'storage/atlas-forge/sdd/abc.json',
                    'risk_register' => 'storage/atlas-forge/risk/abc.json',
                ],
                'policy_refs' => ['policy_profile_id' => 'programming.forge.governed'],
                'policy_snapshot' => ['execution_policy' => ['harness_required' => true]],
                'actor_type' => 'router_runtime_adapter',
            ],
        );

        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_FORGE, $decision->route);
        $this->assertSame($workOrderId, $decision->work_order_id);
        $this->assertTrue($decision->sdd_required);
        $this->assertSame(['sdd', 'plan', 'work_packets', 'receipt', 'verification', 'evidence_pack'], $decision->evidence_required);
        $this->assertNotNull($decision->evidence_refs);
        $this->assertArrayHasKey('sdd_intake', (array) $decision->evidence_refs);
        $this->assertSame('programming.forge.governed', $decision->policy_refs['policy_profile_id']);
        $this->assertCount(1, $decision->rejected_routes);
        $this->assertSame('scope_too_large', $decision->rejected_routes[0]['reason']);
        $this->assertSame('router_runtime_adapter', $decision->actor_type);
    }

    public function test_records_dev_to_forge_escalation_decision(): void
    {
        $missionId = $this->fakeUuid();

        $decision = app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE,
            'dev started; scope expanded to multi-module rewrite mid-run',
            'patch inicial em billing engine virou refactor cross-module em 6 services',
            [
                'mission_id' => $missionId,
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_MEDIUM,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_HIGH,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_DAYS,
                'modules_touched_estimate' => 6,
                'rejected_routes' => [
                    ['route' => DualCoreRouteDecisionCanon::ROUTE_DEV, 'reason' => 'scope_expanded_mid_run'],
                ],
                'evidence_refs' => [
                    'plan' => 'storage/atlas-dev/plan/xyz.json',
                    'senior_loop_audit' => 'storage/atlas-dev/audit/xyz.json',
                ],
            ],
        );

        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, $decision->route);
        $this->assertSame($missionId, $decision->mission_id);
        $this->assertTrue($decision->sdd_required, 'dev_to_forge defaults to sdd_required=true (post-escalation)');
        $this->assertSame(['plan', 'receipt', 'escalation_packet'], $decision->evidence_required);
        $this->assertNotEmpty($decision->rejected_routes);
        $this->assertSame('scope_expanded_mid_run', $decision->rejected_routes[0]['reason']);
        $this->assertArrayHasKey('plan', (array) $decision->evidence_refs);
        $this->assertArrayHasKey('senior_loop_audit', (array) $decision->evidence_refs);
    }

    public function test_evidence_refs_persist_as_array_and_are_queryable(): void
    {
        $service = app(DualCoreRouteDecisionService::class);

        $service->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'simple receipt verification',
            'apenas verificar receipt cardinality',
            ['evidence_refs' => ['plan' => 'p1', 'verification_receipt' => 'v1']],
        );

        $persisted = AiDualCoreRouteDecision::query()
            ->where('route', DualCoreRouteDecisionCanon::ROUTE_DEV)
            ->firstOrFail();

        $this->assertIsArray($persisted->evidence_refs);
        $this->assertSame('p1', $persisted->evidence_refs['plan']);
        $this->assertSame('v1', $persisted->evidence_refs['verification_receipt']);
    }

    public function test_decision_hash_is_deterministic_for_equivalent_inputs(): void
    {
        $service = app(DualCoreRouteDecisionService::class);

        $first = $service->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'identical reason',
            'identical intent summary',
            [
                'uuid' => 'fixed-uuid-aaaa-bbbb-cccc-ddddeeee0001',
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_LOW,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_MINUTES,
                'modules_touched_estimate' => 1,
                'routing_signals' => ['k' => 'v'],
            ],
        );

        $expectedHash = MissionCanonicalHash::sha256([
            'schema' => DualCoreRouteDecisionCanon::SCHEMA_VERSION,
            'uuid' => 'fixed-uuid-aaaa-bbbb-cccc-ddddeeee0001',
            'route' => DualCoreRouteDecisionCanon::ROUTE_DEV,
            'reason' => 'identical reason',
            'intent_summary' => 'identical intent summary',
            'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
            'risk_level' => DualCoreRouteDecisionCanon::RISK_LOW,
            'expected_duration' => DualCoreRouteDecisionCanon::DURATION_MINUTES,
            'modules_touched_estimate' => 1,
            'sdd_required' => false,
            'evidence_required' => ['plan', 'receipt', 'verification'],
            'operator_visible' => true,
            'rejected_routes' => [],
            'routing_signals' => ['k' => 'v'],
            'confidence' => null,
            'mission_id' => null,
            'work_order_id' => null,
            'router_decision_id' => null,
            'intent_classification_id' => null,
            'conversation_id' => null,
            'evidence_refs' => null,
            'policy_refs' => null,
            'policy_snapshot' => null,
            'actor_type' => 'system',
        ]);

        $this->assertSame($expectedHash, $first->decision_hash);

        $reason = $this->reasonForDifferentHash($first);

        $different = $service->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'different reason',
            'identical intent summary',
            [
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_LOW,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_MINUTES,
                'modules_touched_estimate' => 1,
                'routing_signals' => ['k' => 'v'],
            ],
        );
        $this->assertNotSame($first->decision_hash, $different->decision_hash, $reason);
    }

    public function test_invalid_route_throws_dual_core_route_decision_exception(): void
    {
        $this->expectException(DualCoreRouteDecisionException::class);
        $this->expectExceptionMessage('Invalid dual_core route [super_forge]');

        app(DualCoreRouteDecisionService::class)->record(
            'super_forge',
            'irrelevant',
            'irrelevant',
        );
    }

    public function test_invalid_ambiguity_level_throws(): void
    {
        $this->expectException(DualCoreRouteDecisionException::class);
        $this->expectExceptionMessage('Invalid ambiguity_level [ultra]');

        app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'ok',
            'ok',
            ['ambiguity_level' => 'ultra'],
        );
    }

    public function test_invalid_risk_level_throws(): void
    {
        $this->expectException(DualCoreRouteDecisionException::class);
        $this->expectExceptionMessage('Invalid risk_level [apocalyptic]');

        app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_FORGE,
            'ok',
            'ok',
            ['risk_level' => 'apocalyptic'],
        );
    }

    public function test_empty_intent_summary_throws(): void
    {
        $this->expectException(DualCoreRouteDecisionException::class);

        app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'reason ok',
            '   ',
        );
    }

    public function test_to_canonical_array_returns_stable_schema_with_required_canonical_fields(): void
    {
        $decision = app(DualCoreRouteDecisionService::class)->record(
            DualCoreRouteDecisionCanon::ROUTE_DEV,
            'simple reason',
            'simple intent summary',
            [
                'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
                'risk_level' => DualCoreRouteDecisionCanon::RISK_LOW,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_MINUTES,
                'modules_touched_estimate' => 2,
            ],
        );

        $canonical = $decision->toCanonicalArray();

        // Canonical contract from atlas-dual-core-engineering-system.md:247-258
        foreach ([
            'schema',
            'route',
            'reason',
            'intent_summary',
            'ambiguity_level',
            'risk_level',
            'expected_duration',
            'modules_touched_estimate',
            'sdd_required',
            'evidence_required',
            'operator_visible',
        ] as $field) {
            $this->assertArrayHasKey($field, $canonical, "canonical projection missing field [{$field}]");
        }

        $this->assertSame('atlas.dual_core.route_decision.v1', $canonical['schema']);
        $this->assertSame('dev', $canonical['route']);
        $this->assertSame(2, $canonical['modules_touched_estimate']);
        $this->assertFalse($canonical['sdd_required']);
        $this->assertTrue($canonical['operator_visible']);

        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($encoded, 'canonical projection must be JSON encodable');
        $decoded = json_decode((string) $encoded, true);
        $this->assertSame($canonical['decision_hash'], $decoded['decision_hash']);
        $this->assertSame($canonical['evidence_required'], $decoded['evidence_required']);
    }

    public function test_record_from_flow_route_translates_router_runtime_into_dual_core(): void
    {
        $intent = app(IntentKernelService::class)->classify('implementar endpoint POST /reports e teste de regressao');
        $decision = app(DomainRouterService::class)->route($intent);
        $flow = app(FlowRouterService::class)->decideFlow($decision, $intent);

        $dual = app(DualCoreRouteDecisionService::class)
            ->recordFromFlowRoute($flow, $decision, $intent, [
                'risk_level' => DualCoreRouteDecisionCanon::RISK_MEDIUM,
                'expected_duration' => DualCoreRouteDecisionCanon::DURATION_HOURS,
                'modules_touched_estimate' => 2,
            ]);

        $this->assertContains($dual->route, DualCoreRouteDecisionCanon::ROUTES);
        $this->assertSame($decision->id, $dual->router_decision_id);
        $this->assertSame($intent->id, $dual->intent_classification_id);
        $this->assertNotEmpty($dual->routing_signals);
        $this->assertSame($flow->flow_id, $dual->routing_signals['flow_id']);
        $this->assertSame('router_runtime_adapter', $dual->actor_type);
    }

    private function fakeUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    private function reasonForDifferentHash(AiDualCoreRouteDecision $reference): string
    {
        return sprintf(
            'changing reason text should produce a different decision_hash than [%s]',
            $reference->decision_hash,
        );
    }
}
