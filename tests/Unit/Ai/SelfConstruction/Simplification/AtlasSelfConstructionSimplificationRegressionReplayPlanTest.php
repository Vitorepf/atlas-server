<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationRegressionReplayPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationRegressionReplayPlanTest extends TestCase
{
    private function planner(): AtlasSelfConstructionSimplificationRegressionReplayPlan
    {
        return new AtlasSelfConstructionSimplificationRegressionReplayPlan;
    }

    public function test_queue_health_gate_defaults_to_self_heal_command_when_not_provided(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertContains('php artisan atlas:task:self-heal --json', $result['pre_checks']);
    }

    public function test_queue_health_gate_uses_provided_command_deterministically(): void
    {
        $planner = $this->planner();
        $input = [
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'queue_health_gate' => 'php artisan atlas:task:queue-status --json',
        ];

        $a = $planner->compile($input);
        $b = $planner->compile($input);

        $this->assertContains('php artisan atlas:task:queue-status --json', $a['pre_checks']);
        $this->assertNotContains('php artisan atlas:task:self-heal --json', $a['pre_checks']);
        $this->assertSame($a['pre_checks'], $b['pre_checks']);
    }

    public function test_every_plan_includes_all_four_check_sections(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA', 'OrganB'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertArrayHasKey('pre_checks', $result);
        $this->assertArrayHasKey('post_checks', $result);
        $this->assertArrayHasKey('replay_checks', $result);
        $this->assertArrayHasKey('acceptance_gates', $result);
        $this->assertNotEmpty($result['pre_checks']);
        $this->assertNotEmpty($result['post_checks']);
        $this->assertNotEmpty($result['replay_checks']);
        $this->assertNotEmpty($result['acceptance_gates']);
    }

    public function test_ready_plan_has_no_not_ready_reasons(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['not_ready_reasons']);
    }

    public function test_plan_without_behavior_equivalence_is_not_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => false,
            'rollback_plan_present' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('behavior_equivalence_not_proven', $result['not_ready_reasons']);
    }

    public function test_plan_without_rollback_coverage_is_not_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('rollback_plan_missing', $result['not_ready_reasons']);
    }

    public function test_replay_checks_reference_each_target_organ(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA', 'OrganB'],
        ]);

        $replayChecks = implode('|', $result['replay_checks']);
        $this->assertStringContainsString('OrganA', $replayChecks);
        $this->assertStringContainsString('OrganB', $replayChecks);
    }

    public function test_no_tests_covering_targets_is_a_not_ready_reason(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('no_tests_covering_targets', $result['not_ready_reasons']);
    }

    public function test_candidate_with_touched_tests_and_public_command_consumers_emits_exact_runnable_gates(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'public_command_consumers' => ['atlas:external-brain:originator-stop-pivot'],
            'command_replay_expectations' => [
                ['command' => 'atlas:external-brain:originator-stop-pivot', 'expected_exit_code' => 0, 'output_contract_refs' => ['schema.v1']],
            ],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'action' => 'merge',
            'rollback_receipt_present' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertContains('php artisan test tests/Unit/OrganATest.php', $result['pre_checks']);
        $this->assertContains('replay_public_command:atlas:external-brain:originator-stop-pivot', $result['pre_checks']);
        $this->assertContains('replay_public_command:atlas:external-brain:originator-stop-pivot', $result['replay_checks']);
        // No unrelated broad-suite fallback command is present.
        foreach ($result['pre_checks'] as $check) {
            $this->assertStringNotContainsString('--filter=', $check);
            $this->assertNotSame('php artisan test', trim($check));
        }
    }

    public function test_deletion_or_merge_candidate_without_proof_coverage_is_blocked_with_missing_replay_gate(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'action' => 'delete',
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing_replay_gate', $result['not_ready_reasons']);
        $this->assertContains('no_public_command_replay_coverage', $result['not_ready_reasons']);
        $this->assertContains('rollback_receipt_missing', $result['not_ready_reasons']);
        $this->assertContains('no_tests_covering_targets', $result['not_ready_reasons']);
    }

    public function test_rollback_receipt_requirement_included_for_deletion_and_merge_actions(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'action' => 'delete',
        ]);
        $this->assertContains('rollback_receipt_present', $result['acceptance_gates']);

        $notApplicable = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'action' => 'consolidate',
        ]);
        $this->assertNotContains('rollback_receipt_present', $notApplicable['acceptance_gates']);
        $this->assertNotContains('missing_replay_gate', $notApplicable['not_ready_reasons']);
    }

    public function test_delete_wave_with_public_command_consumers_but_no_replay_expectations_is_not_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'public_command_consumers' => ['atlas:some:command'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'action' => 'delete',
            'rollback_receipt_present' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing_command_replay_expectations', $result['not_ready_reasons']);
    }

    public function test_command_replay_expectation_missing_exit_code_or_output_contract_refs_is_not_ready(): void
    {
        $missingExitCode = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'public_command_consumers' => ['atlas:some:command'],
            'command_replay_expectations' => [
                ['command' => 'atlas:some:command', 'output_contract_refs' => ['schema.v1']],
            ],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'action' => 'delete',
            'rollback_receipt_present' => true,
        ]);
        $this->assertFalse($missingExitCode['ready']);
        $this->assertContains('incomplete_command_replay_expectation', $missingExitCode['not_ready_reasons']);

        $missingOutputRefs = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'public_command_consumers' => ['atlas:some:command'],
            'command_replay_expectations' => [
                ['command' => 'atlas:some:command', 'expected_exit_code' => 0],
            ],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'action' => 'delete',
            'rollback_receipt_present' => true,
        ]);
        $this->assertFalse($missingOutputRefs['ready']);
        $this->assertContains('incomplete_command_replay_expectation', $missingOutputRefs['not_ready_reasons']);
    }

    public function test_fully_specified_delete_wave_includes_replay_public_command_checks_and_is_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'public_command_consumers' => ['atlas:some:command'],
            'command_replay_expectations' => [
                ['command' => 'atlas:some:command', 'expected_exit_code' => 0, 'output_contract_refs' => ['schema.v1']],
            ],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'action' => 'delete',
            'rollback_receipt_present' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['not_ready_reasons']);
        $this->assertContains('replay_public_command:atlas:some:command', $result['pre_checks']);
        $this->assertContains('replay_public_command:atlas:some:command', $result['replay_checks']);
    }

    // ── AC: replay plans include caller_probe, command_probe, config_probe and failure_probe when those surfaces are touched ──

    public function test_replay_plan_includes_caller_probe_when_callers_touched(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'caller_paths' => ['app/Services/CallerA.php', 'app/Services/CallerB.php'],
        ]);

        $this->assertContains('caller_probe:app/Services/CallerA.php', $result['caller_probe']);
        $this->assertContains('caller_probe:app/Services/CallerB.php', $result['caller_probe']);
    }

    public function test_replay_plan_includes_command_probe_when_commands_touched(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'public_command_consumers' => ['atlas:cmd:one'],
        ]);

        $this->assertContains('replay_public_command:atlas:cmd:one', $result['command_probe']);
    }

    public function test_replay_plan_includes_config_probe_when_config_touched(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'config_bindings' => ['config/atlas.php', 'config/services.php'],
        ]);

        $this->assertContains('config_probe:config/atlas.php', $result['config_probe']);
        $this->assertContains('config_probe:config/services.php', $result['config_probe']);
    }

    public function test_replay_plan_includes_failure_probe_when_failure_cases_touched(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'failure_cases' => ['timeout_under_load', 'corrupt_input'],
        ]);

        $this->assertContains('failure_probe:timeout_under_load', $result['failure_probe']);
        $this->assertContains('failure_probe:corrupt_input', $result['failure_probe']);
    }

    // ── AC: candidates with no executable replay commands are blocked ──

    public function test_candidate_with_no_executable_replay_commands_is_blocked(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => [],
            'behavior_equivalence_proven' => false,
            'rollback_plan_present' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['not_ready_reasons']);
    }

    // ── AC: replay commands are deterministic and provider-safe ──

    public function test_replay_commands_are_deterministic(): void
    {
        $input = [
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'caller_paths' => ['app/Services/CallerA.php'],
            'config_bindings' => ['config/atlas.php'],
            'failure_cases' => ['timeout'],
        ];

        $a = $this->planner()->compile($input);
        $b = $this->planner()->compile($input);

        $this->assertSame($a['critical_path_probes'], $b['critical_path_probes']);
    }

    public function test_replay_commands_are_provider_safe(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'caller_paths' => ['app/Services/CallerA.php'],
        ]);

        $json = (string) json_encode($result);
        $this->assertStringNotContainsString('raw_prompt', $json);
        $this->assertStringNotContainsString('provider_trace', $json);
        $this->assertStringNotContainsString('api_key', $json);
    }
}
