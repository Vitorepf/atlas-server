<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApCompletionChecklistContract
{
    private const SCHEMA_VERSION = 'atlas.ap_completion_checklist_contract.v1';

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function evaluate(array $evidence): array
    {
        $checks = [
            $this->booleanCheck($evidence, 'code_or_doc_changes_scoped', 'changes_are_scoped_to_declared_ap'),
            $this->booleanCheck($evidence, 'focused_tests_passed', 'focused_tests_passed'),
            $this->booleanCheck($evidence, 'docs_health_ok', 'docs_health_ok'),
            $this->booleanCheck($evidence, 'architecture_validate_ok', 'architecture_validate_ok'),
            $this->booleanCheck($evidence, 'git_diff_check_passed', 'git_diff_check_passed'),
            $this->booleanCheck($evidence, 'ap_doc_updated', 'ap_doc_updated_or_not_needed_with_reason'),
            $this->impactCheck($evidence),
        ];

        $failed = array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'mode' => 'read_only_completion_checklist',
            'authority' => 'ap_completion_checklist_only_no_command_execution',
            'passed_count' => count($checks) - count($failed),
            'failed_count' => count($failed),
            'checks' => $checks,
            'failed_checks' => $failed,
            'required_commands' => [
                'focused_tests' => 'php artisan test <focused-test-files-or-filter>',
                'docs_health' => 'atlas engineering knowledge docs-health',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'architecture_readiness' => 'php artisan atlas:ai:architecture-readiness --json',
                'knowledge_sync' => 'atlas engineering knowledge sync --prune',
                'code_intelligence_index' => 'atlas engineering knowledge index-code --prune',
                'diff_check' => 'git diff --check',
            ],
            'next_action' => $failed === []
                ? 'mark_ap_block_complete_and_report_validation_evidence'
                : 'finish_failed_checks_before_claiming_ap_complete',
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'marks_complete_without_evidence' => false,
                'replaces_human_review' => false,
                'requires_explicit_validation_evidence' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function booleanCheck(array $evidence, string $key, string $id): array
    {
        $value = $evidence[$key] ?? null;

        return [
            'id' => $id,
            'status' => $value === true ? 'passed' : 'failed',
            'evidence_key' => $key,
            'provided' => array_key_exists($key, $evidence),
            'value' => $value,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function impactCheck(array $evidence): array
    {
        $uncovered = (array) ($evidence['uncovered_changed_paths'] ?? []);
        $reviewed = ($evidence['uncovered_paths_reviewed'] ?? false) === true;
        $passed = $uncovered === [] || $reviewed;

        return [
            'id' => 'ap_change_impact_reviewed',
            'status' => $passed ? 'passed' : 'failed',
            'evidence_key' => 'uncovered_changed_paths',
            'provided' => array_key_exists('uncovered_changed_paths', $evidence),
            'uncovered_changed_path_count' => count($uncovered),
            'uncovered_changed_paths' => array_values($uncovered),
            'uncovered_paths_reviewed' => $reviewed,
        ];
    }
}
