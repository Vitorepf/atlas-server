<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\ToolRuntime\Governance;

use App\Services\Ai\ToolRuntime\Governance\ToolRecipePromotionGate;
use Tests\TestCase;

final class ToolRecipePromotionGateTest extends TestCase
{
    private ToolRecipePromotionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ToolRecipePromotionGate;
    }

    private function compliantRecipe(): array
    {
        return [
            'recipe_id' => 'psql.read_query',
            'schema_version' => 'atlas.tool_runtime.recipe.v1',
            'execution_class' => 'read',
            'policy_budget' => [
                'timeout_seconds' => 30,
                'calls_per_day_limit' => 500,
            ],
            'evidence_contract' => 'atlas.tool_runtime.recipe_receipt.v1',
            'sandbox' => 'host',
            'failure_handler' => 'rollback_and_log',
        ];
    }

    public function test_approves_compliant_recipe(): void
    {
        $r = $this->gate->evaluate($this->compliantRecipe());
        $this->assertSame('promotion_approved', $r['promotion_decision']);
        $this->assertSame([], $r['failed_checks']);
        $this->assertSame('atlas.tool_runtime.recipe_promotion.v1', $r['schema_version']);
    }

    public function test_blocks_when_required_field_missing(): void
    {
        $recipe = $this->compliantRecipe();
        unset($recipe['sandbox']);
        $r = $this->gate->evaluate($recipe);
        $this->assertSame('promotion_blocked', $r['promotion_decision']);
        $this->assertArrayHasKey('sandbox', $r['failed_checks']);
    }

    public function test_blocks_invalid_execution_class(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['execution_class'] = 'wibble';
        $r = $this->gate->evaluate($recipe);
        $this->assertArrayHasKey('execution_class', $r['failed_checks']);
    }

    public function test_blocks_invalid_sandbox(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['sandbox'] = 'mars';
        $r = $this->gate->evaluate($recipe);
        $this->assertArrayHasKey('sandbox', $r['failed_checks']);
    }

    public function test_blocks_policy_budget_missing_timeout(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['policy_budget'] = ['calls_per_day_limit' => 100];
        $r = $this->gate->evaluate($recipe);
        $this->assertArrayHasKey('policy_budget.timeout_seconds', $r['failed_checks']);
    }

    public function test_blocks_policy_budget_missing_per_day(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['policy_budget'] = ['timeout_seconds' => 10];
        $r = $this->gate->evaluate($recipe);
        $this->assertArrayHasKey('policy_budget.calls_per_day_limit', $r['failed_checks']);
    }

    public function test_danger_class_requires_operator_authority(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['execution_class'] = 'danger';
        $r = $this->gate->evaluate($recipe);
        $this->assertArrayHasKey('operator_authority_required', $r['failed_checks']);
    }

    public function test_danger_class_passes_with_operator_authority(): void
    {
        $recipe = $this->compliantRecipe();
        $recipe['execution_class'] = 'danger';
        $recipe['operator_authority_required'] = true;
        $r = $this->gate->evaluate($recipe);
        $this->assertSame('promotion_approved', $r['promotion_decision']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliantRecipe());
        $this->assertSame([
            'schema_version', 'recipe_id', 'promotion_decision', 'passed_checks',
            'failed_checks', 'evidence_contract', 'detail', 'evaluated_at',
        ], array_keys($r));
    }

    public function test_passed_checks_records_each_invariant(): void
    {
        $r = $this->gate->evaluate($this->compliantRecipe());
        $this->assertContains('recipe_id_present', $r['passed_checks']);
        $this->assertContains('execution_class_valid', $r['passed_checks']);
        $this->assertContains('sandbox_valid', $r['passed_checks']);
        $this->assertContains('policy_budget_timeout_declared', $r['passed_checks']);
        $this->assertContains('policy_budget_per_day_declared', $r['passed_checks']);
    }
}
