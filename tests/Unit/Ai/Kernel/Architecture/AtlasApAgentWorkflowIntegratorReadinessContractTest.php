<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowIntegratorReadinessContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowIntegratorReadinessContractTest extends TestCase
{
    public function test_integrator_readiness_allows_manual_integration_after_handoff_and_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-readiness-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-integrator-readiness.md', implode("\n", [
                '---',
                'title: AP-970 Integrator Readiness',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContract.php',
                '---',
                '# AP-970 Integrator Readiness',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowIntegratorReadinessContract::class)->readiness(
                workTitle: 'Accept integrator readiness contract',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContract.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_integrator_readiness_contract.v1', $payload['schema_version']);
            $this->assertSame('ready_for_manual_integration', $payload['status']);
            $this->assertSame('read_only_integrator_readiness_contract', $payload['mode']);
            $this->assertSame('ap_agent_workflow_integrator_readiness_only_no_execution', $payload['authority']);
            $this->assertSame('ready_for_integrator_review', data_get($payload, 'handoff_summary.status'));
            $this->assertSame('complete', data_get($payload, 'integrator_evidence.status'));
            $this->assertSame(6, data_get($payload, 'integrator_evidence.passed_count'));
            $this->assertSame('integrator_may_manually_apply_after_final_local_review', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.auto_merges_work'));
            $this->assertFalse(data_get($payload, 'guardrails.marks_ready_without_integrator_evidence'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_readiness_blocks_when_handoff_is_not_ready(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-readiness-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-integrator-readiness.md', "# AP-980 Integrator Readiness\n");

            $payload = app(AtlasApAgentWorkflowIntegratorReadinessContract::class)->readiness(
                workTitle: 'Blocked integrator readiness',
                intendedPaths: ['app/New/BlockedIntegratorReadiness.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_integrator_handoff', $payload['status']);
            $this->assertSame('blocked_by_human_decision_contract', data_get($payload, 'handoff_summary.status'));
            $this->assertSame('repair_integrator_handoff_before_readiness_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_readiness_blocks_incomplete_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-readiness-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-integrator-readiness.md', "# AP-990 Integrator Readiness\n");
            $evidence = $this->passingIntegratorEvidence();
            $evidence['confirmed_no_hot_file_conflict'] = false;

            $payload = app(AtlasApAgentWorkflowIntegratorReadinessContract::class)->readiness(
                workTitle: 'Incomplete integrator readiness',
                intendedPaths: ['app/New/IncompleteIntegratorReadiness.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $evidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('integrator_review_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'integrator_evidence.status'));
            $this->assertSame(['confirmed_no_hot_file_conflict'], data_get($payload, 'integrator_evidence.failed_keys'));
            $this->assertSame('complete_integrator_evidence_before_manual_integration', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_readiness_blocks_invalid_evidence_shape(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-readiness-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-integrator-readiness.md', "# AP-995 Integrator Readiness\n");

            $payload = app(AtlasApAgentWorkflowIntegratorReadinessContract::class)->readiness(
                workTitle: 'Invalid integrator readiness',
                intendedPaths: ['app/New/InvalidIntegratorReadiness.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: [
                    'reviewed_handoff_packet' => 'yes',
                    'reviewed_diff_scope' => true,
                    'reviewed_validation_output' => true,
                    'confirmed_no_hot_file_conflict' => true,
                    'confirmed_no_unrelated_reverts' => true,
                    'confirmed_manual_integration_owner' => true,
                    'surprise' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_integrator_evidence_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'integrator_evidence.status'));
            $this->assertSame(2, data_get($payload, 'integrator_evidence.shape_error_count'));
            $this->assertSame('fix_integrator_evidence_shape_before_readiness_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function passingValidationEvidence(): array
    {
        return [
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => true,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => [],
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContractTest.php'],
            'notes' => ['fixture validation only'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passingIntegratorEvidence(): array
    {
        return [
            'reviewed_handoff_packet' => true,
            'reviewed_diff_scope' => true,
            'reviewed_validation_output' => true,
            'confirmed_no_hot_file_conflict' => true,
            'confirmed_no_unrelated_reverts' => true,
            'confirmed_manual_integration_owner' => true,
            'commands' => ['git diff --check'],
            'notes' => ['manual integrator evidence fixture'],
        ];
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
