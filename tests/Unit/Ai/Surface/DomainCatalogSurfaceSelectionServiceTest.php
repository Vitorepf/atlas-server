<?php

namespace Tests\Unit\Ai\Surface;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Surface\DomainCatalogSurfaceSelectionService;
use Mockery;
use Tests\TestCase;

class DomainCatalogSurfaceSelectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $catalog = Mockery::mock(AtlasAiDomainCatalogService::class);
        $catalog->shouldReceive('inspect')->andReturn($this->catalogFixture());
        $this->app->instance(AtlasAiDomainCatalogService::class, $catalog);
    }

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
            'surface_id' => 'atlas_api_interaction',
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
            'surface_id' => 'atlas_cli_forge',
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

    public function test_legacy_surface_aliases_resolve_to_formal_adapters(): void
    {
        $apiSelection = $this->service()->select([
            'surface_id' => 'atlas_api',
            'domain_id' => 'programming',
            'flow_id' => 'programming.review',
        ]);

        $this->assertSame('ok', $apiSelection['status']);
        $this->assertSame('atlas_api_interaction', $apiSelection['surface_id']);
        $this->assertTrue(data_get($apiSelection, 'surface_hints.registered'));
        $this->assertContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, data_get($apiSelection, 'surface_hints.supported_capabilities'));

        $cliSelection = $this->service()->select([
            'surface_id' => 'atlas_cli',
            'mode' => 'programming',
            'task' => 'debug',
        ]);

        $this->assertSame('ok', $cliSelection['status']);
        $this->assertSame('atlas_cli_dev', $cliSelection['surface_id']);
        $this->assertTrue(data_get($cliSelection, 'surface_hints.registered'));
        $this->assertSame('programming.repair', data_get($cliSelection, 'flow.id'));
    }

    public function test_legacy_cli_alias_does_not_bypass_formal_adapter_flow_limits(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli',
            'mode' => 'programming',
            'task' => 'dev',
            'flow_id' => 'programming.forge',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('atlas_cli_dev', $selection['surface_id']);
        $this->assertSame('surface_flow_not_supported', data_get($selection, 'error.code'));
        $this->assertTrue(data_get($selection, 'surface_hints.registered'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_atlas_cli_dev_resolves_programming_tasks_from_catalog(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli_dev',
            'mode' => 'programming',
            'task' => 'debug',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('atlas_cli_dev', $selection['surface_id']);
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.repair', data_get($selection, 'flow.id'));
        $this->assertSame('dev_repair_executor', data_get($selection, 'flow.executor_preference'));
        $this->assertSame('medium', data_get($selection, 'safety.autonomy'));
        $this->assertSame(true, data_get($selection, 'surface_hints.registered'));
        $this->assertContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, data_get($selection, 'surface_hints.supported_capabilities'));
        $this->assertContains(SurfaceCapability::WORKSPACE_CONTEXT, data_get($selection, 'surface_hints.supported_capabilities'));
        $this->assertContains('programming.repair', data_get($selection, 'surface_hints.supported_flow_ids'));
    }

    public function test_atlas_cli_forge_resolves_heavy_programming_to_forge_flow(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli_forge',
            'mode' => 'programming',
            'task' => 'heavy',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('atlas_cli_forge', $selection['surface_id']);
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.forge', data_get($selection, 'flow.id'));
        $this->assertSame('engineering_harness', data_get($selection, 'flow.executor_preference'));
        $this->assertSame('high', data_get($selection, 'safety.autonomy'));
        $this->assertTrue(data_get($selection, 'safety.destructive_requires_approval'));
        $this->assertSame('programming.forge', data_get($selection, 'surface_hints.task_flow_map.heavy'));
    }

    public function test_atlas_code_is_forge_only_programming_surface(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_code',
            'mode' => 'forge',
            'task' => 'direct',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('atlas_code', $selection['surface_id']);
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.forge', data_get($selection, 'flow.id'));
        $this->assertSame('engineering_harness', data_get($selection, 'flow.executor_preference'));
        $this->assertSame(['programming.forge'], data_get($selection, 'surface_hints.supported_flow_ids'));
        $this->assertSame('programming.forge', data_get($selection, 'surface_hints.task_flow_map.review'));
    }

    public function test_app_and_api_surfaces_accept_catalog_domain_flow_selection_without_catalog_duplication(): void
    {
        foreach (['atlas_app', 'atlas_api_interaction'] as $surfaceId) {
            $selection = $this->service()->select([
                'surface_id' => $surfaceId,
                'domain_id' => 'programming',
                'flow_id' => 'programming.review',
                'mode' => 'general',
                'task' => 'direct',
            ]);

            $this->assertSame('ok', $selection['status']);
            $this->assertSame($surfaceId, $selection['surface_id']);
            $this->assertSame('explicit_flow', $selection['selection_source']);
            $this->assertSame('programming', data_get($selection, 'domain.id'));
            $this->assertSame('programming.review', data_get($selection, 'flow.id'));
            $this->assertTrue(data_get($selection, 'surface_hints.accepts_explicit_domain_flow_selection'));
        }
    }

    public function test_voice_alias_accepts_explicit_domain_flow_selection_without_tool_runtime(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'voice',
            'domain_id' => 'programming',
            'flow_id' => 'programming.review',
            'task' => 'review',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('voice_realtime', $selection['surface_id']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.review', data_get($selection, 'flow.id'));
        $this->assertTrue(data_get($selection, 'surface_hints.accepts_explicit_domain_flow_selection'));
        $this->assertContains('programming.review', data_get($selection, 'surface_hints.supported_flow_ids'));
        $this->assertSame('programming.review', data_get($selection, 'surface_hints.task_flow_map.review'));
    }

    public function test_domain_catalog_selection_envelope_can_drive_selection_without_flat_fields(): void
    {
        $selection = $this->service()->select([
            'domain_catalog_selection' => [
                'surface_id' => 'atlas_app',
                'ux' => [
                    'mode' => 'programming',
                    'task' => 'review',
                    'product_domain' => 'blackink',
                ],
                'domain' => ['id' => 'programming'],
                'flow' => ['id' => 'programming.review'],
            ],
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('atlas_app', $selection['surface_id']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertTrue($selection['operator_override']);
        $this->assertSame('blackink', data_get($selection, 'ux.product_domain'));
        $this->assertSame('programming', data_get($selection, 'domain.id'));
        $this->assertSame('programming.review', data_get($selection, 'flow.id'));
    }

    public function test_flat_domain_flow_fields_win_over_stale_selection_envelope(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_app',
            'domain_id' => 'programming',
            'flow_id' => 'programming.repair',
            'domain_catalog_selection' => [
                'surface_id' => 'atlas_app',
                'ux' => [
                    'mode' => 'programming',
                    'task' => 'review',
                    'product_domain' => 'blackink',
                ],
                'domain' => ['id' => 'programming'],
                'flow' => ['id' => 'programming.review'],
            ],
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('programming.repair', data_get($selection, 'flow.id'));
        $this->assertSame('programming.repair', data_get($selection, 'payload_patch.flow_id'));
        $this->assertSame('blackink', data_get($selection, 'payload_patch.product_domain'));
    }

    public function test_unknown_explicit_flow_returns_unresolved_selection_without_fallback_magic(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_unknown_surface',
            'mode' => 'programming',
            'task' => 'dev',
            'flow_id' => 'programming.unknown',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertTrue($selection['operator_override']);
        $this->assertSame('programming.unknown', data_get($selection, 'requested.flow_id'));
        $this->assertSame('flow_not_found', data_get($selection, 'error.code'));
        $this->assertSame('Flow [programming.unknown] does not exist in the Atlas AI domain catalog.', data_get($selection, 'error.message'));
        $this->assertFalse(data_get($selection, 'surface_hints.registered'));
        $this->assertSame([], data_get($selection, 'surface_hints.supported_capabilities'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
        $this->assertSame('unresolved', data_get($selection, 'payload_patch.selection_source'));
    }

    public function test_unknown_explicit_domain_returns_unresolved_selection_without_fallback_magic(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_app',
            'mode' => 'programming',
            'task' => 'dev',
            'domain_id' => 'unknown_domain',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_domain', $selection['selection_source']);
        $this->assertSame('domain_not_found', data_get($selection, 'error.code'));
        $this->assertSame('Domain [unknown_domain] does not exist in the Atlas AI domain catalog.', data_get($selection, 'error.message'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_registered_surface_without_domain_flow_capability_rejects_explicit_selection(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli_chat',
            'domain_id' => 'programming',
            'flow_id' => 'programming.review',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('surface_domain_flow_selection_not_supported', data_get($selection, 'error.code'));
        $this->assertSame('programming', data_get($selection, 'requested.domain_id'));
        $this->assertSame('programming.review', data_get($selection, 'requested.flow_id'));
        $this->assertTrue(data_get($selection, 'surface_hints.registered'));
        $this->assertFalse(data_get($selection, 'surface_hints.accepts_explicit_domain_flow_selection'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_surface_supported_flow_list_blocks_catalog_flow_not_declared_by_surface(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli_dev',
            'mode' => 'programming',
            'task' => 'dev',
            'flow_id' => 'programming.forge',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('surface_flow_not_supported', data_get($selection, 'error.code'));
        $this->assertSame('programming.forge', data_get($selection, 'requested.flow_id'));
        $this->assertSame('atlas_cli_dev', data_get($selection, 'surface_hints.surface_id'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_surface_supported_domain_list_blocks_catalog_domain_not_declared_by_surface(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_cli_dev',
            'domain_id' => 'marketing',
        ]);

        $this->assertSame('unresolved', $selection['status']);
        $this->assertSame('explicit_domain', $selection['selection_source']);
        $this->assertSame('surface_domain_not_supported', data_get($selection, 'error.code'));
        $this->assertSame('marketing', data_get($selection, 'requested.domain_id'));
        $this->assertSame('atlas_cli_dev', data_get($selection, 'surface_hints.surface_id'));
        $this->assertNull(data_get($selection, 'payload_patch.domain_id'));
        $this->assertNull(data_get($selection, 'payload_patch.flow_id'));
    }

    public function test_catalog_accepting_surface_allows_catalog_flow_without_local_supported_list(): void
    {
        $selection = $this->service()->select([
            'surface_id' => 'atlas_app',
            'domain_id' => 'marketing',
            'flow_id' => 'marketing.campaign',
        ]);

        $this->assertSame('ok', $selection['status']);
        $this->assertSame('explicit_flow', $selection['selection_source']);
        $this->assertSame('marketing', data_get($selection, 'domain.id'));
        $this->assertSame('marketing.campaign', data_get($selection, 'flow.id'));
        $this->assertSame('domain_runtime', data_get($selection, 'flow.executor_preference'));
    }

    private function service(): DomainCatalogSurfaceSelectionService
    {
        return $this->app->make(DomainCatalogSurfaceSelectionService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function catalogFixture(): array
    {
        return [
            'schema_version' => 1,
            'source' => 'test_catalog_fixture',
            'status' => 'ok',
            'validation' => ['valid' => true],
            'domains' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'default_flow' => 'general.answer',
                    'orchestrator_maturity' => 'implemented',
                    'runtime_family' => 'general',
                    'autonomy_default' => 'low',
                    'background_allowed' => false,
                    'onboarding' => ['status' => 'ready'],
                ],
                [
                    'id' => 'programming',
                    'label' => 'Programming',
                    'default_flow' => 'programming.dev',
                    'orchestrator_maturity' => 'implemented',
                    'runtime_family' => 'programming',
                    'autonomy_default' => 'medium',
                    'background_allowed' => false,
                    'onboarding' => ['status' => 'ready'],
                ],
                [
                    'id' => 'marketing',
                    'label' => 'Marketing',
                    'default_flow' => 'marketing.campaign',
                    'orchestrator_maturity' => 'scaffold',
                    'runtime_family' => 'domain',
                    'autonomy_default' => 'low',
                    'background_allowed' => false,
                    'onboarding' => ['status' => 'ready'],
                ],
            ],
            'flows' => [
                [
                    'id' => 'general.answer',
                    'domain_id' => 'general',
                    'label' => 'Answer',
                    'runtime' => 'general',
                    'orchestrator_maturity' => 'implemented',
                    'autonomy' => 'low',
                    'background_allowed' => false,
                    'destructive_requires_approval' => false,
                    'executor_preference' => 'simple_provider_execution',
                ],
                [
                    'id' => 'programming.dev',
                    'domain_id' => 'programming',
                    'label' => 'Dev',
                    'runtime' => 'programming',
                    'orchestrator_maturity' => 'implemented',
                    'autonomy' => 'medium',
                    'background_allowed' => false,
                    'destructive_requires_approval' => true,
                    'executor_preference' => 'dev_executor',
                ],
                [
                    'id' => 'programming.review',
                    'domain_id' => 'programming',
                    'label' => 'Review',
                    'runtime' => 'programming',
                    'orchestrator_maturity' => 'implemented',
                    'autonomy' => 'low',
                    'background_allowed' => false,
                    'destructive_requires_approval' => false,
                    'executor_preference' => 'engineering_harness',
                ],
                [
                    'id' => 'programming.repair',
                    'domain_id' => 'programming',
                    'label' => 'Repair',
                    'runtime' => 'programming',
                    'orchestrator_maturity' => 'implemented',
                    'autonomy' => 'medium',
                    'background_allowed' => false,
                    'destructive_requires_approval' => true,
                    'executor_preference' => 'dev_repair_executor',
                ],
                [
                    'id' => 'programming.forge',
                    'domain_id' => 'programming',
                    'label' => 'Forge',
                    'runtime' => 'programming',
                    'orchestrator_maturity' => 'implemented',
                    'autonomy' => 'high',
                    'background_allowed' => false,
                    'destructive_requires_approval' => true,
                    'executor_preference' => 'engineering_harness',
                ],
                [
                    'id' => 'marketing.campaign',
                    'domain_id' => 'marketing',
                    'label' => 'Campaign',
                    'runtime' => 'domain',
                    'orchestrator_maturity' => 'scaffold',
                    'autonomy' => 'low',
                    'background_allowed' => false,
                    'destructive_requires_approval' => false,
                    'executor_preference' => 'domain_runtime',
                ],
            ],
        ];
    }
}
