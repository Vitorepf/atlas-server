<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCapabilityContract;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolCapabilityContractTest extends TestCase
{
    private AtlasExternalBrainProviderPoolCapabilityContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new AtlasExternalBrainProviderPoolCapabilityContract;
    }

    private function pool(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'cursor_composer',
            'model_family' => 'composer-2.5',
            'cost_tier' => 'subscription',
            'sdk_available' => false,
            'headless_available' => false,
            'supports_patch_generation' => true,
            'supports_task_origination' => false,
            'supports_long_running_goal' => false,
            'quota_pool_policy' => 'shared',
        ], $overrides);
    }

    public function test_returns_schema_and_capability_matrix(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool()]]);

        $this->assertSame(AtlasExternalBrainProviderPoolCapabilityContract::SCHEMA, $result['schema']);
        $this->assertCount(1, $result['capability_matrix']);
        $this->assertSame(1, $result['provider_count']);
    }

    public function test_row_carries_all_input_fields(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'cursor_composer',
            'model_family' => 'composer-2.5',
            'cost_tier' => 'subscription',
            'sdk_available' => true,
            'headless_available' => true,
            'supports_patch_generation' => true,
            'supports_task_origination' => true,
            'supports_long_running_goal' => false,
            'quota_pool_policy' => 'shared',
            'smoke_test_refs' => ['vendor/bin/phpunit smoke'],
        ])]]);

        $row = $result['capability_matrix'][0];
        $this->assertSame('cursor_composer', $row['provider_id']);
        $this->assertSame('composer-2.5', $row['model_family']);
        $this->assertSame('subscription', $row['cost_tier']);
        $this->assertTrue($row['sdk_available']);
        $this->assertTrue($row['headless_available']);
        $this->assertTrue($row['supports_patch_generation']);
        $this->assertTrue($row['supports_task_origination']);
        $this->assertFalse($row['supports_long_running_goal']);
        $this->assertSame('shared', $row['quota_pool_policy']);
        $this->assertSame(['vendor/bin/phpunit smoke'], $row['smoke_test_refs']);
        $this->assertTrue($row['optional_accelerator']);
        $this->assertFalse($row['atlas_required']);
        $this->assertTrue($row['fallback_required']);
        $this->assertSame('production_ready', $row['integration_status']);
        $this->assertSame('muscle_only', $row['capability_level']);
    }

    public function test_every_provider_is_optional_accelerator_not_atlas_required(): void
    {
        $result = $this->contract->describe(['provider_pools' => [
            $this->pool(['provider_id' => 'cursor_composer']),
            $this->pool(['provider_id' => 'codex_cli', 'sdk_available' => true, 'headless_available' => true]),
        ]]);

        foreach ($result['capability_matrix'] as $row) {
            $this->assertTrue($row['optional_accelerator']);
            $this->assertFalse($row['atlas_required']);
            $this->assertTrue($row['fallback_required']);
        }
    }

    public function test_cursor_without_headless_sdk_proof_is_unproven(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'cursor_composer',
            'sdk_available' => false,
            'headless_available' => false,
        ])]]);

        $this->assertSame('unproven', $result['capability_matrix'][0]['integration_status']);
    }

    public function test_provider_with_partial_headless_sdk_proof_is_still_unproven(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'cursor_composer',
            'sdk_available' => true,
            'headless_available' => false,
        ])]]);

        $this->assertSame('unproven', $result['capability_matrix'][0]['integration_status']);
    }

    public function test_provider_with_full_headless_sdk_proof_is_production_ready(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'codex_cli',
            'sdk_available' => true,
            'headless_available' => true,
            'smoke_test_refs' => ['vendor/bin/phpunit runs without error'],
        ])]]);

        $this->assertSame('production_ready', $result['capability_matrix'][0]['integration_status']);
    }

    // ── AC1: sdk_available + headless_available without smoke_test_refs → unproven ──

    public function test_sdk_and_headless_without_smoke_test_refs_is_unproven(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'no-smoke',
            'sdk_available' => true,
            'headless_available' => true,
        ])]]);

        $this->assertSame('unproven', $result['capability_matrix'][0]['integration_status']);
    }

    // ── AC2: production_ready requires smoke_test_refs + atlas_required=false + fallback_required=true ──

    public function test_production_ready_requires_smoke_test_refs_and_non_required_fallback(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'full-proof',
            'sdk_available' => true,
            'headless_available' => true,
            'smoke_test_refs' => ['vendor/bin/phpunit', 'artisan test:smoke'],
        ])]]);

        $row = $result['capability_matrix'][0];
        $this->assertSame('production_ready', $row['integration_status']);
        $this->assertFalse($row['atlas_required']);
        $this->assertTrue($row['fallback_required']);
        $this->assertStringContainsString('smoke_test_refs', $row['proof_requirement']);
    }

    // ── AC3: patch_generation without long_running_goal → muscle_only ────────────

    public function test_patch_generation_without_long_running_goal_is_muscle_only(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'codex_muscle',
            'sdk_available' => true,
            'headless_available' => true,
            'smoke_test_refs' => ['smoke'],
            'supports_patch_generation' => true,
            'supports_long_running_goal' => false,
        ])]]);

        $this->assertSame('muscle_only', $result['capability_matrix'][0]['capability_level']);
    }

    public function test_patch_generation_with_long_running_goal_is_brain_ready(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'brain_pool',
            'sdk_available' => true,
            'headless_available' => true,
            'smoke_test_refs' => ['smoke'],
            'supports_patch_generation' => true,
            'supports_long_running_goal' => true,
        ])]]);

        $this->assertSame('brain_ready', $result['capability_matrix'][0]['capability_level']);
    }

    public function test_pool_without_patch_generation_has_null_capability_level(): void
    {
        $result = $this->contract->describe(['provider_pools' => [$this->pool([
            'provider_id' => 'no-muscle',
            'supports_patch_generation' => false,
            'supports_long_running_goal' => false,
        ])]]);

        $this->assertNull($result['capability_matrix'][0]['capability_level']);
    }

    public function test_empty_input_returns_empty_matrix(): void
    {
        $result = $this->contract->describe([]);

        $this->assertSame([], $result['capability_matrix']);
        $this->assertSame(0, $result['provider_count']);
    }

    public function test_pool_without_provider_id_is_skipped(): void
    {
        $result = $this->contract->describe(['provider_pools' => [
            ['model_family' => 'no-id-here'],
            $this->pool(),
        ]]);

        $this->assertSame(1, $result['provider_count']);
    }
}
