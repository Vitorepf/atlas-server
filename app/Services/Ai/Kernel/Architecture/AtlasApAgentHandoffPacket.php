<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_handoff_packet.v1';

    public function __construct(
        private readonly AtlasApWorkIntakeContract $workIntake,
        private readonly AtlasApAgentContextPack $contextPack,
        private readonly AtlasApCompletionChecklistContract $completionChecklist,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @return array<string,mixed>
     */
    public function packet(
        string $workTitle,
        array $intendedPaths = [],
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $intake = $this->workIntake->evaluate($workTitle, $intendedPaths, $docsApPath, $requestedApNumber, $proposedSlug);
        $resolvedTarget = $targetAp ?? $this->targetFromIntake($intake);
        $context = $resolvedTarget === null ? null : $this->contextPack->pack($resolvedTarget, $docsApPath);
        $completion = $this->completionChecklist->evaluate([
            'code_or_doc_changes_scoped' => false,
            'focused_tests_passed' => false,
            'docs_health_ok' => false,
            'architecture_validate_ok' => false,
            'git_diff_check_passed' => false,
            'ap_doc_updated' => false,
            'uncovered_changed_paths' => data_get($intake, 'change_impact.uncovered_changed_paths', []),
        ]);

        $status = $this->status($intake, $context);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_agent_handoff_packet',
            'authority' => 'ap_agent_handoff_only_no_file_writes',
            'work_title' => trim($workTitle),
            'resolved_target_ap' => $resolvedTarget,
            'intake' => [
                'schema_version' => $intake['schema_version'],
                'status' => $intake['status'],
                'recommendation' => $intake['recommendation'],
                'change_impact' => $intake['change_impact'],
                'creation_decision' => $intake['creation_decision'],
                'next_action' => $intake['next_action'],
            ],
            'target_context' => $context,
            'completion_starting_point' => [
                'schema_version' => $completion['schema_version'],
                'status' => $completion['status'],
                'failed_count' => $completion['failed_count'],
                'failed_checks' => $completion['failed_checks'],
                'required_commands' => $completion['required_commands'],
            ],
            'next_action' => $this->nextAction($status, $intake, $context),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'creates_ap_docs' => false,
                'edits_ap_docs' => false,
                'replaces_agent_judgement' => false,
                'requires_agent_to_execute_and_report_completion_checklist' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $intake
     */
    private function targetFromIntake(array $intake): int|string|null
    {
        $target = data_get($intake, 'recommendation.target_aps.0.ap');
        if (is_string($target) && $target !== '') {
            return $target;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $intake
     * @param  array<string,mixed>|null  $context
     */
    private function status(array $intake, ?array $context): string
    {
        if (($intake['status'] ?? null) === 'blocked') {
            return 'blocked';
        }

        if ($context !== null && ($context['status'] ?? null) !== 'ready') {
            return 'attention';
        }

        if ($context !== null) {
            return 'existing_ap_ready';
        }

        if (($intake['status'] ?? null) === 'new_ap_allowed') {
            return 'new_ap_ready';
        }

        return 'existing_ap_ready';
    }

    /**
     * @param  array<string,mixed>  $intake
     * @param  array<string,mixed>|null  $context
     */
    private function nextAction(string $status, array $intake, ?array $context): string
    {
        if ($status === 'blocked') {
            return (string) ($intake['next_action'] ?? 'repair_handoff_inputs_before_work');
        }

        if ($status === 'attention') {
            return (string) ($context['next_action'] ?? 'repair_target_context_before_work');
        }

        if ($status === 'new_ap_ready') {
            return 'create_or_update_ap_doc_via_ap192_then_execute_completion_checklist';
        }

        return 'review_target_context_then_execute_scoped_change_and_completion_checklist';
    }
}
