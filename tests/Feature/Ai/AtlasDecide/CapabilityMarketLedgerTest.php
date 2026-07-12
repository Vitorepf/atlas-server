<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AtlasDecide\CapabilityMarketClearingService;
use App\Services\Ai\AtlasDecide\CapabilityMarketRequest;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CapabilityMarketLedgerTest extends TestCase
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

    public function test_market_clear_persists_one_replayable_decision_receipt(): void
    {
        $request = CapabilityMarketRequest::fromArray([
            'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'authority_hash' => str_repeat('c', 64),
            'risk_class' => 'R3', 'required_capabilities' => ['php'], 'topology' => 'single',
        ]);
        $routes = [[
            'id' => 'kernel', 'capabilities' => ['php'], 'authority_status' => 'active', 'allowed' => true,
            'quality_status' => 'proven', 'quality_hash' => str_repeat('d', 64), 'verifier_families' => ['a', 'b'],
            'available' => true, 'estimated_time_ms' => 10, 'estimated_cost' => 1.0, 'provider_version' => 'v1',
            'supported_risk_classes' => ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'],
            'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'authority_hash' => str_repeat('c', 64),
            'quality_evidence' => ['capability' => 'php', 'risk_class' => 'R3', 'observation_window' => '7d'],
        ]];
        $service = new CapabilityMarketClearingService(app(AtlasEvidenceLedger::class));

        $first = $service->clear($request, $routes);
        $second = $service->clear($request, $routes);

        self::assertSame($first->decisionHash, $second->decisionHash);
        self::assertSame(['quality:'.str_repeat('d', 64)], $first->evidenceRefs);
        self::assertSame(['kernel' => true], $first->availability);
        self::assertSame(10, $first->estimatedTimeMs);
        self::assertSame(1.0, $first->estimatedCost);
        self::assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::DecisionIssued->value)->count());
        self::assertSame('capability.market.cleared', AtlasLedgerEvent::query()->firstOrFail()->payload['event_name']);
    }

    public function test_kernel_execution_order_carries_the_cleared_market_decision_hash(): void
    {
        $request = CapabilityMarketRequest::fromArray([
            'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'authority_hash' => str_repeat('c', 64),
            'risk_class' => 'R3', 'required_capabilities' => ['php'], 'topology' => 'single',
        ]);
        $route = [
            'id' => 'kernel', 'capabilities' => ['php'], 'authority_status' => 'active', 'allowed' => true,
            'quality_status' => 'proven', 'quality_hash' => str_repeat('d', 64), 'verifier_families' => ['a', 'b'],
            'available' => true, 'estimated_time_ms' => 10, 'estimated_cost' => 1.0, 'provider_version' => 'v1',
            'supported_risk_classes' => ['R3'], 'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64),
            'authority_hash' => str_repeat('c', 64), 'quality_evidence' => ['capability' => 'php', 'risk_class' => 'R3', 'observation_window' => '7d'],
        ];
        $decision = (new CapabilityMarketClearingService)->clear($request, [$route]);
        $roles = array_fill_keys(EngineeringRoleRoster::OFFICIAL_ROLES, ['depth' => 'standard', 'independent' => true]);

        $order = ExecutionOrder::fromArray([
            'schema_version' => 'atlas.execution_order.v2', 'run_id' => 'run', 'delivery_id' => 'delivery', 'mode' => 'dev',
            'risk_class' => 'R3', 'complexity_band' => 'C2', 'duration_regime' => 'interactive', 'work_topology' => 'single',
            'product_intent_verdict_hash' => str_repeat('e', 64), 'spec_hash' => str_repeat('f', 64), 'world_model_snapshot_hash' => str_repeat('b', 64),
            'workspace' => 'atlas-server', 'base_commit' => str_repeat('a', 40), 'allowed_scope' => ['app'], 'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'shared', 'authority_hash' => str_repeat('c', 64)], 'decision_receipt' => ['decision_event_id' => 'decision'],
            'operator_contract' => ['presence' => 'confirmed'], 'role_roster' => $roles, 'provider_route' => ['provider' => 'shared', 'model' => 'quality'],
            'tool_permissions' => ['read' => true, 'mutate' => false], 'evidence_policy' => ['acceptance_event_id' => 'acceptance', 'role_disposition_event_ids' => array_fill_keys(array_keys($roles), 'role-event')],
            'release_policy' => ['kind' => 'shared'], 'rollback_policy' => ['kind' => 'shared'], 'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment', 'idempotency_key' => 'idempotency', 'budget_posture' => 'unbounded_quality_first',
            'market_decision_hash' => $decision->decisionHash,
        ]);

        self::assertSame($decision->decisionHash, $order->marketDecisionHash);
    }
}
