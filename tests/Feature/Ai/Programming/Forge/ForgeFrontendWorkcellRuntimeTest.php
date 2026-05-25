<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeSpecialistWorkcellRouterService;
use Tests\TestCase;

class ForgeFrontendWorkcellRuntimeTest extends TestCase
{
    public function test_surface_ui_work_packet_receives_atlas_frontend_runtime_contract(): void
    {
        $packet = new AiForgeWorkPacket([
            'packet_id' => 'wp-frontend-001',
            'title' => 'Implement frontend SaaS dashboard',
            'objective' => 'Criar UI multiempresa com design system, live variants e visual smoke',
            'scope' => 'React frontend components',
            'risk_band' => 'high',
        ]);

        $route = app(ForgeSpecialistWorkcellRouterService::class)->route($packet);

        $this->assertSame('surface_ui', $route['workcell']);
        $this->assertSame('atlas.frontend.design_runtime_contract.v1', data_get($route, 'atlas_frontend_runtime.schema_version'));
        $this->assertSame('atlas.frontend.execution_gate.v1', data_get($route, 'atlas_frontend_pre_execution_gate.schema_version'));
        $this->assertSame('blocked', data_get($route, 'atlas_frontend_pre_execution_gate.status'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_pre_execution_gate.claim_policy.provider_dispatch_allowed'));
        $this->assertContains('visual_smoke_multi_viewport', data_get($route, 'atlas_frontend_runtime.required_gates', []));
        $this->assertContains('anti_ai_slop_detector', data_get($route, 'atlas_frontend_runtime.required_capabilities', []));
        $this->assertContains('atlas_frontend_runtime_contract_attached', $route['route_reasons']);
        $this->assertContains('atlas_frontend_pre_execution_gate_attached', $route['route_reasons']);
        $this->assertTrue((bool) $route['requires_human_review']);
    }
}
