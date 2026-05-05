<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SurfaceDomainCatalogReadinessTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_catalog_exposes_minimum_fields_for_surface_pickers(): void
    {
        $response = $this->getJson('/ai/domains?flow=programming.repair', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('validation.valid', true)
            ->assertJsonPath('filters.flow', 'programming.repair');

        $domain = $response->json('domains.0');
        $flow = $response->json('flows.0');

        $this->assertSame('programming', $domain['id']);
        $this->assertSame('programming.dev', $domain['default_flow']);
        $this->assertSame('implemented', $domain['orchestrator_maturity']);
        $this->assertSame('medium', $domain['autonomy_default']);
        $this->assertFalse($domain['background_allowed']);
        $this->assertSame('ready', data_get($domain, 'onboarding.status'));
        $this->assertSame(9, data_get($domain, 'onboarding.completed_count'));
        $this->assertSame([], data_get($domain, 'onboarding.missing_phases'));

        $this->assertSame('programming.repair', $flow['id']);
        $this->assertSame('programming', $flow['domain_id']);
        $this->assertSame('implemented', $flow['orchestrator_maturity']);
        $this->assertSame('medium', $flow['autonomy']);
        $this->assertFalse($flow['background_allowed']);
        $this->assertTrue($flow['destructive_requires_approval']);
        $this->assertSame('dev_repair_executor', $flow['executor_preference']);
    }

    public function test_cli_catalog_json_matches_api_readiness_contract(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--flow' => 'programming.repair',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'validation.valid'));
        $this->assertSame('programming.repair', data_get($payload, 'filters.flow'));
        $this->assertSame('ready', data_get($payload, 'domains.0.onboarding.status'));
        $this->assertSame('medium', data_get($payload, 'domains.0.autonomy_default'));
        $this->assertSame('programming.repair', data_get($payload, 'flows.0.id'));
        $this->assertSame('dev_repair_executor', data_get($payload, 'flows.0.executor_preference'));
        $this->assertTrue(data_get($payload, 'flows.0.destructive_requires_approval'));
    }

    public function test_cli_catalog_can_preview_surface_domain_flow_selection(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--select' => true,
            '--surface' => 'atlas_app',
            '--mode' => 'programming',
            '--task' => 'debug',
            '--routing-domain' => 'blackink',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ok', data_get($payload, 'surface_selection.status'));
        $this->assertSame('atlas_app', data_get($payload, 'surface_selection.surface_id'));
        $this->assertSame('blackink', data_get($payload, 'surface_selection.ux.product_domain'));
        $this->assertSame('programming', data_get($payload, 'surface_selection.domain.id'));
        $this->assertSame('ready', data_get($payload, 'surface_selection.domain.onboarding.status'));
        $this->assertSame('programming.repair', data_get($payload, 'surface_selection.flow.id'));
        $this->assertSame('dev_repair_executor', data_get($payload, 'surface_selection.flow.executor_preference'));
        $this->assertSame('programming.repair', data_get($payload, 'surface_selection.payload_patch.flow_id'));
    }

    public function test_architecture_validation_exposes_onboarding_summary_for_release_gates(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-validate', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'capabilities.valid'));
        $this->assertTrue(data_get($payload, 'domains.valid'));
        $this->assertTrue(data_get($payload, 'orchestrators.valid'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'onboarding.ready_domains'));
        $this->assertSame(0, data_get($payload, 'onboarding.executable_incomplete_domains'));
    }
}
