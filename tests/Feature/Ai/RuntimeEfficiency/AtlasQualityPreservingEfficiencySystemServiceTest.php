<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeEfficiency;

use App\Services\Ai\RuntimeEfficiency\AtlasQualityPreservingEfficiencySystemService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasQualityPreservingEfficiencySystemServiceTest extends TestCase
{
    private const GB = 1073741824;

    public function test_shadow_unifies_areg_compiler_token_economy_and_memory_without_provider_calls(): void
    {
        $payload = app(AtlasQualityPreservingEfficiencySystemService::class)->shadow([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'provider' => 'gpt',
            'risk_level' => 'medium',
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
            'swap_used_bytes' => 0,
            'cpu_load' => 0.20,
            'repeated_tokens' => 1200,
        ]);

        $this->assertSame(AtlasQualityPreservingEfficiencySystemService::SHADOW_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'quality_contract.quality_gate_status'));
        $this->assertSame('ready', data_get($payload, 'runtime_refs.context_cache.status'));
        $this->assertSame('warm', data_get($payload, 'runtime_refs.context_cache.cache_status'));
        $this->assertSame(1.0, data_get($payload, 'quality_contract.must_keep_coverage'));
        $this->assertSame('deep', data_get($payload, 'resource_policy.mode'));
        $this->assertSame(8.0, data_get($payload, 'resource_policy.admitted_ram_gb'));
        $this->assertTrue(data_get($payload, 'resource_policy.heavy_jobs_allowed'));
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.external_execution_performed'));
        $this->assertNotEmpty($payload['shadow_hash']);
    }

    public function test_shadow_blocks_when_must_keep_coverage_is_not_full(): void
    {
        $payload = app(AtlasQualityPreservingEfficiencySystemService::class)->shadow([
            'must_keep_coverage' => 0.99,
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('must_keep_coverage_below_one', data_get($payload, 'quality_contract.blockers'));
        $this->assertSame(false, data_get($payload, 'claim_policy.quality_regression_allowed'));
    }

    public function test_resource_policy_preserves_operator_floor_and_downgrades_under_pressure(): void
    {
        $payload = app(AtlasQualityPreservingEfficiencySystemService::class)->resourcePolicy([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 2 * self::GB,
            'swap_used_bytes' => 9 * self::GB,
            'operator_resource_floor_gb' => 3,
            'requested_ram_gb' => 8,
        ]);

        $this->assertSame(AtlasQualityPreservingEfficiencySystemService::RESOURCE_POLICY_SCHEMA, $payload['schema_version']);
        $this->assertSame('swap_pressure', $payload['mode']);
        $this->assertSame(0.0, $payload['admitted_ram_gb']);
        $this->assertFalse($payload['heavy_jobs_allowed']);
        $this->assertContains('suspend_alve_heavy', $payload['actions']);
    }

    public function test_certification_passes_with_existing_area_six_surfaces(): void
    {
        $payload = app(AtlasQualityPreservingEfficiencySystemService::class)->certify([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
            'swap_used_bytes' => 0,
        ]);

        $this->assertSame(AtlasQualityPreservingEfficiencySystemService::CERTIFICATION_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(0, data_get($payload, 'summary.fail'));
        $this->assertNotEmpty($payload['certification_hash']);
    }

    public function test_command_emits_shadow_and_resources_json(): void
    {
        $shadowExit = Artisan::call('atlas:efficiency', [
            'action' => 'shadow',
            '--available-gb' => '20',
            '--total-gb' => '48',
            '--json' => true,
        ]);
        $shadow = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $shadowExit);
        $this->assertSame(AtlasQualityPreservingEfficiencySystemService::SHADOW_SCHEMA, $shadow['schema_version']);

        $resourceExit = Artisan::call('atlas:efficiency', [
            'action' => 'resources',
            '--available-gb' => '2',
            '--swap-gb' => '9',
            '--json' => true,
        ]);
        $resources = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $resourceExit);
        $this->assertSame(AtlasQualityPreservingEfficiencySystemService::RESOURCE_POLICY_SCHEMA, $resources['schema_version']);
        $this->assertSame('swap_pressure', $resources['mode']);
    }
}
