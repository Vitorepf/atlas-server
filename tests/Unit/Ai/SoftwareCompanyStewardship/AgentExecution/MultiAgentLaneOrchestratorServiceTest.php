<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLaneOrchestratorService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AP-797 · Multi-Agent Lane Orchestrator contract tests.
 *
 * The orchestrator is a pure deterministic plan builder. It never invokes a
 * provider, never mutates a branch, never merges, never runs repair and never
 * scores the judge. These tests pin the lane shape, the conditional repair lane,
 * the blocking guards, the deterministic hash and the no-execution guarantees.
 */
class MultiAgentLaneOrchestratorServiceTest extends TestCase
{
    private function service(): MultiAgentLaneOrchestratorService
    {
        return app(MultiAgentLaneOrchestratorService::class);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function slice(array $over = []): array
    {
        return array_merge([
            'slice_id' => 'slice_abcdef0123456789',
            'objective' => 'Harden Reliable24hLoopRunnerService stale-lock recovery.',
            'owner' => 'atlas_dev',
            'risk_level' => 'medium',
            'allowed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
            ],
            'forbidden_files' => ['config/*', '.env'],
            'validation_commands' => [
                'php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
            ],
            'evidence_obligations' => ['test_log', 'git_diff', 'inbox_item'],
            'max_runtime_seconds' => 1800,
        ], $over);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function roles(array $plan): array
    {
        return array_map(static fn (array $lane): string => $lane['role'], $plan['lanes']);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function lane(array $plan, string $role): array
    {
        foreach ($plan['lanes'] as $lane) {
            if ($lane['role'] === $role) {
                return $lane;
            }
        }
        $this->fail("lane {$role} not found in plan");
    }

    public function test_clean_slice_builds_five_lane_plan(): void
    {
        $plan = $this->service()->orchestrate(['executable_slice' => $this->slice()]);

        $this->assertSame(MultiAgentLaneOrchestratorService::PLAN_SCHEMA, $plan['schema_version']);
        $this->assertSame('AP-797', $plan['ap_contract']);
        $this->assertStringStartsWith('malp_', $plan['plan_id']);
        $this->assertStringStartsWith('sha256:', $plan['plan_hash']);

        $this->assertSame([
            'context_scout',
            'architect',
            'implementer',
            'reviewer',
            'judge',
        ], $this->roles($plan));
        $this->assertSame(array_column($plan['lanes'], 'lane_id'), $plan['sequence']);
        $this->assertFalse($plan['repair']['included']);

        // Canonical write authority per role (AP-793 lane table).
        $this->assertSame('read_only', $this->lane($plan, 'context_scout')['write_authority']);
        $this->assertSame('spec_only', $this->lane($plan, 'architect')['write_authority']);
        $this->assertSame('worktree_write', $this->lane($plan, 'implementer')['write_authority']);
        $this->assertSame('read_only', $this->lane($plan, 'reviewer')['write_authority']);
        $this->assertSame('read_only_no_merge', $this->lane($plan, 'judge')['write_authority']);

        // Each lane carries the full AP-797 lane contract.
        foreach ($plan['lanes'] as $lane) {
            foreach (['lane_id', 'role', 'write_authority', 'input_refs', 'output_contract', 'budget', 'timeout_seconds', 'allowed_actions', 'forbidden_actions', 'status', 'receipt'] as $key) {
                $this->assertArrayHasKey($key, $lane, "lane {$lane['role']} missing key {$key}");
            }
            $this->assertStringStartsWith('lane_', $lane['lane_id']);
            $this->assertStringStartsWith('lrcpt_', $lane['receipt']['receipt_id']);
        }
    }

    public function test_repair_agent_lane_is_conditional(): void
    {
        $clean = $this->service()->orchestrate(['executable_slice' => $this->slice()]);
        $this->assertNotContains('repair_agent', $this->roles($clean));

        foreach ([
            ['validation_failure_present' => true],
            ['gate_failure_present' => true],
            ['policy_requires_repair' => true],
        ] as $trigger) {
            $plan = $this->service()->orchestrate(array_merge(['executable_slice' => $this->slice()], $trigger));
            $this->assertTrue($plan['repair']['included']);
            $this->assertSame([
                'context_scout',
                'architect',
                'implementer',
                'reviewer',
                'repair_agent',
                'judge',
            ], $this->roles($plan), 'repair lane must sit between reviewer and judge');
            $this->assertSame('repair_branch_write', $this->lane($plan, 'repair_agent')['write_authority']);
            $this->assertNotEmpty($plan['repair']['triggers']);
        }
    }

    public function test_implementer_without_allowed_files_blocks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/implementer_allowed_files_required/');
        $this->service()->orchestrate(['executable_slice' => $this->slice(['allowed_files' => []])]);
    }

    public function test_judge_write_authority_blocks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/judge_write_authority_forbidden/');
        $this->service()->orchestrate([
            'executable_slice' => $this->slice(),
            'lane_overrides' => ['judge' => ['write_authority' => 'worktree_write']],
        ]);
    }

