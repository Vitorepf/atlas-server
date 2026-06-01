<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasImplementationPlanningAndRolloutService;
use Tests\TestCase;

/**
 * Pins the documented Implementation Planning And Rollout rules: the nine-field
 * Block Shape (architecture/doc command optional), the seven-step Rollout Order
 * with its earlier-steps-first invariant, the six absolute Stop Conditions, and
 * the five-condition Done Definition gating final automation.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md
 */
class AtlasImplementationPlanningAndRolloutTest extends TestCase
{
    private function service(): AtlasImplementationPlanningAndRolloutService
    {
        return new AtlasImplementationPlanningAndRolloutService();
    }

    /**
     * A fully-shaped block (eight mandatory fields declared; architecture/doc
     * command may be omitted as it is conditional) at the first rollout step
     * with no stop conditions => proceed, may execute the step.
     */
    public function test_well_formed_block_at_first_step_proceeds(): void
    {
        $r = $this->service()->evaluate([
            'shape' => [
                'objective' => 'Add cold guardrail for rollout gate.',
                'owner_files' => ['app/Services/X.php'],
                'hot_files_to_avoid' => ['app/Kernel.php'],
                'allowed_files' => ['tests/Unit/XTest.php'],
                'forbidden_changes' => ['no provider calls'],
                'test_command' => 'php artisan test --filter XTest',
                // architecture_doc_command intentionally omitted (conditional).
                'rollback_plan' => 'git revert the block commit.',
                'success_metric' => 'test green, gate fail-closed.',
            ],
            'step' => 'cold_tests_and_guardrails',
            'completed_steps' => [],
            'stop_conditions' => [],
        ]);

        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_PROCEED, $r['verdict']);
        $this->assertSame(AtlasImplementationPlanningAndRolloutService::NEXT_EXECUTE_BLOCK, $r['required_next_action']);
        $this->assertTrue($r['may_execute_step']);
        // Eight mandatory fields (nine total minus the conditional one).
        $this->assertSame(8, $r['shape']['mandatory_total']);
        $this->assertSame(8, $r['shape']['declared_count']);
        $this->assertTrue($r['shape']['well_formed']);
    }

    /**
     * Block Shape: a block missing a mandatory field (here the rollback plan and
     * success metric) is not well-formed => blocked with fix_block, naming the
     * missing fields. The conditional architecture/doc command being absent does
     * NOT count as missing.
     */
    public function test_block_missing_mandatory_shape_fields_is_blocked(): void
    {
        $r = $this->service()->evaluate([
            'shape' => [
                'objective' => 'Wire scanner report.',
                'owner_files' => ['app/Services/Y.php'],
                'hot_files_to_avoid' => ['app/Kernel.php'],
                'allowed_files' => ['app/Services/Y.php'],
                'forbidden_changes' => ['no runtime'],
                'test_command' => 'php artisan test --filter YTest',
                // rollback_plan + success_metric missing on purpose.
                'rollback_plan' => '',
                'success_metric' => '',
            ],
            'step' => 'read_only_scanners_and_reports',
            'completed_steps' => ['cold_tests_and_guardrails'],
        ]);

        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_BLOCKED, $r['verdict']);
        $this->assertSame(AtlasImplementationPlanningAndRolloutService::NEXT_FIX_BLOCK, $r['required_next_action']);
        $this->assertFalse($r['may_execute_step']);
        $this->assertFalse($r['shape']['well_formed']);
        $this->assertSame(['rollback_plan', 'success_metric'], $r['shape']['missing']);
        // The conditional field is not counted as a missing mandatory field.
        $this->assertNotContains('architecture_doc_command', $r['shape']['missing']);
    }

    /**
     * Rollout Order: surface exposure (step 5) may not begin before cold tests,
     * scanners, DTO contracts and fail-closed runtime adapters (steps 1-4) are
     * complete. A fully-shaped block at surface_exposure with only step 1 done
     * is blocked, listing steps 2-4 as missing prerequisites. This is the doc's
     * "do not make autonomy powerful before it is observable and reversible".
     */
    public function test_rollout_order_blocks_surface_before_runtime_adapters(): void
    {
        $r = $this->service()->evaluate([
            'shape' => $this->fullShape(),
            'step' => 'surface_exposure',
            'completed_steps' => ['cold_tests_and_guardrails'],
            'stop_conditions' => [],
        ]);

        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_BLOCKED, $r['verdict']);
        $this->assertFalse($r['rollout']['in_order']);
        $this->assertSame(5, $r['rollout']['rank']);
        $this->assertSame([
            'read_only_scanners_and_reports',
            'dto_schema_contracts',
            'runtime_adapters_behind_fail_closed_gates',
        ], $r['rollout']['missing_prerequisites']);
    }

    /**
     * Stop Conditions are absolute: "runtime change would bypass
     * Kernel/Policy/Receipt/Ledger" forces a stop even when the block is fully
     * shaped and the rollout step is in order.
     */
    public function test_stop_condition_overrides_a_valid_block(): void
    {
        $r = $this->service()->evaluate([
            'shape' => $this->fullShape(),
            'step' => 'runtime_adapters_behind_fail_closed_gates',
            'completed_steps' => [
                'cold_tests_and_guardrails',
                'read_only_scanners_and_reports',
                'dto_schema_contracts',
            ],
            'stop_conditions' => [
                'runtime_bypasses_kernel_policy_receipt_ledger' => true,
            ],
        ]);

        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_STOP, $r['verdict']);
        $this->assertSame(AtlasImplementationPlanningAndRolloutService::NEXT_STOP_AND_REPORT, $r['required_next_action']);
        $this->assertFalse($r['may_execute_step']);
        $this->assertContains('runtime_bypasses_kernel_policy_receipt_ledger', $r['stop_conditions']['triggered']);
    }

    /**
     * Done Definition gates the final automation step. With all prerequisites
     * complete but the block not done (tests not yet passing/explained), turning
     * on automation is blocked and the unmet condition is reported.
     */
    public function test_final_automation_step_requires_done_definition(): void
    {
        $r = $this->service()->evaluate([
            'shape' => $this->fullShape(),
            'step' => 'automation',
            'completed_steps' => [
                'cold_tests_and_guardrails',
                'read_only_scanners_and_reports',
                'dto_schema_contracts',
                'runtime_adapters_behind_fail_closed_gates',
                'surface_exposure',
                'promotion_metrics',
            ],
            'stop_conditions' => [],
            'done' => [
                'delta_scoped' => true,
                'tests_pass_or_explained' => false,
                'git_diff_check_passes' => true,
                // docs/architecture not changed => those conditions don't apply.
            ],
            'docs_changed' => false,
            'structural_contracts_changed' => false,
        ]);

        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_BLOCKED, $r['verdict']);
        $this->assertFalse($r['done']['done']);
        $this->assertSame(['tests_pass_or_explained'], $r['done']['unmet']);
        // A condition whose trigger is off is treated as not-applicable.
        $this->assertFalse($r['done']['conditions']['docs_health_passes']['applicable']);
        $this->assertFalse($r['done']['conditions']['architecture_validation_passes']['applicable']);
    }

    /**
     * Done Definition, conditional rule: when docs changed, docs-health becomes
     * applicable and must pass; a fully-prerequisited, fully-shaped, done-in-all-
     * other-respects block at automation is blocked until docs-health passes.
     */
    public function test_done_docs_health_applies_only_when_docs_changed(): void
    {
        $base = [
            'shape' => $this->fullShape(),
            'step' => 'automation',
            'completed_steps' => [
                'cold_tests_and_guardrails',
                'read_only_scanners_and_reports',
                'dto_schema_contracts',
                'runtime_adapters_behind_fail_closed_gates',
                'surface_exposure',
                'promotion_metrics',
            ],
            'stop_conditions' => [],
            'done' => [
                'delta_scoped' => true,
                'tests_pass_or_explained' => true,
                'git_diff_check_passes' => true,
                'docs_health_passes' => false,
                'architecture_validation_passes' => false,
            ],
            'structural_contracts_changed' => false,
        ];

        // docs NOT changed => docs-health not applicable => block is done => proceed.
        $noDocs = $this->service()->evaluate($base + ['docs_changed' => false]);
        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_PROCEED, $noDocs['verdict']);
        $this->assertTrue($noDocs['done']['done']);

        // docs changed => docs-health applicable and false => blocked.
        $withDocs = $this->service()->evaluate(array_merge($base, ['docs_changed' => true]));
        $this->assertSame(AtlasImplementationPlanningAndRolloutService::VERDICT_BLOCKED, $withDocs['verdict']);
        $this->assertTrue($withDocs['done']['conditions']['docs_health_passes']['applicable']);
        $this->assertSame(['docs_health_passes'], $withDocs['done']['unmet']);
    }

    /**
     * The canonical rollout order is exactly the seven documented steps in order.
     */
    public function test_rollout_order_is_the_seven_documented_steps(): void
    {
        $this->assertSame([
            'cold_tests_and_guardrails',
            'read_only_scanners_and_reports',
            'dto_schema_contracts',
            'runtime_adapters_behind_fail_closed_gates',
            'surface_exposure',
            'promotion_metrics',
            'automation',
        ], $this->service()->rolloutOrder());
    }

    /**
     * A fully-declared Block Shape used by the rollout-order and stop-condition
     * tests so those tests isolate their own rule.
     *
     * @return array<string,mixed>
     */
    private function fullShape(): array
    {
        return [
            'objective' => 'Promote rollout block.',
            'owner_files' => ['app/Services/Z.php'],
            'hot_files_to_avoid' => ['app/Kernel.php'],
            'allowed_files' => ['app/Services/Z.php'],
            'forbidden_changes' => ['no bypass of gate'],
            'test_command' => 'php artisan test --filter ZTest',
            'architecture_doc_command' => 'php artisan atlas:ai:architecture-validate',
            'rollback_plan' => 'git revert the block commit.',
            'success_metric' => 'gate green, reversible.',
        ];
    }
}
