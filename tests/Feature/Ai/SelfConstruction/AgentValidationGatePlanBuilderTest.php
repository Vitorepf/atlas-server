<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGatePlanBuilder;
use Tests\TestCase;

final class AgentValidationGatePlanBuilderTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_plan.v1', AgentValidationGatePlanBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_plan', AgentValidationGatePlanBuilder::MODE);
        $this->assertContains('scope_check', AgentValidationGatePlanBuilder::CANONICAL_ORDER);
        $this->assertContains('rollback_plan_check', AgentValidationGatePlanBuilder::CANONICAL_ORDER);
        $this->assertSame(10, count(AgentValidationGatePlanBuilder::CANONICAL_ORDER));
    }

    public function test_empty_context_yields_full_plan(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $this->assertSame('plan_ready', $plan['status']);
        $this->assertFalse($plan['execution_allowed']);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['self_programming_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['runtime_write_allowed']);
        $this->assertSame(10, count($plan['ordered_runs']));
        $this->assertSame(10, count($plan['ordered_gate_ids']));
        $this->assertSame(10, count($plan['requested_gate_ids']));
        $this->assertSame([], $plan['unknown_requested_gate_ids']);
        $this->assertMatchesRegularExpression('/^plan-[a-f0-9]{16}$/', $plan['plan_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['plan_hash']);
    }

    public function test_canonical_ordering(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $this->assertSame(AgentValidationGatePlanBuilder::CANONICAL_ORDER, $plan['ordered_gate_ids']);
    }

    public function test_requested_subset_is_honored(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'requested_gates' => ['unit_tests', 'docs_health', 'scope_check'],
        ]);
        $this->assertSame(['scope_check', 'docs_health', 'unit_tests'], $plan['ordered_gate_ids']);
        $this->assertSame(['unit_tests', 'docs_health', 'scope_check'], $plan['requested_gate_ids']);
        $this->assertSame([], $plan['unknown_requested_gate_ids']);
    }

    public function test_unknown_requested_gate_collected(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'requested_gates' => ['unit_tests', 'fake_gate', 'docs_health'],
        ]);
        $this->assertSame(['fake_gate'], $plan['unknown_requested_gate_ids']);
        $this->assertSame(['docs_health', 'unit_tests'], $plan['ordered_gate_ids']);
    }

    public function test_empty_requested_yields_empty_plan(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'requested_gates' => [],
        ]);
        $this->assertSame('empty_plan', $plan['status']);
        $this->assertSame([], $plan['ordered_runs']);
        $this->assertSame([], $plan['ordered_gate_ids']);
        $this->assertSame([], $plan['abort_conditions']);
    }

    public function test_runs_have_canonical_run_shape(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        foreach ($plan['ordered_runs'] as $i => $run) {
            $this->assertSame($i, $run['position']);
            $this->assertIsString($run['gate_id']);
            $this->assertIsString($run['gate_name']);
            $this->assertIsString($run['gate_type']);
            $this->assertIsString($run['severity']);
            $this->assertIsBool($run['blocking']);
            $this->assertIsBool($run['requires_command']);
            $this->assertIsString($run['command']);
            $this->assertIsString($run['expected_artifact']);
            $this->assertIsArray($run['dependencies']);
            $this->assertIsArray($run['skip_unless']);
            $this->assertIsBool($run['abort_on_failure']);
        }
    }

    public function test_dependencies_chain_to_previous_run(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $runs = $plan['ordered_runs'];
        $this->assertSame([], $runs[0]['dependencies']);
        for ($i = 1; $i < count($runs); $i++) {
            $this->assertSame([$runs[$i - 1]['gate_id']], $runs[$i]['dependencies']);
        }
    }

    public function test_abort_conditions_match_blocking_gates(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $abortIds = array_map(static fn ($a) => $a['gate_id'], $plan['abort_conditions']);
        foreach ($plan['ordered_runs'] as $run) {
            if ($run['blocking'] === true) {
                $this->assertContains($run['gate_id'], $abortIds);
                $this->assertTrue($run['abort_on_failure']);
            } else {
                $this->assertNotContains($run['gate_id'], $abortIds);
                $this->assertFalse($run['abort_on_failure']);
            }
        }
    }

    public function test_focused_filter_renders_into_command(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'focused_filter' => 'AgentValidationGateCatalogTest',
        ]);
        $focused = $this->findRun($plan, 'focused_tests');
        $this->assertNotNull($focused);
        $this->assertStringContainsString('AgentValidationGateCatalogTest', $focused['command']);
        $this->assertStringNotContainsString('<focused_filter>', $focused['command']);
        $this->assertTrue($plan['context_summary']['focused_filter_present']);
        $this->assertSame('AgentValidationGateCatalogTest', $plan['context_summary']['focused_filter']);
    }

    public function test_focused_filter_absent_leaves_placeholder(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $focused = $this->findRun($plan, 'focused_tests');
        $this->assertNotNull($focused);
        $this->assertStringContainsString('<focused_filter>', $focused['command']);
        $this->assertFalse($plan['context_summary']['focused_filter_present']);
        $this->assertSame('', $plan['context_summary']['focused_filter']);
    }

    public function test_scope_violation_forbidden_intersection(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'allowed_files' => ['a.php', 'b.php'],
            'forbidden_files' => ['c.php'],
            'changed_files' => ['a.php', 'c.php'],
        ]);
        $this->assertTrue($plan['context_summary']['scope_violation_detected']);
        $this->assertSame(['c.php'], $plan['context_summary']['forbidden_files_touched']);
        $this->assertSame([], $plan['context_summary']['unknown_files_touched']);
    }

    public function test_scope_violation_unknown_files(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'allowed_files' => ['a.php'],
            'forbidden_files' => [],
            'changed_files' => ['a.php', 'z.php'],
        ]);
        $this->assertTrue($plan['context_summary']['scope_violation_detected']);
        $this->assertSame(['z.php'], $plan['context_summary']['unknown_files_touched']);
        $this->assertSame([], $plan['context_summary']['forbidden_files_touched']);
    }

    public function test_scope_violation_clean_when_allowed_only(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'allowed_files' => ['a.php', 'b.php'],
            'forbidden_files' => ['c.php'],
            'changed_files' => ['a.php', 'b.php'],
        ]);
        $this->assertFalse($plan['context_summary']['scope_violation_detected']);
        $this->assertSame([], $plan['context_summary']['forbidden_files_touched']);
        $this->assertSame([], $plan['context_summary']['unknown_files_touched']);
    }

    public function test_no_allowed_files_means_no_unknown_violation(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'changed_files' => ['a.php', 'b.php'],
        ]);
        $this->assertFalse($plan['context_summary']['scope_violation_detected']);
        $this->assertSame([], $plan['context_summary']['unknown_files_touched']);
    }

    public function test_plan_hash_changes_with_context(): void
    {
        $b = new AgentValidationGatePlanBuilder;
        $a = $b->buildPlan();
        $c = $b->buildPlan(['changed_files' => ['a.php']]);
        $this->assertNotSame($a['plan_hash'], $c['plan_hash']);
    }

    public function test_plan_hash_stable_for_same_context(): void
    {
        $b = new AgentValidationGatePlanBuilder;
        $a = $b->buildPlan(['changed_files' => ['a.php']]);
        $c = $b->buildPlan(['changed_files' => ['a.php']]);
        $this->assertSame($a['plan_hash'], $c['plan_hash']);
        $this->assertSame($a['plan_id'], $c['plan_id']);
    }

    public function test_skip_unless_canonical_rules(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $expected = [
            'focused_tests' => ['focused_filter_present'],
            'docs_health' => ['docs_changed_or_added'],
            'architecture_validate' => ['architecture_or_governance_changed'],
            'diff_check' => ['working_tree_has_changes'],
        ];
        foreach ($expected as $id => $rules) {
            $run = $this->findRun($plan, $id);
            $this->assertNotNull($run);
            $this->assertSame($rules, $run['skip_unless']);
        }
        $other = $this->findRun($plan, 'scope_check');
        $this->assertSame([], $other['skip_unless']);
    }

    public function test_safety_invariants_block(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inv = $plan['safety_invariants'];
        $this->assertContains('no_real_command_execution', $inv);
        $this->assertContains('no_provider_call', $inv);
        $this->assertContains('no_token_spend', $inv);
        $this->assertContains('no_self_programming', $inv);
        $this->assertContains('no_ledger_write', $inv);
        $this->assertContains('plan_does_not_authorize_runtime', $inv);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $rs = $plan['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
    }

    public function test_garbage_requested_gates_filtered_to_empty(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'requested_gates' => 'not-an-array',
        ]);
        $this->assertSame('empty_plan', $plan['status']);
        $this->assertSame([], $plan['ordered_gate_ids']);
    }

    public function test_garbage_files_silently_ignored(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'changed_files' => 'not-array',
            'allowed_files' => ['a.php'],
            'forbidden_files' => null,
        ]);
        $this->assertSame(0, $plan['context_summary']['changed_file_count']);
        $this->assertSame(1, $plan['context_summary']['allowed_file_count']);
        $this->assertSame(0, $plan['context_summary']['forbidden_file_count']);
    }

    public function test_duplicate_requested_gates_deduped(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan([
            'requested_gates' => ['unit_tests', 'unit_tests', 'docs_health'],
        ]);
        $this->assertSame(['docs_health', 'unit_tests'], $plan['ordered_gate_ids']);
    }

    /** @return array<string,mixed>|null */
    private function findRun(array $plan, string $id): ?array
    {
        foreach ($plan['ordered_runs'] as $run) {
            if ($run['gate_id'] === $id) {
                return $run;
            }
        }

        return null;
    }
}
