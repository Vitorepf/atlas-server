<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApWorkIntakeContract
{
    private const SCHEMA_VERSION = 'atlas.ap_work_intake_contract.v1';

    public function __construct(
        private readonly AtlasApChangeImpactReadModel $changeImpact,
        private readonly AtlasApCreationDecisionContract $creationDecision,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @return array<string,mixed>
     */
    public function evaluate(
        string $workTitle,
        array $intendedPaths = [],
        ?string $docsApPath = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $title = trim($workTitle);
        $slug = $proposedSlug ?? $this->slugFromTitle($title);
        $impact = $this->changeImpact->report($intendedPaths, $docsApPath);
        $creation = $this->creationDecision->decide($docsApPath, $requestedApNumber, $slug);
        $violations = $this->inputViolations($title, $slug);
        $recommendation = $this->recommendation($impact, $creation, $violations);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $recommendation['status'],
            'mode' => 'read_only_work_intake_contract',
            'authority' => 'ap_work_intake_only_no_file_writes',
            'work_title' => $title,
            'proposed_slug' => $slug,
            'requested_ap_number' => $requestedApNumber,
            'recommendation' => $recommendation,
            'violations' => $violations,
            'intended_path_count' => count(array_values(array_unique(array_filter($intendedPaths)))),
            'change_impact' => [
                'schema_version' => $impact['schema_version'],
                'status' => $impact['status'],
                'impacted_ap_count' => $impact['impacted_ap_count'],
                'impacted_aps' => $impact['impacted_aps'],
                'uncovered_changed_path_count' => $impact['uncovered_changed_path_count'],
                'uncovered_changed_paths' => $impact['uncovered_changed_paths'],
                'next_action' => $impact['next_action'],
            ],
            'creation_decision' => [
                'schema_version' => $creation['schema_version'],
                'status' => $creation['status'],
                'allowed' => $creation['allowed'],
                'next_suggested_number' => $creation['next_suggested_number'],
                'recommended_doc_path' => $creation['recommended_doc_path'],
                'violations' => $creation['violations'],
            ],
            'next_action' => $recommendation['next_action'],
            'guardrails' => [
                'writes_files' => false,
                'creates_ap_docs' => false,
                'edits_existing_ap_docs' => false,
                'runs_semantic_ai_matching' => false,
                'requires_agent_to_review_recommended_ap_before_editing' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputViolations(string $title, string $slug): array
    {
        $violations = [];

        if ($title === '') {
            $violations[] = [
                'reason' => 'work_title_required',
            ];
        }

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $violations[] = [
                'reason' => 'work_slug_must_be_lowercase_kebab_case',
                'slug' => $slug,
            ];
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $impact
     * @param  array<string,mixed>  $creation
     * @param  array<int,array<string,mixed>>  $violations
     * @return array<string,mixed>
     */
    private function recommendation(array $impact, array $creation, array $violations): array
    {
        if ($violations !== []) {
            return [
                'status' => 'blocked',
                'decision' => 'repair_intake_inputs',
                'reason' => 'intake_inputs_invalid',
                'next_action' => 'repair_work_title_or_slug_before_ap_intake',
            ];
        }

        if (($impact['status'] ?? null) !== 'ok') {
            return [
                'status' => 'blocked',
                'decision' => 'repair_ap_governance',
                'reason' => 'ap_governance_attention',
                'next_action' => 'repair_ap_governance_before_work_intake',
            ];
        }

        if ((int) $impact['impacted_ap_count'] > 0 && (int) $impact['uncovered_changed_path_count'] === 0) {
            return [
                'status' => 'existing_ap_review_required',
                'decision' => 'update_existing_ap_scope',
                'reason' => 'intended_paths_already_documented',
                'target_aps' => $impact['impacted_aps'],
                'next_action' => 'review_impacted_aps_then_edit_existing_scope_with_tests',
            ];
        }

        if ((int) $impact['uncovered_changed_path_count'] > 0 && $creation['allowed'] === true) {
            return [
                'status' => 'new_ap_allowed',
                'decision' => 'create_new_ap_for_uncovered_scope',
                'reason' => 'intended_paths_include_uncovered_scope',
                'recommended_doc_path' => $creation['recommended_doc_path'],
                'next_suggested_number' => $creation['next_suggested_number'],
                'next_action' => 'create_new_ap_via_ap192_template_then_update_related_paths',
            ];
        }

        if ((int) $impact['uncovered_changed_path_count'] > 0) {
            return [
                'status' => 'blocked',
                'decision' => 'new_ap_needed_but_creation_blocked',
                'reason' => 'creation_decision_blocked',
                'next_action' => 'repair_creation_decision_before_creating_new_ap',
            ];
        }

        if ($creation['allowed'] === true) {
            return [
                'status' => 'new_ap_allowed',
                'decision' => 'create_new_ap_for_declared_work',
                'reason' => 'no_existing_ap_impact_detected',
                'recommended_doc_path' => $creation['recommended_doc_path'],
                'next_suggested_number' => $creation['next_suggested_number'],
                'next_action' => 'create_new_ap_via_ap192_template_before_code',
            ];
        }

        return [
            'status' => 'blocked',
            'decision' => 'creation_decision_blocked',
            'reason' => 'ap_creation_not_allowed',
            'next_action' => 'repair_creation_decision_before_work_intake',
        ];
    }

    private function slugFromTitle(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));

        return preg_replace('/-+/', '-', $slug) ?? '';
    }
}
