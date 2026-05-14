<?php

namespace App\Services\Ai\Programming\Sdd\Agents;

/**
 * Canonical Atlas SDD agent role registry.
 *
 * Per agents-and-mcp-contract.md:112-128, the SDD pipeline names 14 roles.
 * Each role declares: input, output, allowed actions, forbidden actions,
 * required evidence and required gates. Roles are templates the AtlasDecide
 * service consumes when binding work to a runtime agent — they do not
 * execute on their own.
 *
 * Hard law: no role may bypass a Decision Receipt or escalate autonomy.
 */
class SddAgentRoleRegistry
{
    /** @var array<string,array<string,mixed>> */
    private const ROLES = [
        'context_scout' => [
            'name' => 'Context Scout',
            'pipeline_stage' => 'context_discovery',
            'input' => ['operation_envelope'],
            'output' => ['context_findings'],
            'allowed_actions' => ['read_repo', 'read_docs', 'read_memory'],
            'forbidden_actions' => ['write_files', 'mutate_state', 'open_pull_request'],
            'required_evidence' => ['context_pack_digest'],
            'required_gates' => [],
        ],
        'product_analyst' => [
            'name' => 'Product Analyst',
            'pipeline_stage' => 'intent_to_outcome',
            'input' => ['operation_envelope', 'context_pack'],
            'output' => ['business_outcome', 'success_signals'],
            'allowed_actions' => ['read_repo', 'read_docs', 'attach_evidence'],
            'forbidden_actions' => ['write_files', 'open_pull_request', 'merge'],
            'required_evidence' => ['business_outcome_summary'],
            'required_gates' => [],
        ],
        'business_rule_miner' => [
            'name' => 'Business Rule Miner',
            'pipeline_stage' => 'rule_extraction',
            'input' => ['operation_envelope', 'context_pack'],
            'output' => ['confirmed_rules', 'hypothesised_rules'],
            'allowed_actions' => ['read_repo', 'read_docs'],
            'forbidden_actions' => ['promote_hypothesis_without_human_review', 'write_files'],
            'required_evidence' => ['rule_source_citations'],
            'required_gates' => [],
        ],
        'spec_compiler' => [
            'name' => 'Spec Compiler',
            'pipeline_stage' => 'spec_compilation',
            'input' => ['intent', 'context_pack'],
            'output' => ['atlas_sdd_spec'],
            'allowed_actions' => ['compile_spec'],
            'forbidden_actions' => ['skip_critic', 'mark_approved_without_review'],
            'required_evidence' => ['compiled_spec_hash'],
            'required_gates' => ['spec-before-code'],
        ],
        'spec_critic' => [
            'name' => 'Spec Critic',
            'pipeline_stage' => 'spec_review',
            'input' => ['atlas_sdd_spec', 'context_pack'],
            'output' => ['critic_report', 'clarification_questions'],
            'allowed_actions' => ['critique_spec'],
            'forbidden_actions' => ['silently_amend_spec'],
            'required_evidence' => ['critic_status'],
            'required_gates' => [],
        ],
        'architecture_agent' => [
            'name' => 'Architecture Agent',
            'pipeline_stage' => 'architecture_decision',
            'input' => ['atlas_sdd_spec', 'context_pack'],
            'output' => ['architecture_choice', 'risks'],
            'allowed_actions' => ['read_repo', 'attach_evidence'],
            'forbidden_actions' => ['write_files', 'rotate_secrets', 'change_provider_routing'],
            'required_evidence' => ['architecture_decision_summary'],
            'required_gates' => ['feature-placement'],
        ],
        'plan_compiler' => [
            'name' => 'Plan Compiler',
            'pipeline_stage' => 'plan_compilation',
            'input' => ['atlas_sdd_spec', 'context_pack'],
            'output' => ['atlas_sdd_plan'],
            'allowed_actions' => ['compile_plan'],
            'forbidden_actions' => ['skip_test_plan', 'omit_rollback'],
            'required_evidence' => ['plan_content_hash'],
            'required_gates' => [],
        ],
        'task_compiler' => [
            'name' => 'Task Compiler',
            'pipeline_stage' => 'task_compilation',
            'input' => ['atlas_sdd_plan'],
            'output' => ['atlas_sdd_tasks'],
            'allowed_actions' => ['compile_tasks'],
            'forbidden_actions' => ['ignore_plan_dependencies', 'skip_acceptance_refs'],
            'required_evidence' => ['task_codes'],
            'required_gates' => [],
        ],
        'execution_agent' => [
            'name' => 'Execution Agent',
            'pipeline_stage' => 'runtime_execution',
            'input' => ['atlas_decision_receipt', 'atlas_sdd_tasks'],
            'output' => ['execution_result', 'evidence_refs'],
            'allowed_actions' => ['write_allowed_files', 'run_validation_commands'],
            'forbidden_actions' => ['write_outside_receipt_scope', 'mutate_kernel_policy', 'rotate_secrets'],
            'required_evidence' => ['execution_output_hash', 'written_files'],
            'required_gates' => ['scope-guard', 'evidence-required'],
        ],
        'qa_agent' => [
            'name' => 'QA Agent',
            'pipeline_stage' => 'quality_assurance',
            'input' => ['execution_result', 'atlas_sdd_tasks'],
            'output' => ['test_run_result'],
            'allowed_actions' => ['run_tests', 'attach_evidence'],
            'forbidden_actions' => ['skip_failing_tests', 'mutate_test_assertions'],
            'required_evidence' => ['test_command_output'],
            'required_gates' => ['evidence-required'],
        ],
        'security_agent' => [
            'name' => 'Security Agent',
            'pipeline_stage' => 'security_review',
            'input' => ['atlas_sdd_spec', 'execution_result'],
            'output' => ['security_findings'],
            'allowed_actions' => ['scan_for_secrets', 'review_auth', 'attach_evidence'],
            'forbidden_actions' => ['emit_secrets', 'auto_apply_security_changes'],
            'required_evidence' => ['security_scan_report'],
            'required_gates' => ['evidence-required'],
        ],
        'drift_detector' => [
            'name' => 'Drift Detector',
            'pipeline_stage' => 'post_execution_audit',
            'input' => ['atlas_sdd_spec', 'execution_result'],
            'output' => ['atlas_sdd_drift_report'],
            'allowed_actions' => ['compare_spec_to_code', 'compare_spec_to_tests', 'compare_spec_to_evidence'],
            'forbidden_actions' => ['silently_widen_spec', 'auto_repair'],
            'required_evidence' => ['drift_report_id'],
            'required_gates' => [],
        ],
        'evidence_agent' => [
            'name' => 'Evidence Agent',
            'pipeline_stage' => 'evidence_ledger',
            'input' => ['execution_result', 'atlas_decision_receipt'],
            'output' => ['evidence_event_id'],
            'allowed_actions' => ['append_evidence_event'],
            'forbidden_actions' => ['mutate_existing_evidence', 'delete_evidence'],
            'required_evidence' => ['evidence_event_hash'],
            'required_gates' => ['evidence-required'],
        ],
        'learning_curator' => [
            'name' => 'Learning Curator',
            'pipeline_stage' => 'learning_proposal',
            'input' => ['atlas_sdd_drift_report', 'execution_result'],
            'output' => ['atlas_sdd_learning_proposal'],
            'allowed_actions' => ['propose_template_update', 'propose_policy_review'],
            'forbidden_actions' => [
                'mutate_kernel',
                'mutate_policy',
                'mutate_provider_routing',
                'mutate_memory_truth',
                'mutate_critical_runtime',
            ],
            'required_evidence' => ['proposal_summary'],
            'required_gates' => [],
        ],
    ];

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $name): array
    {
        if (! isset(self::ROLES[$name])) {
            throw new \InvalidArgumentException("unknown_sdd_agent_role:{$name}");
        }

        return self::ROLES[$name];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return self::ROLES;
    }

    /**
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema_version' => 'atlas.sdd_agent_role_registry.v1',
            'count' => count(self::ROLES),
            'roles' => array_map(
                static fn (string $key): array => array_merge(['key' => $key], self::ROLES[$key]),
                array_keys(self::ROLES),
            ),
        ];
    }

    public function isAllowedAction(string $roleName, string $action): bool
    {
        $role = $this->get($roleName);
        if (in_array($action, (array) ($role['forbidden_actions'] ?? []), true)) {
            return false;
        }

        return in_array($action, (array) ($role['allowed_actions'] ?? []), true);
    }
}