    public function test_read_only_lane_cannot_be_elevated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/read_only_lane_write_authority_violation/');
        $this->service()->orchestrate([
            'executable_slice' => $this->slice(),
            'lane_overrides' => ['reviewer' => ['write_authority' => 'worktree_write']],
        ]);
    }

    public function test_forbidden_action_blocks(): void
    {
        foreach (['merge_to_main', 'deploy', 'delete_files', 'access_secrets'] as $forbidden) {
            try {
                $this->service()->orchestrate([
                    'executable_slice' => $this->slice(),
                    'lane_overrides' => ['implementer' => ['extra_allowed_actions' => [$forbidden]]],
                ]);
                $this->fail("forbidden action {$forbidden} should have blocked");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('forbidden_action_in_lane', $e->getMessage());
            }
        }
    }

    public function test_missing_work_unit_blocks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/work_unit_identity_required/');
        $this->service()->orchestrate([]);
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $first = $this->service()->orchestrate(['executable_slice' => $this->slice()]);
        $second = $this->service()->orchestrate(['executable_slice' => $this->slice()]);

        $this->assertSame($first['plan_hash'], $second['plan_hash']);
        $this->assertSame($first['plan_id'], $second['plan_id']);
        $this->assertSame($first['sequence'], $second['sequence']);

        // A different work unit yields a different deterministic hash.
        $other = $this->service()->orchestrate(['executable_slice' => $this->slice(['slice_id' => 'slice_other_000000000'])]);
        $this->assertNotSame($first['plan_hash'], $other['plan_hash']);
    }

    public function test_plan_only_executes_nothing(): void
    {
        $plan = $this->service()->orchestrate([
            'executable_slice' => $this->slice(),
            'mode' => 'plan_only',
        ]);

        $this->assertSame('plan_only', $plan['mode']);
        $this->assertTrue($plan['claim_policy']['no_provider_call']);
        $this->assertTrue($plan['claim_policy']['no_branch_mutation']);
        $this->assertTrue($plan['claim_policy']['no_merge']);
        $this->assertTrue($plan['claim_policy']['no_repair_execution']);
        $this->assertTrue($plan['claim_policy']['no_judge_scoring']);
        $this->assertTrue($plan['claim_policy']['deterministic']);
        $this->assertTrue($plan['claim_policy']['plan_only']);

        // No lane is dispatched/running; every lane sits at the initial planned state.
        foreach ($plan['lanes'] as $lane) {
            $this->assertSame('planned', $lane['status'], "lane {$lane['role']} must not be executing");
        }
        $this->assertSame('planned', $plan['state_machine']['initial_plan_state']);
    }

    public function test_execution_ready_mode_only_changes_initial_state(): void
    {
        $plan = $this->service()->orchestrate([
            'executable_slice' => $this->slice(),
            'mode' => 'execution_ready',
        ]);

        $this->assertSame('execution_ready', $plan['mode']);
        // Still invokes nothing: claim policy guarantees hold regardless of mode.
        $this->assertTrue($plan['claim_policy']['no_provider_call']);
        $this->assertTrue($plan['claim_policy']['no_merge']);
        $this->assertFalse($plan['claim_policy']['plan_only']);
        foreach ($plan['lanes'] as $lane) {
            $this->assertSame('ready', $lane['status']);
        }
        $this->assertSame('ready', $plan['state_machine']['initial_plan_state']);
    }

    public function test_lanes_share_one_evidence_pack(): void
    {
        $plan = $this->service()->orchestrate(['executable_slice' => $this->slice()]);

        $this->assertStringStartsWith('aevp_', $plan['shared_evidence_pack_ref']);
        foreach ($plan['lanes'] as $lane) {
            $this->assertSame($plan['shared_evidence_pack_ref'], $lane['receipt']['evidence_pack_ref']);
        }
    }
}
