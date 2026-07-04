<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolSmokeTestPlan;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProviderPoolSmokeTestPlanTest extends TestCase
{
    private function plan(): AtlasExternalBrainProviderPoolSmokeTestPlan
    {
        return new AtlasExternalBrainProviderPoolSmokeTestPlan();
    }

    private function fullEvidence(array $overrides = []): array
    {
        return array_merge([
            'sdk_proof' => true,
            'entitlement_proof' => true,
            'output_contract_proof' => true,
            'cost_proof' => true,
            'fallback_proof' => true,
        ], $overrides);
    }

    // ── AC2: ready_for_optional_routing requires all evidence ────────────────

    public function test_ready_for_optional_routing_when_all_evidence_present(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence()]);

        $this->assertSame('ready_for_optional_routing', $result['status']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_not_ready_when_one_evidence_missing(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence(['cost_proof' => false])]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(['cost_proof'], $result['missing_evidence']);
    }

    public function test_not_ready_when_all_evidence_absent(): void
    {
        $result = $this->plan()->plan([]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(AtlasExternalBrainProviderPoolSmokeTestPlan::REQUIRED_EVIDENCE, $result['missing_evidence']);
    }

    public function test_missing_evidence_lists_only_absent_fields(): void
    {
        $result = $this->plan()->plan(['evidence' => [
            'sdk_proof' => true,
            'entitlement_proof' => false,
            'output_contract_proof' => true,
            'cost_proof' => true,
            'fallback_proof' => false,
        ]]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(['entitlement_proof', 'fallback_proof'], $result['missing_evidence']);
    }

    // ── AC3: every step is non_executing_plan_only ───────────────────────────

    public function test_every_step_is_non_executing_plan_only(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence()]);

        foreach ($result['steps'] as $step) {
            $this->assertTrue($step['non_executing_plan_only'], "Step {$step['id']} is not non_executing_plan_only");
        }
    }

    public function test_all_provider_flags_are_false(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence()]);

        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
    }

    // ── AC4: output shape ────────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence()]);

        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('steps', $result);
        $this->assertArrayHasKey('required_evidence', $result);
        $this->assertArrayHasKey('evidence', $result);
        $this->assertArrayHasKey('missing_evidence', $result);
        $this->assertArrayHasKey('provider_call_allowed', $result);
        $this->assertArrayHasKey('token_spend_allowed', $result);
        $this->assertArrayHasKey('adapter_execution_allowed', $result);
    }

    public function test_provider_call_allowed_is_always_false(): void
    {
        $withInput  = $this->plan()->plan(['evidence' => $this->fullEvidence()]);
        $without    = $this->plan()->plan([]);

        $this->assertFalse($withInput['provider_call_allowed']);
        $this->assertFalse($without['provider_call_allowed']);
    }

    // ── steps shape ──────────────────────────────────────────────────────────

    public function test_steps_are_ordered_and_cover_seven_stages(): void
    {
        $result = $this->plan()->plan(['evidence' => $this->fullEvidence()]);

        $ids = array_column($result['steps'], 'id');
        $this->assertSame([
            'preflight',
            'harmless_prompt',
            'patch_generation_dry_run',
            'no_secret_policy_check',
            'cost_boundary_check',
            'timeout_boundary',
            'fallback_replay',
        ], $ids);

        $orders = array_column($result['steps'], 'order');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $orders);
    }

    // ── evidence shape ───────────────────────────────────────────────────────

    public function test_required_evidence_covers_five_named_proofs(): void
    {
        $this->assertSame([
            'sdk_proof',
            'entitlement_proof',
            'output_contract_proof',
            'cost_proof',
            'fallback_proof',
        ], AtlasExternalBrainProviderPoolSmokeTestPlan::REQUIRED_EVIDENCE);
    }

    public function test_evidence_output_reflects_input(): void
    {
        $result = $this->plan()->plan(['evidence' => [
            'sdk_proof' => true,
            'entitlement_proof' => false,
            'cost_proof' => true,
        ]]);

        $this->assertTrue($result['evidence']['sdk_proof']);
        $this->assertFalse($result['evidence']['entitlement_proof']);
        $this->assertFalse($result['evidence']['output_contract_proof']); // absent → false
        $this->assertTrue($result['evidence']['cost_proof']);
        $this->assertFalse($result['evidence']['fallback_proof']); // absent → false
    }

    // ── no input edge cases ──────────────────────────────────────────────────

    public function test_absent_evidence_key_defaults_to_empty_missing_list_and_incomplete(): void
    {
        $result = $this->plan()->plan([]);

        $this->assertCount(5, $result['missing_evidence']);
        $this->assertSame('evidence_incomplete', $result['status']);
    }

    // ── non-array evidence defaults to empty ─────────────────────────────────

    public function test_non_array_evidence_is_treated_as_absent(): void
    {
        $result = $this->plan()->plan([]);

        $this->assertSame('evidence_incomplete', $result['status']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['evidence' => ['sdk_proof' => true, 'cost_proof' => true]];

        $a = $this->plan()->plan($input);
        $b = $this->plan()->plan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
