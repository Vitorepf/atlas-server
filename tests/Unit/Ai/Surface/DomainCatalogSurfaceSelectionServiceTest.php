<?php

namespace Tests\Unit\Ai\Surface;

use App\Services\Ai\Surface\DomainCatalogSurfaceSelectionService;
use Tests\TestCase;

class DomainCatalogSurfaceSelectionServiceTest extends TestCase
{
    public function test_maps_programming_debug_to_repair_flow_with_surface_payload_patch(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_app',
            'mode' => 'programming',
            'task' => 'debug',
            'routing_domain' => 'blackink',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('ux_mapping', $selection['selection_source']);
        $this->assertFalse($selection['operator_override']);
        $this->assertSame('blackink', data_get($selection, 'ux.product_domain'));
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('ready', data_get($selection, 'domain.onboarding.status'));
        $this->assertSame('programming.repair', data_get($selection, 'flow.id'));
        $this->assertSame('dev_repair_executor', data_get($selection, 'flow.executor_preference'));
        $this->assertSame('medium', data_get($selection, 'flow.autonomy'));
        $this->assertTrue(data_get($selection, 'safety.ready'));
        $this->assertTrue(data_get($selection, 'safety.destructive_requires_approval'));
        $this->assertSame([
            'domain_id' => 'programming',
            'flow_id' => 'programming.repair',
            'surface_id' => 'atlas_app',
            'catalog_schema_version' => 1,
            'selection_source' => 'ux_mapping',
            'product_domain' => 'blackink',
        ], $selection['payload_patch']);
    }

    public function test_explicit_domain_uses_catalog_default_flow_and_keeps_provider_out_of_executor_selection(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli',
            'mode' => 'general',
            'task' => 'direct',
            'domain_id' => 'marketing',
            'provider' => 'codex_cli',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('explicit_domain', $selection['selection_source']);
        $this->assertTrue($selection['operator_override']);
        $this->assertSame('marketing', data_get($selection, 'domain.id'));
        $this->assertSame('marketing.campaign', data_get($selection, 'flow.id'));
        $this->assertSame('domain_runtime', data_get($selection, 'flow.executor_preference'));
        $this->assertSame('marketing.campaign', data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_explicit_flow_wins_over_ux_mapping(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli',
            'mode' => 'programming',
            'task' => 'dev',
            'flow_id' => 'programming.forge',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.forge', data_get($selection, 'flow.id'));
        $this->assertSame('engineering_harness', data_get($selection, 'flow.executor_preference'));
        $this->assertSame('high', data_get($selection, 'flow.autonomy'));
    }

    public function test_unknown_explicit_flow_returns_unresolved_selection_without_fallback_magic(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_mcp_readonly',
            'mode' => 'programming',
            'task' => 'dev',
            'flow_id' => 'programming.unknown',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertTrue($selection['operator_override']);
        $this->assertSame('programming.unknown', data_get($selection, 'requested.flow_id'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
        $this->assertSame('unresolved', data_get($selection, 'payload_patch.selection_source'));
    }

    private function service(): DomainCatalogSurfaceSelectionService
    {
        return $this->app->make(DomainCatalogSurfaceSelectionService::class);
    }
}
