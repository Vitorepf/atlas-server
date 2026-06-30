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
                    'evidence_list'  => ['evidence-a', 'evidence-b'],
                    'dedup_proof'    => ['proof-entry-1'],
                    'critique_report' => ['critique-entry-1'],
                    'final_batch'    => $batchItems,
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
}
