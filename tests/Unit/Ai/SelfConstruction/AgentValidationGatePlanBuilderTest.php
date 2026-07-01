<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGatePlanBuilder;
use PHPUnit\Framework\TestCase;

final class AgentValidationGatePlanBuilderTest extends TestCase
{
    public function test_requested_gates_are_ordered_by_canonical_order_not_caller_input_order(): void
    {
        $builder = new AgentValidationGatePlanBuilder;

        $plan = $builder->buildPlan([
            'requested_gates' => ['unit_tests', 'php_lint', 'scope_check'],
        ]);

        $this->assertSame(
            ['scope_check', 'php_lint', 'unit_tests'],
            $plan['ordered_gate_ids'],
        );
    }

    public function test_unknown_requested_gate_ids_blocks_plan_quality(): void
    {
        $builder = new AgentValidationGatePlanBuilder;

        $plan = $builder->buildPlan([
            'requested_gates' => ['php_lint', 'not_a_real_gate'],
        ]);

        $this->assertSame(['not_a_real_gate'], $plan['unknown_requested_gate_ids']);
        $this->assertSame('blocked_unknown_gate_request', $plan['plan_quality']);
    }

    public function test_forbidden_or_unknown_changed_files_block_plan_quality_without_execution(): void
    {
        $builder = new AgentValidationGatePlanBuilder;

        $plan = $builder->buildPlan([
            'allowed_files' => ['app/Foo.php'],
            'forbidden_files' => ['.env'],
            'changed_files' => ['.env'],
        ]);

        $this->assertSame('blocked_scope_violation', $plan['plan_quality']);
        $this->assertFalse($plan['execution_allowed']);
        $this->assertFalse($plan['runtime_safety']['execution_allowed']);
    }

    public function test_unknown_changed_file_outside_allowed_scope_also_blocks_plan_quality(): void
    {
        $builder = new AgentValidationGatePlanBuilder;

        $plan = $builder->buildPlan([
            'allowed_files' => ['app/Foo.php'],
            'changed_files' => ['app/NotAllowed.php'],
        ]);

        $this->assertSame('blocked_scope_violation', $plan['plan_quality']);
    }

    public function test_clean_plan_has_ok_plan_quality(): void
    {
        $builder = new AgentValidationGatePlanBuilder;

        $plan = $builder->buildPlan([
            'requested_gates' => ['php_lint'],
        ]);

        $this->assertSame('ok', $plan['plan_quality']);
    }
}
