<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\SchemaContract;
use InvalidArgumentException;
use Tests\TestCase;

class ClaimTierTest extends TestCase
{
    public function test_harness_and_diagnostic_arms_cannot_escalate_to_production(): void
    {
        $registry = new ArmRegistry;

        $this->assertSame(
            ClaimTier::HARNESS,
            ClaimTier::forPlan('local_fake', [$registry->parse('local_fake_model@bare')]),
        );
        $this->assertSame(
            ClaimTier::DIAGNOSTIC,
            ClaimTier::forPlan('atlas_bench', [$registry->parse('harness_golden@bare')]),
        );

        $this->expectException(InvalidArgumentException::class);
        ClaimTier::forPlan(
            'local_fake',
            [$registry->parse('local_fake_model@bare')],
            ClaimTier::PRODUCTION,
        );
    }

    public function test_real_external_arm_defaults_to_production(): void
    {
        $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', 'tau2_bench');

        $this->assertSame(
            ClaimTier::PRODUCTION,
            ClaimTier::forPlan('tau2_bench', [$arm]),
        );
    }

    public function test_v1_plan_and_receipt_are_dual_read_as_non_claimable_v2(): void
    {
        $arm = (new ArmRegistry)->parse('local_fake_model@bare');
        $plan = RunPlan::fromArray([
            'schema_version' => SchemaContract::RUN_PLAN_V1,
            'run_id' => '20260709_000000_abcdef12',
            'suite_id' => 'local_fake',
            'case_ids' => ['fake_patch_ok'],
            'arms' => [$arm],
            'repetitions' => 1,
            'budget' => ['max_usd' => 0.0, 'max_minutes' => 1],
            'seed' => 1,
            'environment' => ['os' => 'test'],
            'created_at' => now()->toIso8601String(),
        ]);
        $this->assertSame(SchemaContract::RUN_PLAN, $plan->data['schema_version']);
        $this->assertSame(ClaimTier::HARNESS, $plan->data['claim_tier']);

        $receipt = RunReceipt::fromArray([
            'schema_version' => SchemaContract::RUN_RECEIPT_V1,
            'run_id' => $plan->runId(),
            'case_id' => 'fake_patch_ok',
            'task_type' => 'coding_patch',
            'arm_id' => 'local_fake_model@bare',
            'repetition' => 1,
            'status' => 'success',
            'wall_ms' => 1,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0.0,
            'artifacts' => [],
            'started_at' => null,
            'finished_at' => null,
        ]);
        $this->assertSame(SchemaContract::RUN_RECEIPT, $receipt->data['schema_version']);
        $this->assertSame(ClaimTier::HARNESS, $receipt->data['claim_tier']);
        $this->assertTrue($receipt->data['harness_only']);
    }
}
