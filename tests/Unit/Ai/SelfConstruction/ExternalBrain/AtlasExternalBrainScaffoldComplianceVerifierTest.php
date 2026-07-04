<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldComplianceVerifier;
use Tests\TestCase;

final class AtlasExternalBrainScaffoldComplianceVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainScaffoldComplianceVerifier
    {
        return new AtlasExternalBrainScaffoldComplianceVerifier();
    }

    private function runnableTask(string $id = 'task-1'): array
    {
        return [
            'task_id'            => $id,
            'acceptance_criteria' => [
                'The class must implement compute().',
                'Running ./vendor/bin/phpunit tests/Unit/FooTest.php produces green output.',
            ],
        ];
    }

    private function compliantRun(array $taskIds = ['task-1']): array
    {
        $tasks = array_map(fn (string $id) => $this->runnableTask($id), $taskIds);
        $batchItems = array_map(fn (string $id) => ['task_id' => $id], $taskIds);

        return [
            'run' => [
                'artifacts' => [
                    'evidence_list'              => ['evidence-a', 'evidence-b'],
                    'dedup_proof'                => ['proof-entry-1'],
                    'semantic_dedup_proof'       => ['similarity:0.12:task-1-vs-task-2'],
                    'critique_report'            => ['critique-entry-1'],
                    'implementability_simulation' => ['sim-result:feasible'],
                    'final_batch'                => $batchItems,
                ],
                'produced_tasks' => $tasks,
            ],
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->verifier()->verify([]);

        $this->assertSame(AtlasExternalBrainScaffoldComplianceVerifier::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->verifier()->verify([]);

        foreach (['schema', 'compliant', 'missing_steps', 'weak_artifacts', 'credited_tasks', 'refused_tasks'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── compliant run ─────────────────────────────────────────────────────────

    public function test_compliant_when_all_steps_present_and_tasks_credited(): void
    {
        $result = $this->verifier()->verify($this->compliantRun());

        $this->assertTrue($result['compliant']);
        $this->assertSame([], $result['missing_steps']);
        $this->assertSame([], $result['weak_artifacts']);
        $this->assertSame([], $result['refused_tasks']);
        $this->assertContains('task-1', $result['credited_tasks']);
    }

    // ── missing: evidence_intake ──────────────────────────────────────────────

    public function test_fails_when_evidence_list_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['evidence_list']);

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('evidence_intake', $result['missing_steps']);
    }

    public function test_weak_artifact_when_evidence_list_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['evidence_list'] = [];

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('evidence_list', $names);
        $this->assertFalse($result['compliant']);
    }

    // ── missing: duplicate_search ─────────────────────────────────────────────

    public function test_fails_when_dedup_proof_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['dedup_proof']);

        $result = $this->verifier()->verify($input);

        $this->assertContains('duplicate_search', $result['missing_steps']);
        $this->assertFalse($result['compliant']);
    }

    public function test_weak_artifact_when_dedup_proof_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['dedup_proof'] = [];

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('dedup_proof', $names);
    }

    // ── missing: critique_pass ────────────────────────────────────────────────

    public function test_fails_when_critique_report_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['critique_report']);

        $result = $this->verifier()->verify($input);

        $this->assertContains('critique_pass', $result['missing_steps']);
        $this->assertFalse($result['compliant']);
    }

    public function test_weak_artifact_when_critique_report_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['critique_report'] = '';

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('critique_report', $names);
    }

    // ── missing: final_queue_validation ──────────────────────────────────────

    public function test_fails_when_final_batch_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['final_batch']);

        $result = $this->verifier()->verify($input);

        $this->assertContains('final_queue_validation', $result['missing_steps']);
    }

    public function test_weak_artifact_when_final_batch_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['final_batch'] = [];

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('final_batch', $names);
    }

    // ── runnable_acceptance_proof ─────────────────────────────────────────────

    public function test_refuses_task_without_runnable_criterion(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['The class must be well-structured.', 'The output should be valid.'],
        ]];

        $result = $this->verifier()->verify($input);

        $refusedIds = array_column($result['refused_tasks'], 'task_id');
        $this->assertContains('task-1', $refusedIds);
        $this->assertFalse($result['compliant']);
    }

    public function test_runnable_acceptance_proof_step_fails_when_task_refused_for_no_runnable(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['No runnable criterion here.'],
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertContains('runnable_acceptance_proof', $result['missing_steps']);
    }

    public function test_phpunit_in_criterion_makes_it_runnable(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['./vendor/bin/phpunit tests/Unit/MyTest.php'],
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertContains('task-1', $result['credited_tasks']);
    }

    public function test_artisan_in_criterion_makes_it_runnable(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['php artisan test tests/Unit/MyTest.php'],
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertContains('task-1', $result['credited_tasks']);
    }

    // ── task not in final_batch ───────────────────────────────────────────────

    public function test_refuses_task_not_in_final_batch(): void
    {
        $input = $this->compliantRun(['task-1']);
        $input['run']['produced_tasks'][] = $this->runnableTask('task-extra');

        $result = $this->verifier()->verify($input);

        $refusedIds = array_column($result['refused_tasks'], 'task_id');
        $this->assertContains('task-extra', $refusedIds);
    }

    // ── multiple tasks ────────────────────────────────────────────────────────

    public function test_multiple_tasks_credited_when_all_valid(): void
    {
        $result = $this->verifier()->verify($this->compliantRun(['t1', 't2', 't3']));

        $this->assertCount(3, $result['credited_tasks']);
        $this->assertSame([], $result['refused_tasks']);
        $this->assertTrue($result['compliant']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->compliantRun(['task-1', 'task-2']);

        $this->assertSame($this->verifier()->verify($input), $this->verifier()->verify($input));
    }

    // ── empty run ─────────────────────────────────────────────────────────────

    public function test_empty_run_fails_with_all_mandatory_steps_missing(): void
    {
        $result = $this->verifier()->verify([]);

        $this->assertFalse($result['compliant']);
        $this->assertContains('evidence_intake', $result['missing_steps']);
        $this->assertContains('duplicate_search', $result['missing_steps']);
        $this->assertContains('critique_pass', $result['missing_steps']);
        $this->assertContains('final_queue_validation', $result['missing_steps']);
    }

    // ── AC2: semantic_dedup_proof ─────────────────────────────────────────────

    public function test_fails_when_semantic_dedup_proof_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['semantic_dedup_proof']);

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('semantic_dedup_proof', $result['missing_steps']);
    }

    public function test_weak_artifact_when_semantic_dedup_proof_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['semantic_dedup_proof'] = [];

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('semantic_dedup_proof', $names);
        $this->assertFalse($result['compliant']);
    }

    // ── AC2: implementability_simulation ──────────────────────────────────────

    public function test_fails_when_implementability_simulation_missing(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['implementability_simulation']);

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('implementability_simulation', $result['missing_steps']);
    }

    public function test_weak_artifact_when_implementability_simulation_empty(): void
    {
        $input = $this->compliantRun();
        $input['run']['artifacts']['implementability_simulation'] = [];

        $result = $this->verifier()->verify($input);

        $names = array_column($result['weak_artifacts'], 'artifact_name');
        $this->assertContains('implementability_simulation', $names);
        $this->assertFalse($result['compliant']);
    }

    // ── AC4: high-quality run credits; multiple weak runs fail ────────────────

    public function test_high_quality_run_credits_all_tasks(): void
    {
        $result = $this->verifier()->verify($this->compliantRun(['t1', 't2']));

        $this->assertTrue($result['compliant']);
        $this->assertContains('t1', $result['credited_tasks']);
        $this->assertContains('t2', $result['credited_tasks']);
        $this->assertEmpty($result['refused_tasks']);
    }

    public function test_missing_both_new_artifacts_reported_together(): void
    {
        $input = $this->compliantRun();
        unset($input['run']['artifacts']['semantic_dedup_proof']);
        unset($input['run']['artifacts']['implementability_simulation']);

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('semantic_dedup_proof',        $result['missing_steps']);
        $this->assertContains('implementability_simulation', $result['missing_steps']);
    }

    // ── verifyAmplificationScaffold: small-model scaffold quality contract ────

    private function fullScaffold(array $overrides = []): array
    {
        return [
            'sections' => array_merge([
                'replay' => ['steps' => ['re_run_deterministically']],
                'critique' => ['reviewers' => ['adversarial']],
                'anti_proxy' => ['checks' => ['no_proxy_metric_gaming']],
                'escalation' => ['fallback_tier' => 'frontier_model'],
                'evidence_capture' => ['records' => ['runnable_proof']],
            ], $overrides),
        ];
    }

    public function test_scaffold_with_all_five_sections_is_trusted(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold($this->fullScaffold());

        $this->assertSame('trusted', $result['compliance_status']);
        $this->assertTrue($result['trusted']);
        $this->assertSame([], $result['missing_sections']);
        $this->assertSame([], $result['repair_hints']);
    }

    public function test_missing_replay_section_is_blocking(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold(['sections' => [
            'critique' => ['reviewers' => ['adversarial']],
            'anti_proxy' => ['checks' => ['x']],
            'escalation' => ['fallback_tier' => 'frontier_model'],
            'evidence_capture' => ['records' => ['x']],
        ]]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertFalse($result['trusted']);
        $this->assertContains('replay', $result['missing_sections']);
        $this->assertArrayHasKey('replay', $result['repair_hints']);
    }

    public function test_missing_critique_section_is_blocking(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold([
            'sections' => array_diff_key($this->fullScaffold()['sections'], ['critique' => true]),
        ]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertContains('critique', $result['missing_sections']);
    }

    public function test_missing_anti_proxy_section_is_blocking(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold([
            'sections' => array_diff_key($this->fullScaffold()['sections'], ['anti_proxy' => true]),
        ]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertContains('anti_proxy', $result['missing_sections']);
    }

    public function test_missing_escalation_section_is_blocking(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold([
            'sections' => array_diff_key($this->fullScaffold()['sections'], ['escalation' => true]),
        ]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertContains('escalation', $result['missing_sections']);
    }

    public function test_missing_evidence_capture_section_is_blocking(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold([
            'sections' => array_diff_key($this->fullScaffold()['sections'], ['evidence_capture' => true]),
        ]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertContains('evidence_capture', $result['missing_sections']);
    }

    public function test_empty_section_value_counts_as_missing(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold($this->fullScaffold(['replay' => []]));

        $this->assertContains('replay', $result['missing_sections']);
    }

    public function test_empty_scaffold_reports_all_five_sections_missing_with_hints(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold([]);

        $this->assertSame('blocked', $result['compliance_status']);
        $this->assertSame(['replay', 'critique', 'anti_proxy', 'escalation', 'evidence_capture'], $result['missing_sections']);
        $this->assertCount(5, $result['repair_hints']);
        foreach ($result['missing_sections'] as $section) {
            $this->assertNotEmpty($result['repair_hints'][$section]);
        }
    }

    public function test_required_sections_constant_is_exposed(): void
    {
        $verifier = new AtlasExternalBrainScaffoldComplianceVerifier;
        $result = $verifier->verifyAmplificationScaffold($this->fullScaffold());

        $this->assertSame(['replay', 'critique', 'anti_proxy', 'escalation', 'evidence_capture'], $result['required_sections']);
    }

    // ── AC1: outputs without code or queue grounding fail with missing_grounding ──

    public function test_task_without_code_or_queue_grounding_is_refused_with_missing_grounding(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['phpunit must exit 0 and everything must be fine.'],
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('missing_grounding', $result['missing_steps']);
        $refused = $result['refused_tasks'][0];
        $this->assertSame('task-1', $refused['task_id']);
        $this->assertStringContainsString('missing_grounding', $refused['reason']);
    }

    public function test_task_grounded_via_target_path_is_not_missing_grounding(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['phpunit must exit 0.'],
            'target_path'         => 'app/Services/Ai/Foo.php',
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertNotContains('missing_grounding', $result['missing_steps']);
        $this->assertContains('task-1', $result['credited_tasks']);
    }

    public function test_task_grounded_via_grounding_refs_is_not_missing_grounding(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['phpunit must exit 0.'],
            'grounding_refs'      => ['app/Services/Ai/Foo.php:42'],
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertNotContains('missing_grounding', $result['missing_steps']);
        $this->assertContains('task-1', $result['credited_tasks']);
    }

    // ── AC2: proxy-only observability outputs fail with proxy_work_detected ──

    public function test_proxy_observability_task_is_refused_with_proxy_work_detected(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'             => 'task-1',
            'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/FooTest.php produces green output.'],
            'work_classification' => 'proxy_observability',
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertFalse($result['compliant']);
        $this->assertContains('proxy_work_detected', $result['missing_steps']);
        $refused = $result['refused_tasks'][0];
        $this->assertStringContainsString('proxy_work_detected', $refused['reason']);
        $this->assertNotContains('task-1', $result['credited_tasks']);
    }

    public function test_real_capability_work_classification_is_not_flagged_proxy(): void
    {
        $result = $this->verifier()->verify($this->compliantRun());

        $this->assertNotContains('proxy_work_detected', $result['missing_steps']);
    }

    // ── AC3: grounded task with runnable acceptance + impl/test scope passes compliance ──

    public function test_grounded_task_with_runnable_acceptance_and_scope_passes_compliance(): void
    {
        $input = $this->compliantRun();
        $input['run']['produced_tasks'] = [[
            'task_id'            => 'task-1',
            'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/Ai/FooTest.php produces green output.'],
            'target_path'         => 'app/Services/Ai/Foo.php',
            'work_classification' => 'real_capability',
        ]];

        $result = $this->verifier()->verify($input);

        $this->assertTrue($result['compliant']);
        $this->assertContains('task-1', $result['credited_tasks']);
        $this->assertSame([], $result['refused_tasks']);
    }

    // ── valueProofVerify: value_proof, impact_trace, missing_value_proof ──

    public function test_task_with_runnable_acceptance_but_no_value_proof_is_refused(): void
    {
        $tasks = [
            [
                'task_id' => 'task-1',
                'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/FooTest.php'],
            ],
        ];
        $result = $this->verifier()->valueProofVerify($tasks);
        $this->assertSame([], $result['credited_tasks']);
        $this->assertSame(['task-1'], $result['missing_value_proof']);
        $this->assertCount(1, $result['refused_tasks']);
        $this->assertStringContainsString('missing_value_proof', $result['refused_tasks'][0]['reason']);
    }

    public function test_task_with_value_proof_is_credited(): void
    {
        $tasks = [
            [
                'task_id' => 'task-1',
                'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/FooTest.php'],
                'value_proof' => ['reduced_give_back_rate_by_15_percent'],
            ],
        ];
        $result = $this->verifier()->valueProofVerify($tasks);
        $this->assertSame(['task-1'], $result['credited_tasks']);
        $this->assertSame([], $result['missing_value_proof']);
        $this->assertSame([], $result['refused_tasks']);
    }

    public function test_task_with_impact_trace_is_credited(): void
    {
        $tasks = [
            [
                'task_id' => 'task-1',
                'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/FooTest.php'],
                'impact_trace' => ['improved_worker_routing_latency'],
            ],
        ];
        $result = $this->verifier()->valueProofVerify($tasks);
        $this->assertSame(['task-1'], $result['credited_tasks']);
        $this->assertSame([], $result['missing_value_proof']);
    }

    public function test_missing_value_proof_makes_compliant_false_without_hiding_other_failures(): void
    {
        $tasks = [
            [
                'task_id' => 'task-1',
                'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/FooTest.php'],
            ],
            [
                'task_id' => 'task-2',
                'acceptance_criteria' => ['Running ./vendor/bin/phpunit tests/Unit/BarTest.php'],
                'value_proof' => ['verified'],
            ],
        ];
        $result = $this->verifier()->valueProofVerify($tasks);
        $this->assertSame(['task-2'], $result['credited_tasks']);
        $this->assertSame(['task-1'], $result['missing_value_proof']);
        $this->assertCount(1, $result['refused_tasks']);
    }
}
