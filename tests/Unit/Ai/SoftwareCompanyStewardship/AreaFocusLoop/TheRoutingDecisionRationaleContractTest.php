<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheRoutingDecisionRationaleContract;
use Tests\TestCase;

final class TheRoutingDecisionRationaleContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheRoutingDecisionRationaleContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(TheRoutingDecisionRationaleContract::class));
    }

    public function test_default_shape_declares_routing_decision_rationale_contract(): void
    {
        $shape = TheRoutingDecisionRationaleContract::defaults()->toArray();

        $this->assertSame(TheRoutingDecisionRationaleContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('routing_decision_rationale', $shape['contract_id']);
        $this->assertSame('aaeos_dev_forge_router_decision_rationale_contract', $shape['finding_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
            $shape['runbook_canonical'],
        );
        $this->assertSame(AreaFocusDevForgeRouterService::WORK_ORDER_SCHEMA, $shape['work_order_schema']);
        $this->assertSame(AreaFocusDevForgeRouterService::REPORT_SCHEMA, $shape['report_schema']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'owner' => '',
            'risk' => 'unknown',
            'authority_available' => false,
            'route' => '',
        ], $shape['inputs']);
        $this->assertSame('', $shape['outputs']['owner']);
        $this->assertSame('unknown', $shape['outputs']['risk']);
        $this->assertFalse($shape['outputs']['authority_available']);
        $this->assertSame('', $shape['outputs']['route']);
        $this->assertFalse($shape['outputs']['surfaces_authority_gap']);
        $this->assertFalse($shape['outputs']['routing_rationale_auditable']);
    }

    public function test_from_array_surfaces_atlas_dev_rationale_with_execution_authority(): void
    {
        $shape = TheRoutingDecisionRationaleContract::fromArray([
            'route' => AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV,
            'severity' => 'medium',
            'authority_available' => true,
        ])->toArray();

        $this->assertSame(TheRoutingDecisionRationaleContract::OWNER_ATLAS_DEV, $shape['outputs']['owner']);
        $this->assertSame('medium', $shape['outputs']['risk']);
        $this->assertTrue($shape['outputs']['authority_available']);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, $shape['outputs']['route']);
        $this->assertFalse($shape['outputs']['surfaces_authority_gap']);
        $this->assertTrue($shape['outputs']['routing_rationale_auditable']);
    }

    public function test_from_array_surfaces_authority_gap_when_forge_route_lacks_authority(): void
    {
        $shape = TheRoutingDecisionRationaleContract::fromArray([
            'route_hint' => AreaFocusDevForgeRouterService::ROUTE_FORGE,
            'risk_level' => 'high',
        ])->toArray();

        $this->assertSame(TheRoutingDecisionRationaleContract::OWNER_FORGE, $shape['outputs']['owner']);
        $this->assertSame('high', $shape['outputs']['risk']);
        $this->assertFalse($shape['outputs']['authority_available']);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_FORGE, $shape['outputs']['route']);
        $this->assertTrue($shape['outputs']['surfaces_authority_gap']);
        $this->assertTrue($shape['outputs']['routing_rationale_auditable']);
    }

    public function test_from_array_maps_operator_review_to_operator_owner(): void
    {
        $shape = TheRoutingDecisionRationaleContract::fromArray([
            'route' => AreaFocusDevForgeRouterService::ROUTE_OPERATOR_REVIEW,
            'risk' => 'critical',
        ])->toArray();

        $this->assertSame(TheRoutingDecisionRationaleContract::OWNER_OPERATOR, $shape['outputs']['owner']);
        $this->assertSame('critical', $shape['outputs']['risk']);
        $this->assertFalse($shape['outputs']['authority_available']);
        $this->assertFalse($shape['outputs']['surfaces_authority_gap']);
    }
}
