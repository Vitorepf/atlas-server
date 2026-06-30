<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricMacroBatchAcceptanceSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricMacroBatchAcceptanceSynthesizerTest extends TestCase
{
    private AtlasTaskFabricMacroBatchAcceptanceSynthesizer $synth;

    protected function setUp(): void
    {
        $this->synth = new AtlasTaskFabricMacroBatchAcceptanceSynthesizer;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_id'              => 'task-'.uniqid(),
            'objective'            => 'implement unique novel service component',
            'allowed_files'        => ['app/Services/Foo.php'],
            'acceptance_criteria'  => ['must pass phpunit tests'],
            'test_commands'        => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
        ], $overrides);
    }

    private function input(array $batch, array $candidateAcceptance = []): array
    {
        return ['batch' => $batch, 'candidate_acceptance' => $candidateAcceptance];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        foreach (['schema', 'synthesized_acceptance', 'rejected_acceptance', 'batch_level_gates', 'per_task_coverage_gaps'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasTaskFabricMacroBatchAcceptanceSynthesizer::SCHEMA, $result['schema']);
    }

    // ── AC2: five batch-level gates always emitted ────────────────────────────

    public function test_all_five_batch_level_gates_are_present(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $expectedGates = [
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_STRUCTURAL_LEVERAGE,
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_NON_DUPLICATION,
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_INTEGRATION_EFFECT,
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_WORKER_IMPLEMENTABILITY,
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_EVIDENCE_STRENGTH,
        ];

        foreach ($expectedGates as $gate) {
            $this->assertContains($gate, $result['batch_level_gates'], "Missing gate: {$gate}");
        }
    }

    public function test_five_synthesized_acceptance_criteria_match_five_gates(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $this->assertCount(5, $result['synthesized_acceptance']);
    }

    // ── AC2: structural leverage ──────────────────────────────────────────────

    public function test_batch_spanning_multiple_directories_passes_leverage(): void
    {
        $batch = [
            $this->task(['allowed_files' => ['app/Services/Foo.php']]),
            $this->task(['allowed_files' => ['tests/Unit/FooTest.php']]),
        ];

        $result   = $this->synth->synthesize($this->input($batch));
        $leverage = $result['synthesized_acceptance'][0];

        $this->assertStringContainsString('span', strtolower($leverage));
    }

    // ── AC2: non-duplication ──────────────────────────────────────────────────

    public function test_nearly_identical_objectives_flagged_in_duplication_criterion(): void
    {
        $batch = [
            $this->task(['task_id' => 'a', 'objective' => 'implement entropy restoration planner service batch']),
            $this->task(['task_id' => 'b', 'objective' => 'implement entropy restoration planner service batch']),
        ];

        $result = $this->synth->synthesize($this->input($batch));

        $this->assertStringContainsString('overlap', strtolower($result['synthesized_acceptance'][1]));
    }

    public function test_distinct_objectives_pass_non_duplication(): void
    {
        $batch = [
            $this->task(['task_id' => 'a', 'objective' => 'implement entropy restoration planner']),
            $this->task(['task_id' => 'b', 'objective' => 'implement regression oracle autonomy']),
        ];

        $result = $this->synth->synthesize($this->input($batch));

        $this->assertStringNotContainsString('overlap', strtolower($result['synthesized_acceptance'][1]));
    }

    // ── AC2: integration effect ───────────────────────────────────────────────

    public function test_task_with_test_command_passes_integration_effect(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $this->assertStringContainsString('runnable', strtolower($result['synthesized_acceptance'][2]));
    }

    public function test_batch_with_no_test_commands_fails_integration_effect(): void
    {
        $batch  = [$this->task(['test_commands' => []])];
        $result = $this->synth->synthesize($this->input($batch));

        $this->assertStringContainsString('no task', strtolower($result['synthesized_acceptance'][2]));
    }

    // ── AC2: worker implementability ──────────────────────────────────────────

    public function test_task_with_no_allowed_files_flagged_in_implementability(): void
    {
        $batch  = [$this->task(['task_id' => 'bad', 'allowed_files' => []])];
        $result = $this->synth->synthesize($this->input($batch));

        $this->assertStringContainsString('bad', $result['synthesized_acceptance'][3]);
    }

    // ── AC2: evidence strength ────────────────────────────────────────────────

    public function test_task_with_no_evidence_flagged(): void
    {
        $batch  = [$this->task(['task_id' => 'noevid', 'test_commands' => [], 'acceptance_criteria' => []])];
        $result = $this->synth->synthesize($this->input($batch));

        $this->assertStringContainsString('noevid', $result['synthesized_acceptance'][4]);
    }

    // ── AC3: vague candidate acceptance is rejected ───────────────────────────

    public function test_vague_candidate_is_rejected(): void
    {
        $result = $this->synth->synthesize($this->input(
            [$this->task()],
            ['This should be better quality overall'],
        ));

        $this->assertCount(1, $result['rejected_acceptance']);
        $this->assertSame(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::REJECTION_VAGUE_ACCEPTANCE,
            $result['rejected_acceptance'][0]['rejection_reason'],
        );
    }

    public function test_runnable_candidate_is_synthesized_not_rejected(): void
    {
        $result = $this->synth->synthesize($this->input(
            [$this->task()],
            ['Runnable: ./vendor/bin/phpunit tests/IntegrationTest.php must pass.'],
        ));

        $this->assertCount(0, $result['rejected_acceptance']);
        $this->assertCount(6, $result['synthesized_acceptance']); // 5 gates + 1 candidate
    }

    public function test_deterministic_candidate_is_synthesized_not_rejected(): void
    {
        $result = $this->synth->synthesize($this->input(
            [$this->task()],
            ['Batch must return exactly 5 repair specs when given 5 diagnostics.'],
        ));

        $this->assertCount(0, $result['rejected_acceptance']);
    }

    // ── Per-task coverage gaps ────────────────────────────────────────────────

    public function test_task_missing_allowed_files_has_coverage_gap(): void
    {
        $batch  = [$this->task(['task_id' => 't1', 'allowed_files' => []])];
        $result = $this->synth->synthesize($this->input($batch));

        $gaps = array_column($result['per_task_coverage_gaps'], 'task_id');
        $this->assertContains('t1', $gaps);
    }

    public function test_complete_task_has_no_coverage_gaps(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task(['task_id' => 'complete'])]));

        $this->assertSame([], $result['per_task_coverage_gaps']);
    }

    // ── Empty batch ───────────────────────────────────────────────────────────

    public function test_empty_batch_still_emits_five_gates(): void
    {
        $result = $this->synth->synthesize($this->input([]));

        $this->assertCount(5, $result['batch_level_gates']);
    }

    // ── AC2: narrow evidence for multi-capability task ────────────────────────

    public function test_multi_capability_task_with_single_test_command_gets_narrow_evidence_gap(): void
    {
        // allowed_files span 2 top-level dirs → multi-capability; only 1 test command → narrow evidence
        $batch = [$this->task([
            'task_id'       => 'wide',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'test_commands' => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
        ])];

        $result = $this->synth->synthesize($this->input($batch));

        $gap = $result['per_task_coverage_gaps'][0] ?? null;
        $this->assertNotNull($gap, 'Expected a coverage gap for narrow evidence');
        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GAP_NARROW_EVIDENCE,
            $gap['missing_coverage'],
        );
    }

    public function test_single_capability_task_does_not_get_narrow_evidence_gap(): void
    {
        // allowed_files in ONE top-level dir → single-capability; 1 test command is sufficient
        $batch = [$this->task([
            'task_id'       => 'narrow',
            'allowed_files' => ['app/Services/Foo.php'],
            'test_commands' => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
        ])];

        $result = $this->synth->synthesize($this->input($batch));

        $gaps = array_column($result['per_task_coverage_gaps'], 'task_id');
        $this->assertNotContains('narrow', $gaps, 'Single-capability task should not get narrow evidence gap');
    }

    public function test_multi_capability_task_with_two_test_commands_no_narrow_gap(): void
    {
        $batch = [$this->task([
            'task_id'       => 'covered',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'test_commands' => [
                './vendor/bin/phpunit tests/Unit/FooTest.php',
                './vendor/bin/phpunit tests/Feature/FooFeatureTest.php',
            ],
        ])];

        $result = $this->synth->synthesize($this->input($batch));

        // No gaps at all — two evidence channels satisfy the multi-capability requirement
        $this->assertSame([], $result['per_task_coverage_gaps']);
    }

    public function test_capability_count_field_triggers_multi_capability_detection(): void
    {
        $batch = [$this->task([
            'task_id'         => 'multi',
            'capability_count' => 3,
            'allowed_files'   => ['app/Services/Foo.php'], // single dir, but count=3
            'test_commands'   => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
        ])];

        $result = $this->synth->synthesize($this->input($batch));

        $gap = $result['per_task_coverage_gaps'][0] ?? null;
        $this->assertNotNull($gap);
        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GAP_NARROW_EVIDENCE,
            $gap['missing_coverage'],
        );
    }

    // ── AC3: conditional gates ────────────────────────────────────────────────

    public function test_outcome_learning_gate_emitted_for_multi_task_batch(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task(), $this->task()]));

        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_OUTCOME_LEARNING,
            $result['batch_level_gates'],
        );
    }

    public function test_outcome_learning_gate_not_emitted_for_single_task(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $this->assertNotContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_OUTCOME_LEARNING,
            $result['batch_level_gates'],
        );
    }

    public function test_anti_template_farm_gate_emitted_when_template_similarity_present(): void
    {
        $batch = [$this->task(['template_similarity' => 0.6])];

        $result = $this->synth->synthesize($this->input($batch));

        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_ANTI_TEMPLATE_FARM,
            $result['batch_level_gates'],
        );
    }

    public function test_anti_template_farm_gate_not_emitted_without_template_similarity(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $this->assertNotContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_ANTI_TEMPLATE_FARM,
            $result['batch_level_gates'],
        );
    }

    public function test_rollback_respec_safety_gate_emitted_when_modifies_existing_files(): void
    {
        $batch = [$this->task(['modifies_existing_files' => true])];

        $result = $this->synth->synthesize($this->input($batch));

        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_ROLLBACK_RESPEC_SAFETY,
            $result['batch_level_gates'],
        );
    }

    public function test_rollback_respec_safety_gate_emitted_when_rollback_required(): void
    {
        $batch = [$this->task(['rollback_required' => true])];

        $result = $this->synth->synthesize($this->input($batch));

        $this->assertContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_ROLLBACK_RESPEC_SAFETY,
            $result['batch_level_gates'],
        );
    }

    public function test_rollback_safety_gate_not_emitted_for_standard_task(): void
    {
        $result = $this->synth->synthesize($this->input([$this->task()]));

        $this->assertNotContains(
            AtlasTaskFabricMacroBatchAcceptanceSynthesizer::GATE_ROLLBACK_RESPEC_SAFETY,
            $result['batch_level_gates'],
        );
    }
}
