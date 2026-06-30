<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRegressionRepairTaskSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRegressionRepairTaskSynthesizerTest extends TestCase
{
    private AtlasExternalBrainRegressionRepairTaskSynthesizer $synthesizer;

    protected function setUp(): void
    {
        $this->synthesizer = new AtlasExternalBrainRegressionRepairTaskSynthesizer;
    }

    private function good(array $overrides = []): array
    {
        return array_merge([
            'diagnostic_id'               => 'diag-001',
            'gate_name'                   => 'spec_quality_gate',
            'target_path'                 => 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainFoo.php',
            'runnable_proof_command'      => './vendor/bin/phpunit tests/Unit/FooTest.php',
            'failing_reason'              => 'gate rejected spec missing runnable proof',
            'forbidden_self_target'       => false,
            'requires_manual_operator_action' => false,
            'is_bootstrap_only'           => false,
            'unblock_plan'                => '',
        ], $overrides);
    }

    private function input(array ...$diagnostics): array
    {
        return ['diagnostics' => $diagnostics];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        foreach (['schema', 'repair_specs', 'rejected_diagnostics', 'allowed_files', 'acceptance_criteria', 'unblock_reason'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainRegressionRepairTaskSynthesizer::SCHEMA, $result['schema']);
    }

    // ── AC2: concrete diagnostic produces repair spec ─────────────────────────

    public function test_concrete_diagnostic_is_promoted(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $this->assertCount(1, $result['repair_specs']);
        $this->assertCount(0, $result['rejected_diagnostics']);
    }

    public function test_repair_spec_has_required_fields(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));
        $spec   = $result['repair_specs'][0];

        foreach (['diagnostic_id', 'gate_name', 'target_path', 'allowed_files', 'acceptance_criteria', 'unblock_reason'] as $k) {
            $this->assertArrayHasKey($k, $spec, "Missing field: {$k}");
        }
    }

    public function test_acceptance_criteria_includes_runnable_proof_command(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));
        $acText = implode(' ', $result['repair_specs'][0]['acceptance_criteria']);

        $this->assertStringContainsString('./vendor/bin/phpunit tests/Unit/FooTest.php', $acText);
    }

    // ── AC3: vague diagnostic is rejected ────────────────────────────────────

    public function test_missing_gate_name_is_rejected_as_vague(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good(['gate_name' => ''])));

        $this->assertCount(0, $result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_missing_target_path_is_rejected_as_vague(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good(['target_path' => ''])));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_missing_runnable_proof_command_is_rejected_as_vague(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good(['runnable_proof_command' => ''])));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    // ── AC3: forbidden self-target without unblock plan ───────────────────────

    public function test_forbidden_self_target_without_unblock_plan_is_rejected(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good([
            'forbidden_self_target' => true,
            'unblock_plan'          => '',
        ])));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_FORBIDDEN_TARGET,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_forbidden_self_target_with_unblock_plan_is_promoted(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good([
            'forbidden_self_target' => true,
            'unblock_plan'          => 'Extract to separate module first, then fix gate',
        ])));

        $this->assertCount(1, $result['repair_specs']);
    }

    // ── AC3: manual operator steady state ────────────────────────────────────

    public function test_manual_operator_required_steady_state_is_rejected(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good([
            'requires_manual_operator_action' => true,
            'is_bootstrap_only'               => false,
        ])));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_MANUAL_OPERATOR,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_manual_operator_bootstrap_only_is_promoted(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good([
            'requires_manual_operator_action' => true,
            'is_bootstrap_only'               => true,
        ])));

        $this->assertCount(1, $result['repair_specs']);
    }

    // ── AC3: rejection hierarchy (vague beats forbidden) ─────────────────────

    public function test_vague_beats_forbidden_in_rejection_hierarchy(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good([
            'gate_name'             => '',   // vague
            'forbidden_self_target' => true, // also forbidden
            'unblock_plan'          => '',
        ])));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_VAGUE,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    // ── Top-level allowed_files and acceptance_criteria aggregation ───────────

    public function test_top_level_allowed_files_aggregates_from_all_specs(): void
    {
        $d1 = $this->good(['diagnostic_id' => 'a', 'target_path' => 'app/Services/Foo.php']);
        $d2 = $this->good(['diagnostic_id' => 'b', 'target_path' => 'app/Services/Bar.php']);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $this->assertContains('app/Services/Foo.php', $result['allowed_files']);
        $this->assertContains('app/Services/Bar.php', $result['allowed_files']);
    }

    // ── Empty diagnostics ─────────────────────────────────────────────────────

    public function test_empty_diagnostics_returns_empty_repair_specs(): void
    {
        $result = $this->synthesizer->synthesize(['diagnostics' => []]);

        $this->assertSame([], $result['repair_specs']);
        $this->assertSame([], $result['rejected_diagnostics']);
        $this->assertNull($result['unblock_reason']);
    }

    // ── component fallback for target ─────────────────────────────────────────

    public function test_component_field_used_when_target_path_absent(): void
    {
        $diag = $this->good(['target_path' => '', 'component' => 'AtlasSpecQualityGate']);

        $result = $this->synthesizer->synthesize($this->input($diag));

        $this->assertCount(1, $result['repair_specs']);
        $this->assertSame('AtlasSpecQualityGate', $result['repair_specs'][0]['target_path']);
    }

    // ── AC2: impl + test files in allowed_files ───────────────────────────────

    public function test_allowed_files_includes_impl_and_derived_test_file(): void
    {
        $diag = $this->good(['target_path' => 'app/Services/Ai/ExternalBrain/AtlasFoo.php']);

        $result = $this->synthesizer->synthesize($this->input($diag));

        $files = $result['repair_specs'][0]['allowed_files'];
        $this->assertContains('app/Services/Ai/ExternalBrain/AtlasFoo.php', $files);
        $this->assertContains('tests/Unit/Services/Ai/ExternalBrain/AtlasFooTest.php', $files);
    }

    // ── AC2: test-only target is rejected ────────────────────────────────────

    public function test_test_file_as_target_is_rejected(): void
    {
        $diag = $this->good(['target_path' => 'tests/Unit/SomeTest.php']);

        $result = $this->synthesizer->synthesize($this->input($diag));

        $this->assertCount(0, $result['repair_specs']);
        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_TEST_ONLY,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    public function test_target_ending_in_test_php_is_rejected(): void
    {
        $diag = $this->good(['target_path' => 'app/Tests/AtlasFooTest.php']);

        $result = $this->synthesizer->synthesize($this->input($diag));

        $this->assertSame(
            AtlasExternalBrainRegressionRepairTaskSynthesizer::REJECTION_TEST_ONLY,
            $result['rejected_diagnostics'][0]['rejection_reason'],
        );
    }

    // ── AC3: grouping diagnostics by shared impl target ───────────────────────

    public function test_two_diagnostics_sharing_target_are_grouped_into_one_spec(): void
    {
        $sharedTarget = 'app/Services/Ai/ExternalBrain/AtlasShared.php';
        $d1 = $this->good([
            'diagnostic_id'          => 'diag-a',
            'gate_name'              => 'gate_alpha',
            'target_path'            => $sharedTarget,
            'runnable_proof_command' => './vendor/bin/phpunit tests/Unit/AlphaTest.php',
        ]);
        $d2 = $this->good([
            'diagnostic_id'          => 'diag-b',
            'gate_name'              => 'gate_beta',
            'target_path'            => $sharedTarget,
            'runnable_proof_command' => './vendor/bin/phpunit tests/Unit/BetaTest.php',
        ]);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $this->assertCount(1, $result['repair_specs']);
    }

    public function test_macro_spec_has_deduped_impl_file(): void
    {
        $sharedTarget = 'app/Services/Ai/ExternalBrain/AtlasShared.php';
        $d1 = $this->good(['diagnostic_id' => 'a', 'target_path' => $sharedTarget]);
        $d2 = $this->good(['diagnostic_id' => 'b', 'target_path' => $sharedTarget]);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $implCount = count(array_filter(
            $result['repair_specs'][0]['allowed_files'],
            static fn (string $f): bool => $f === $sharedTarget,
        ));
        $this->assertSame(1, $implCount);
    }

    public function test_macro_spec_acceptance_contains_gate_criteria_for_each_diagnostic(): void
    {
        $sharedTarget = 'app/Services/Ai/ExternalBrain/AtlasShared.php';
        $d1 = $this->good([
            'diagnostic_id' => 'a', 'gate_name' => 'gate_alpha', 'target_path' => $sharedTarget,
            'runnable_proof_command' => './vendor/bin/phpunit tests/AlphaTest.php',
        ]);
        $d2 = $this->good([
            'diagnostic_id' => 'b', 'gate_name' => 'gate_beta', 'target_path' => $sharedTarget,
            'runnable_proof_command' => './vendor/bin/phpunit tests/BetaTest.php',
        ]);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $acText = implode(' ', $result['repair_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('gate_alpha', $acText);
        $this->assertStringContainsString('gate_beta', $acText);
    }

    public function test_different_targets_produce_separate_specs(): void
    {
        $d1 = $this->good(['diagnostic_id' => 'a', 'target_path' => 'app/Services/Foo.php']);
        $d2 = $this->good(['diagnostic_id' => 'b', 'target_path' => 'app/Services/Bar.php']);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $this->assertCount(2, $result['repair_specs']);
    }

    // ── AC2: promoted repair spec field contract ────────────────────────────────

    public function test_promoted_spec_has_task_packet_id_seed(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $spec = $result['repair_specs'][0];
        $this->assertArrayHasKey('task_packet_id', $spec);
        $this->assertIsString($spec['task_packet_id']);
        $this->assertNotEmpty($spec['task_packet_id']);
    }

    public function test_promoted_spec_task_packet_id_is_stable_for_same_target(): void
    {
        $r1 = $this->synthesizer->synthesize($this->input($this->good()));
        $r2 = $this->synthesizer->synthesize($this->input($this->good()));

        $this->assertSame($r1['repair_specs'][0]['task_packet_id'], $r2['repair_specs'][0]['task_packet_id']);
    }

    public function test_promoted_spec_has_target_path_and_impl_plus_test_allowed_files(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $spec = $result['repair_specs'][0];
        $this->assertArrayHasKey('target_path', $spec);
        $this->assertCount(2, $spec['allowed_files']);
        $hasImpl = false;
        $hasTest = false;
        foreach ($spec['allowed_files'] as $f) {
            if (str_ends_with($f, 'Test.php')) {
                $hasTest = true;
            } else {
                $hasImpl = true;
            }
        }
        $this->assertTrue($hasImpl, 'allowed_files must include an implementation file');
        $this->assertTrue($hasTest, 'allowed_files must include a test file');
    }

    public function test_promoted_spec_acceptance_criteria_contains_runnable_proof_command(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $acText = implode(' ', $result['repair_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('./vendor/bin/phpunit tests/Unit/FooTest.php', $acText);
    }

    public function test_promoted_spec_has_required_evidence(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $spec = $result['repair_specs'][0];
        $this->assertArrayHasKey('required_evidence', $spec);
        $this->assertContains('tests_or_gates_result', $spec['required_evidence']);
    }

    public function test_promoted_spec_has_unblock_reason(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $this->assertArrayHasKey('unblock_reason', $result['repair_specs'][0]);
        $this->assertNotEmpty($result['repair_specs'][0]['unblock_reason']);
    }

    public function test_promoted_spec_has_source_diagnostic_ids_list(): void
    {
        $d1 = $this->good(['diagnostic_id' => 'diag-a']);
        $d2 = $this->good(['diagnostic_id' => 'diag-b']);

        $result = $this->synthesizer->synthesize($this->input($d1, $d2));

        $spec = $result['repair_specs'][0];
        $this->assertArrayHasKey('source_diagnostic_ids', $spec);
        $this->assertSame(['diag-a', 'diag-b'], $spec['source_diagnostic_ids']);
    }

    public function test_synthesize_with_new_fields_is_deterministic(): void
    {
        $input = $this->input($this->good());
        $r1 = $this->synthesizer->synthesize($input);
        $r2 = $this->synthesizer->synthesize($input);

        $this->assertSame(json_encode($r1), json_encode($r2));
    }

    // ── AC1: grouping_summary + safety_rejections ─────────────────────────────

    public function test_output_has_grouping_summary_and_safety_rejections_keys(): void
    {
        $result = $this->synthesizer->synthesize($this->input($this->good()));

        $this->assertArrayHasKey('grouping_summary', $result);
        $this->assertArrayHasKey('safety_rejections', $result);
    }

    public function test_grouping_summary_reports_diagnostic_count_per_target(): void
    {
        $result = $this->synthesizer->synthesize($this->input(
            $this->good(['diagnostic_id' => 'diag-a']),
            $this->good(['diagnostic_id' => 'diag-b']),
        ));

        $this->assertSame(1, count($result['grouping_summary']));
        $this->assertSame(2, $result['grouping_summary'][0]['diagnostic_count']);
    }

    public function test_safety_rejections_includes_forbidden_target_but_not_vague_diagnostic(): void
    {
        $vague = ['diagnostic_id' => 'diag-vague'];
        $forbidden = $this->good([
            'diagnostic_id' => 'diag-forbidden',
            'forbidden_self_target' => true,
            'unblock_plan' => '',
        ]);

        $result = $this->synthesizer->synthesize($this->input($vague, $forbidden));

        $safetyIds = array_column($result['safety_rejections'], 'diagnostic_id');
        $this->assertContains('diag-forbidden', $safetyIds);
        $this->assertNotContains('diag-vague', $safetyIds);
    }
}
