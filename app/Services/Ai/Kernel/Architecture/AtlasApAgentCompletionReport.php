<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentCompletionReport
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_completion_report.v1';

    public function __construct(
        private readonly AtlasApAgentSessionGate $sessionGate,
        private readonly AtlasApValidationEvidenceContract $validationEvidenceContract,
        private readonly AtlasApCompletionChecklistContract $completionChecklist,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @return array<string,mixed>
     */
    public function report(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $gate = $this->sessionGate->gate(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidenceShape = $this->validationEvidenceContract->validate($validationEvidence);
        $checklist = $this->completionChecklist->evaluate($validationEvidence);
        $status = $this->status($gate, $evidenceShape, $checklist);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_completion_report',
            'authority' => 'ap_agent_completion_report_only_no_command_execution',
            'work_title' => trim($workTitle),
            'resolved_target_ap' => $gate['resolved_target_ap'] ?? null,
            'intended_paths' => array_values($intendedPaths),
            'session_gate' => [
                'schema_version' => $gate['schema_version'],
                'status' => $gate['status'],
                'resolved_target_ap' => $gate['resolved_target_ap'],
                'repair_proposal_count' => data_get($gate, 'repair_gate.proposal_count'),
                'repair_reasons' => data_get($gate, 'repair_gate.reasons'),
                'next_action' => $gate['next_action'],
            ],
            'completion_checklist' => [
                'schema_version' => $checklist['schema_version'],
                'status' => $checklist['status'],
                'passed_count' => $checklist['passed_count'],
                'failed_count' => $checklist['failed_count'],
                'failed_checks' => $checklist['failed_checks'],
                'required_commands' => $checklist['required_commands'],
                'next_action' => $checklist['next_action'],
            ],
            'validation_evidence_shape' => [
                'schema_version' => $evidenceShape['schema_version'],
                'status' => $evidenceShape['status'],
                'error_count' => $evidenceShape['error_count'],
                'errors' => $evidenceShape['errors'],
                'next_action' => $evidenceShape['next_action'],
            ],
            'validation_evidence' => $this->publicEvidence($validationEvidence),
            'next_action' => $this->nextAction($status, $gate, $evidenceShape, $checklist),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'reruns_validation' => false,
                'marks_complete_without_gate' => false,
                'marks_complete_without_valid_evidence_shape' => false,
                'marks_complete_without_checklist' => false,
                'replaces_human_review' => false,
                'requires_explicit_validation_evidence' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $evidenceShape
     * @param  array<string,mixed>  $checklist
     */
    private function status(array $gate, array $evidenceShape, array $checklist): string
    {
        $gateStatus = (string) ($gate['status'] ?? 'attention');
        if (! in_array($gateStatus, ['ready_for_existing_ap_work', 'ready_for_new_ap_work'], true)) {
            return 'blocked_by_session_gate';
        }

        if (($evidenceShape['status'] ?? null) !== 'valid_shape') {
            return 'blocked_by_validation_evidence_shape';
        }

        return ($checklist['status'] ?? null) === 'complete'
            ? 'complete'
            : 'incomplete';
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $evidenceShape
     * @param  array<string,mixed>  $checklist
     */
    private function nextAction(string $status, array $gate, array $evidenceShape, array $checklist): string
    {
        if ($status === 'blocked_by_session_gate') {
            return (string) ($gate['next_action'] ?? 'repair_session_gate_before_claiming_completion');
        }

        if ($status === 'blocked_by_validation_evidence_shape') {
            return (string) ($evidenceShape['next_action'] ?? 'repair_validation_evidence_shape_before_completion_report');
        }

        if ($status === 'incomplete') {
            return (string) ($checklist['next_action'] ?? 'finish_failed_checks_before_claiming_complete');
        }

        return 'report_completion_summary_with_validation_evidence';
    }

    /**
     * @param  array<string,mixed>  $validationEvidence
     * @return array<string,mixed>
     */
    private function publicEvidence(array $validationEvidence): array
    {
        return [
            'code_or_doc_changes_scoped' => $validationEvidence['code_or_doc_changes_scoped'] ?? null,
            'focused_tests_passed' => $validationEvidence['focused_tests_passed'] ?? null,
            'docs_health_ok' => $validationEvidence['docs_health_ok'] ?? null,
            'architecture_validate_ok' => $validationEvidence['architecture_validate_ok'] ?? null,
            'git_diff_check_passed' => $validationEvidence['git_diff_check_passed'] ?? null,
            'ap_doc_updated' => $validationEvidence['ap_doc_updated'] ?? null,
            'uncovered_changed_path_count' => count((array) ($validationEvidence['uncovered_changed_paths'] ?? [])),
            'uncovered_paths_reviewed' => $validationEvidence['uncovered_paths_reviewed'] ?? false,
            'commands' => array_values((array) ($validationEvidence['commands'] ?? [])),
            'notes' => array_values((array) ($validationEvidence['notes'] ?? [])),
        ];
    }
}
