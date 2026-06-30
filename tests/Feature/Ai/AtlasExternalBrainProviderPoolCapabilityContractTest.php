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
        ])]]);

        $this->assertSame('production_ready', $result['capability_matrix'][0]['integration_status']);
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
