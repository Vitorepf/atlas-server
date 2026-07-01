<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCapabilityContract;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolCapabilityContractTest extends TestCase
{
    private function svc(): AtlasExternalBrainProviderPoolCapabilityContract
    {
        return new AtlasExternalBrainProviderPoolCapabilityContract;
    }

    // ── schema / output shape ────────────────────────────────────────────────

    public function test_schema_and_empty_pools_yield_empty_matrix(): void
    {
        $r = $this->svc()->describe(['provider_pools' => []]);

        $this->assertSame(AtlasExternalBrainProviderPoolCapabilityContract::SCHEMA, $r['schema']);
        $this->assertSame([], $r['capability_matrix']);
        $this->assertSame(0, $r['provider_count']);
        $this->assertSame([], $r['rejected_pools']);
    }

    // ── AC2: capability axes, proof requirement, cost class, fallback strategy, autonomy risk ──

    public function test_production_ready_pool_has_low_risk_and_all_new_fields(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'codex',
            'model_family' => 'gpt',
            'cost_tier' => 'strong',
            'sdk_available' => true,
            'headless_available' => true,
            'supports_patch_generation' => true,
            'supports_task_origination' => true,
        ]]]);

        $row = $r['capability_matrix'][0];
        $this->assertSame('production_ready', $row['integration_status']);
        $this->assertSame('low', $row['autonomy_risk']);
        $this->assertSame(['patch_generation', 'task_origination'], $row['capability_axes']);
        $this->assertStringContainsString('proven', $row['proof_requirement']);
        $this->assertStringContainsString('codex', $row['fallback_strategy']);
        $this->assertSame('strong', $row['cost_tier']);
    }

    public function test_unproven_pool_with_no_capability_axis_has_high_risk(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'cursor',
            'model_family' => 'composer',
            'cost_tier' => 'strong',
            'sdk_available' => true,
            'headless_available' => false,
        ]]]);

        $row = $r['capability_matrix'][0];
        $this->assertSame('unproven', $row['integration_status']);
        $this->assertSame('high', $row['autonomy_risk']);
        $this->assertSame([], $row['capability_axes']);
        $this->assertStringContainsString('requires', $row['proof_requirement']);
    }

    public function test_unproven_pool_with_a_capability_axis_has_medium_risk(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'trial-pool',
            'model_family' => 'trial',
            'cost_tier' => 'cheap',
            'supports_patch_generation' => true,
        ]]]);

        $row = $r['capability_matrix'][0];
        $this->assertSame('unproven', $row['integration_status']);
        $this->assertSame('medium', $row['autonomy_risk']);
        $this->assertSame(['patch_generation'], $row['capability_axes']);
    }

    // ── AC3: acceleration surface, never a mandatory dependency ─────────────────

    public function test_every_pool_is_marked_optional_accelerator_never_required(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'any-pool',
            'model_family' => 'x',
            'cost_tier' => 'y',
            'sdk_available' => true,
            'headless_available' => true,
        ]]]);

        $row = $r['capability_matrix'][0];
        $this->assertTrue($row['optional_accelerator']);
        $this->assertFalse($row['atlas_required']);
        $this->assertTrue($row['fallback_required']);
    }

    // ── AC4: determinism ──────────────────────────────────────────────────────

    public function test_output_is_deterministic_for_identical_input(): void
    {
        $input = ['provider_pools' => [[
            'provider_id' => 'codex',
            'model_family' => 'gpt',
            'cost_tier' => 'strong',
            'sdk_available' => true,
            'headless_available' => true,
            'supports_long_running_goal' => true,
        ]]];

        $a = $this->svc()->describe($input);
        $b = $this->svc()->describe($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC4: rejects empty or proxy-only capability descriptions ────────────────

    public function test_empty_capability_description_is_rejected_with_actionable_signal(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'ghost-pool',
        ]]]);

        $this->assertSame([], $r['capability_matrix']);
        $this->assertSame(0, $r['provider_count']);
        $this->assertCount(1, $r['rejected_pools']);
        $rejected = $r['rejected_pools'][0];
        $this->assertSame('ghost-pool', $rejected['provider_id']);
        $this->assertSame('empty_capability_description', $rejected['rejection_reason']);
        $this->assertNotEmpty($rejected['actionable_risk_signal']);
    }

    public function test_proxy_only_quota_policy_is_rejected_with_actionable_signal(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'proxy-pool',
            'model_family' => 'wrapper',
            'cost_tier' => 'cheap',
            'quota_pool_policy' => 'shared_proxy_pool',
        ]]]);

        $this->assertSame([], $r['capability_matrix']);
        $this->assertCount(1, $r['rejected_pools']);
        $this->assertSame('proxy_only_capability_description', $r['rejected_pools'][0]['rejection_reason']);
    }

    public function test_pool_with_identity_but_no_capability_axes_is_not_rejected(): void
    {
        // Real identity (model_family + cost_tier) with zero supports_* flags must NOT be
        // rejected — it is common for a newly discovered pool to have no capability proof yet.
        $r = $this->svc()->describe(['provider_pools' => [[
            'provider_id' => 'new-pool',
            'model_family' => 'unknown-model',
            'cost_tier' => 'unknown',
        ]]]);

        $this->assertSame([], $r['rejected_pools']);
        $this->assertCount(1, $r['capability_matrix']);
        $this->assertSame('high', $r['capability_matrix'][0]['autonomy_risk']);
    }

    // ── multiple pools ────────────────────────────────────────────────────────

    public function test_multiple_pools_mix_accepted_and_rejected(): void
    {
        $r = $this->svc()->describe(['provider_pools' => [
            ['provider_id' => 'good-pool', 'model_family' => 'x', 'cost_tier' => 'cheap'],
            ['provider_id' => 'empty-pool'],
        ]]);

        $this->assertSame(1, $r['provider_count']);
        $this->assertCount(1, $r['rejected_pools']);
        $this->assertSame('good-pool', $r['capability_matrix'][0]['provider_id']);
        $this->assertSame('empty-pool', $r['rejected_pools'][0]['provider_id']);
    }
}
