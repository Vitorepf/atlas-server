<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiOperatorApproval;
use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseAgentWorkforceSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseDomainWorkloadAgentTemplateStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $companies = [];

        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $stack = (array) data_get($company, 'enterprise_domain_workload_agent_template_stack', []);
            $templates = array_values((array) ($stack['workload_agent_templates'] ?? []));
            $coverageMatrix = array_values((array) ($stack['flow_template_coverage_matrix'] ?? []));

            $templateRows = array_values(array_map(
                static function (array $template): array {
                    $subagents = array_values((array) ($template['subagents'] ?? []));
                    $requiredConnectors = array_values((array) data_get($template, 'connectors.required', []));
                    $distributionPackage = (array) ($template['distribution_package'] ?? []);
                    $toolPermissionMatrix = array_values((array) data_get($distributionPackage, 'connector_permission_manifest.tool_permission_matrix', []));
                    $sourcePatternReceipts = (array) ($template['source_pattern_receipts'] ?? []);
                    $operatorHandoffContract = (array) data_get($distributionPackage, 'operator_handoff_contract', []);
                    $guardrailContract = (array) data_get($distributionPackage, 'guardrail_contract', []);
                    $evalReplayRecipe = (array) data_get($distributionPackage, 'eval_replay_recipe', []);
                    $gates = [
                        'skills_bound' => count((array) ($template['skills'] ?? [])) >= 8,
                        'connectors_governed' => count($requiredConnectors) > 0
                            && count((array) data_get($template, 'connectors.governance', [])) >= 5,
                        'subagents_bound' => count($subagents) >= 3
                            && count(array_filter($subagents, static fn (array $subagent): bool => (bool) ($subagent['external_effects_allowed'] ?? true) === false)) >= 3,
                        'runtime_contract_enterprise_ready' => data_get($template, 'runtime_contract.session_mode') === 'long_running_supervised_session'
                            && data_get($template, 'runtime_contract.credential_binding') === 'managed_vault_reference_only'
                            && data_get($template, 'runtime_contract.tool_permission_model') === 'per_tool_permission_with_operator_scope'
                            && data_get($template, 'runtime_contract.audit_log') === 'decision_tool_call_artifact_and_handoff_receipts',
                        'approval_contract_blocks_external_effects' => (bool) data_get($template, 'approval_contract.operator_review_required', false)
                            && (bool) data_get($template, 'approval_contract.auto_send_post_pay_trade_publish_deploy_delete_allowed', true) === false,
                        'distribution_package_ready' => data_get($distributionPackage, 'plugin_manifest.permission_profile') === 'read_fixture_shadow_until_operator_scope'
                            && data_get($distributionPackage, 'managed_agent_cookbook.session_model') === 'long_running_supervised_session'
                            && data_get($distributionPackage, 'managed_agent_cookbook.credential_vault') === 'managed_vault_reference_only'
                            && (bool) data_get($distributionPackage, 'skill_bundle.always_on_context_allowed', true) === false
                            && (int) data_get($distributionPackage, 'skill_bundle.skill_count', 0) >= 8
                            && (int) data_get($distributionPackage, 'subagent_bundle.subagent_count', 0) >= 3
                            && (bool) data_get($distributionPackage, 'connector_permission_manifest.write_spend_trade_publish_deploy_delete_allowed', true) === false
                            && (bool) data_get($distributionPackage, 'audit_manifest.receipt_required', false)
                            && (bool) data_get($distributionPackage, 'audit_manifest.replay_manifest_required', false)
                            && is_string(data_get($distributionPackage, 'package_hash'))
                            && strlen((string) data_get($distributionPackage, 'package_hash')) === 64,
                        'rollout_plan_ready' => data_get($distributionPackage, 'rollout_plan.stage') === 'internal_shadow_candidate'
                            && count((array) data_get($distributionPackage, 'rollout_plan.install_steps', [])) >= 6
                            && count((array) data_get($distributionPackage, 'rollout_plan.smoke_suite', [])) >= 6
                            && count((array) data_get($distributionPackage, 'rollout_plan.promotion_gates', [])) >= 5
                            && count((array) data_get($distributionPackage, 'rollout_plan.rollback_plan', [])) >= 4
                            && data_get($distributionPackage, 'rollout_plan.evidence_sink') === 'evidence_ledger.domain_workload_agent_package'
                            && (bool) data_get($distributionPackage, 'rollout_plan.auto_promotion_allowed', true) === false
                            && (bool) data_get($distributionPackage, 'rollout_plan.external_execution_enabled_after_rollout', true) === false
                            && is_string(data_get($distributionPackage, 'rollout_plan.rollout_hash'))
                            && strlen((string) data_get($distributionPackage, 'rollout_plan.rollout_hash')) === 64,
                        'tool_permission_matrix_ready' => count($toolPermissionMatrix) >= count($requiredConnectors)
                            && count($requiredConnectors) > 0
                            && count(array_filter($toolPermissionMatrix, static fn (array $permission): bool => (string) ($permission['default_permission'] ?? '') === 'fixture_or_read_only_probe'
                                && (bool) ($permission['live_scope_requires_operator'] ?? false)
                                && (bool) ($permission['write_spend_trade_publish_deploy_delete_allowed'] ?? true) === false
                                && is_string($permission['permission_hash'] ?? null)
                                && strlen((string) ($permission['permission_hash'] ?? '')) === 64)) >= count($requiredConnectors)
                            && is_string(data_get($distributionPackage, 'connector_permission_manifest.permission_matrix_hash'))
                            && strlen((string) data_get($distributionPackage, 'connector_permission_manifest.permission_matrix_hash')) === 64,
                        'execution_surface_bindings_ready' => is_string(data_get($distributionPackage, 'execution_surface_bindings.cli_action'))
                            && str_contains((string) data_get($distributionPackage, 'execution_surface_bindings.cli_action'), 'enterprise-flow-run-queue-execute')
                            && is_string(data_get($distributionPackage, 'execution_surface_bindings.mcp_tool'))
                            && is_string(data_get($distributionPackage, 'execution_surface_bindings.desktop_panel'))
                            && data_get($distributionPackage, 'execution_surface_bindings.run_queue_contract') === 'enterprise_flow_run_queue_item.v1'
                            && (bool) data_get($distributionPackage, 'execution_surface_bindings.external_execution_allowed', true) === false
                            && is_string(data_get($distributionPackage, 'execution_surface_bindings.surface_binding_hash'))
                            && strlen((string) data_get($distributionPackage, 'execution_surface_bindings.surface_binding_hash')) === 64,
                        'fixture_smoke_contract_ready' => count((array) data_get($distributionPackage, 'fixture_smoke_contract.required_fixture_inputs', [])) >= 5
                            && count((array) data_get($distributionPackage, 'fixture_smoke_contract.replay_steps', [])) >= 7
                            && count((array) data_get($distributionPackage, 'fixture_smoke_contract.expected_artifacts', [])) >= 4
                            && count((array) data_get($distributionPackage, 'fixture_smoke_contract.pass_criteria', [])) >= 5
                            && count((array) data_get($distributionPackage, 'fixture_smoke_contract.policy_boundary_checks', [])) >= 6
                            && (bool) data_get($distributionPackage, 'fixture_smoke_contract.external_effects_allowed_during_smoke', true) === false
                            && is_string(data_get($distributionPackage, 'fixture_smoke_contract.fixture_hash'))
                            && strlen((string) data_get($distributionPackage, 'fixture_smoke_contract.fixture_hash')) === 64,
                        'source_pattern_receipts_bound' => ($sourcePatternReceipts['reference_pattern'] ?? null) === 'anthropic_financial_services_agents_generalized_to_domain_workloads'
                            && count((array) ($sourcePatternReceipts['framework_reference_ids'] ?? [])) >= 5
                            && count((array) ($sourcePatternReceipts['domain_source_refs'] ?? [])) >= 3
                            && (bool) ($sourcePatternReceipts['cross_source_verification_required'] ?? false)
                            && (bool) ($sourcePatternReceipts['unsupported_claim_blocker_enabled'] ?? false)
                            && is_string($sourcePatternReceipts['source_pattern_receipt_hash'] ?? null)
                            && strlen((string) ($sourcePatternReceipts['source_pattern_receipt_hash'] ?? '')) === 64,
                        'operator_handoff_contract_ready' => count((array) ($operatorHandoffContract['required_sections'] ?? [])) >= 7
                            && (bool) ($operatorHandoffContract['human_review_required'] ?? false)
                            && count((array) ($operatorHandoffContract['second_reviewer_required_for'] ?? [])) >= 8
                            && (bool) ($operatorHandoffContract['approval_scope_required_before_external_effect'] ?? false)
                            && is_string($operatorHandoffContract['handoff_contract_hash'] ?? null)
                            && strlen((string) ($operatorHandoffContract['handoff_contract_hash'] ?? '')) === 64,
                        'guardrail_contract_ready' => count((array) ($guardrailContract['blocked_operations'] ?? [])) >= 10
                            && count((array) ($guardrailContract['policy_checks'] ?? [])) >= 7
                            && (bool) ($guardrailContract['calendar_wait_blocker_enabled'] ?? true) === false
                            && (bool) ($guardrailContract['external_effects_allowed'] ?? true) === false
                            && is_string($guardrailContract['guardrail_contract_hash'] ?? null)
                            && strlen((string) ($guardrailContract['guardrail_contract_hash'] ?? '')) === 64,
                        'eval_replay_recipe_ready' => (int) ($evalReplayRecipe['minimum_fixture_cases'] ?? 0) >= 12
                            && count((array) ($evalReplayRecipe['replay_assertions'] ?? [])) >= 7
                            && count((array) ($evalReplayRecipe['benchmark_families'] ?? [])) >= 5
                            && (bool) ($evalReplayRecipe['promotion_requires_green_replay'] ?? false)
                            && (bool) ($evalReplayRecipe['external_effects_allowed_during_replay'] ?? true) === false
                            && is_string($evalReplayRecipe['eval_replay_recipe_hash'] ?? null)
                            && strlen((string) ($evalReplayRecipe['eval_replay_recipe_hash'] ?? '')) === 64,
                    ];
                    $readyGateCount = count(array_filter($gates));

                    $row = [
                        'schema' => 'atlas.ai.company.domain_workload_agent_template_status_record.v1',
                        'template_id' => (string) ($template['template_id'] ?? 'unknown_template'),
                        'flow_id' => (string) ($template['flow_id'] ?? 'unknown_flow'),
                        'owner_agent' => (string) ($template['owner_agent'] ?? 'unknown_agent'),
                        'workload_family' => (string) ($template['workload_family'] ?? 'unknown_workload_family'),
                        'target_artifact' => (string) ($template['target_artifact'] ?? 'enterprise_artifact'),
                        'ready' => $readyGateCount === count($gates),
                        'ready_gate_count' => $readyGateCount,
                        'required_gate_count' => count($gates),
                        'gates' => $gates,
                        'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                        'skill_count' => count((array) ($template['skills'] ?? [])),
                        'required_connector_count' => count($requiredConnectors),
                        'subagent_count' => count($subagents),
                        'distribution_package_ready' => (bool) ($gates['distribution_package_ready'] ?? false),
                        'rollout_plan_ready' => (bool) ($gates['rollout_plan_ready'] ?? false),
                        'tool_permission_matrix_ready' => (bool) ($gates['tool_permission_matrix_ready'] ?? false),
                        'execution_surface_bindings_ready' => (bool) ($gates['execution_surface_bindings_ready'] ?? false),
                        'fixture_smoke_contract_ready' => (bool) ($gates['fixture_smoke_contract_ready'] ?? false),
                        'source_pattern_receipts_bound' => (bool) ($gates['source_pattern_receipts_bound'] ?? false),
                        'operator_handoff_contract_ready' => (bool) ($gates['operator_handoff_contract_ready'] ?? false),
                        'guardrail_contract_ready' => (bool) ($gates['guardrail_contract_ready'] ?? false),
                        'eval_replay_recipe_ready' => (bool) ($gates['eval_replay_recipe_ready'] ?? false),
                        'distribution_package_hash' => data_get($distributionPackage, 'package_hash'),
                        'rollout_plan_hash' => data_get($distributionPackage, 'rollout_plan.rollout_hash'),
                        'tool_permission_matrix_hash' => data_get($distributionPackage, 'connector_permission_manifest.permission_matrix_hash'),
                        'execution_surface_binding_hash' => data_get($distributionPackage, 'execution_surface_bindings.surface_binding_hash'),
                        'fixture_smoke_contract_hash' => data_get($distributionPackage, 'fixture_smoke_contract.fixture_hash'),
                        'source_pattern_receipt_hash' => $sourcePatternReceipts['source_pattern_receipt_hash'] ?? null,
                        'operator_handoff_contract_hash' => $operatorHandoffContract['handoff_contract_hash'] ?? null,
                        'guardrail_contract_hash' => $guardrailContract['guardrail_contract_hash'] ?? null,
                        'eval_replay_recipe_hash' => $evalReplayRecipe['eval_replay_recipe_hash'] ?? null,
                        'external_execution_allowed' => false,
                    ];
                    $row['template_status_record_hash'] = MissionCanonicalHash::sha256($row);

                    return $row;
                },
                $templates,
            ));

            $companyGates = [
                'reference_architecture_bound' => data_get($stack, 'reference_architecture.pattern') === 'anthropic_financial_services_agents_generalized_to_every_atlas_company'
                    && data_get($stack, 'reference_architecture.source_url') === 'https://www.anthropic.com/news/finance-agents'
                    && count((array) data_get($stack, 'reference_architecture.templates_package', [])) >= 3
                    && count((array) data_get($stack, 'reference_architecture.enterprise_runtime_features', [])) >= 5,
                'template_policy_enterprise_ready' => (bool) data_get($stack, 'template_policy.template_required_for_every_flow', false)
                    && (bool) data_get($stack, 'template_policy.skills_are_trigger_loaded_not_always_on_context', false)
                    && (bool) data_get($stack, 'template_policy.connectors_are_governed_read_or_fixture_until_operator_scope', false)
                    && (bool) data_get($stack, 'template_policy.subagents_start_with_minimal_scoped_context', false)
                    && (bool) data_get($stack, 'template_policy.audit_log_required_for_every_tool_call_and_decision', false),
                'template_coverage_complete' => $expectedFlowCount > 0
                    && count($templates) >= $expectedFlowCount
                    && count($coverageMatrix) >= $expectedFlowCount
                    && count(array_filter($coverageMatrix, static fn (array $row): bool => (bool) ($row['coverage_ready'] ?? false))) >= $expectedFlowCount,
                'template_records_ready' => $expectedFlowCount > 0
                    && count($templateRows) >= $expectedFlowCount
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['ready'] ?? false))) >= $expectedFlowCount,
                'distribution_packages_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['distribution_package_ready'] ?? false))) >= $expectedFlowCount,
                'rollout_plans_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['rollout_plan_ready'] ?? false))) >= $expectedFlowCount,
                'tool_permission_matrices_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['tool_permission_matrix_ready'] ?? false))) >= $expectedFlowCount,
                'execution_surface_bindings_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['execution_surface_bindings_ready'] ?? false))) >= $expectedFlowCount,
                'fixture_smoke_contracts_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['fixture_smoke_contract_ready'] ?? false))) >= $expectedFlowCount,
                'source_pattern_receipts_bound' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['source_pattern_receipts_bound'] ?? false))) >= $expectedFlowCount,
                'operator_handoff_contracts_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['operator_handoff_contract_ready'] ?? false))) >= $expectedFlowCount,
                'guardrail_contracts_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['guardrail_contract_ready'] ?? false))) >= $expectedFlowCount,
                'eval_replay_recipes_ready' => $expectedFlowCount > 0
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['eval_replay_recipe_ready'] ?? false))) >= $expectedFlowCount,
                'external_effects_blocked' => (bool) data_get($stack, 'template_policy.external_write_spend_trade_publish_deploy_delete_or_offensive_security_allowed', true) === false
                    && count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['external_execution_allowed'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($companyGates));

            $row = [
                'schema' => 'atlas.ai.company.enterprise_domain_workload_agent_template_status.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'template_count' => count($templates),
                'ready_template_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['ready'] ?? false))),
                'coverage_record_count' => count($coverageMatrix),
                'skill_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['skill_count'] ?? 0), $templateRows)),
                'subagent_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['subagent_count'] ?? 0), $templateRows)),
                'required_connector_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['required_connector_count'] ?? 0), $templateRows)),
                'distribution_package_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['distribution_package_ready'] ?? false))),
                'rollout_plan_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['rollout_plan_ready'] ?? false))),
                'tool_permission_matrix_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['tool_permission_matrix_ready'] ?? false))),
                'execution_surface_binding_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['execution_surface_bindings_ready'] ?? false))),
                'fixture_smoke_contract_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['fixture_smoke_contract_ready'] ?? false))),
                'source_pattern_receipt_bound_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['source_pattern_receipts_bound'] ?? false))),
                'operator_handoff_contract_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['operator_handoff_contract_ready'] ?? false))),
                'guardrail_contract_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['guardrail_contract_ready'] ?? false))),
                'eval_replay_recipe_ready_count' => count(array_filter($templateRows, static fn (array $row): bool => (bool) ($row['eval_replay_recipe_ready'] ?? false))),
                'ready' => $readyGateCount === count($companyGates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'template_records' => $templateRows,
                'source_hashes' => [
                    'company_receipt_hash' => $company['receipt_hash'] ?? null,
                    'workload_template_stack_hash' => $stack['workload_template_stack_hash'] ?? null,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_workload_agent_template_status_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_DOMAIN_WORKLOAD_AGENT_TEMPLATE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_domain_workload_agent_templates_ready_external_effects_blocked'
                : 'enterprise_domain_workload_agent_templates_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'template_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['template_count'] ?? 0), $companies)),
                'ready_template_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_template_count'] ?? 0), $companies)),
                'skill_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['skill_count'] ?? 0), $companies)),
                'subagent_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['subagent_count'] ?? 0), $companies)),
                'distribution_package_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['distribution_package_ready_count'] ?? 0), $companies)),
                'rollout_plan_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['rollout_plan_ready_count'] ?? 0), $companies)),
                'tool_permission_matrix_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['tool_permission_matrix_ready_count'] ?? 0), $companies)),
                'execution_surface_binding_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['execution_surface_binding_ready_count'] ?? 0), $companies)),
                'fixture_smoke_contract_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['fixture_smoke_contract_ready_count'] ?? 0), $companies)),
                'source_pattern_receipt_bound_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['source_pattern_receipt_bound_count'] ?? 0), $companies)),
                'operator_handoff_contract_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['operator_handoff_contract_ready_count'] ?? 0), $companies)),
                'guardrail_contract_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['guardrail_contract_ready_count'] ?? 0), $companies)),
                'eval_replay_recipe_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['eval_replay_recipe_ready_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => [
                'reference_architecture' => 'anthropic_financial_services_agents_generalized_to_all_domains',
                'skills_connectors_subagents_required' => true,
                'plugin_and_managed_agent_cookbook_packages_required' => true,
                'rollout_smoke_rollback_and_evidence_plan_required' => true,
                'surface_bindings_fixture_smoke_and_tool_permission_matrix_required' => true,
                'source_pattern_eval_handoff_and_guardrail_contracts_required' => true,
                'long_running_sessions_per_tool_permissions_vault_and_audit_required' => true,
                'human_in_the_loop_required' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['enterprise_domain_workload_agent_template_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyDomainSolutionPackStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $companies = [];

        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);
            $solution = (array) data_get($company, 'enterprise_domain_solution_stack', []);
            $finance = (array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface', []);

            $flowRecords = [];
            foreach ($flows as $flowId) {
                $playbook = $this->hub->findByFlow($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', $flowId);
                $module = $this->hub->findByFlow($company, 'enterprise_domain_solution_stack.solution_modules', $flowId);
                $verticalKit = $this->hub->findByFlow($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', $flowId);
                $executionCell = $this->hub->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', $flowId);
                $providerRoute = $this->hub->findByFlow($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', $flowId);
                $dataConnector = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);

                $gates = [
                    'solution_module_ready' => $module !== []
                        && count((array) data_get($module, 'capability_bundle.sense', [])) >= 3
                        && count((array) data_get($module, 'capability_bundle.reason', [])) >= 3
                        && in_array('operator_checkpoint_before_external_action', (array) data_get($module, 'capability_bundle.act', []), true)
                        && (bool) data_get($module, 'service_level.receipt_required', false),
                    'source_pack_ready' => $playbook !== []
                        && (bool) data_get($playbook, 'source_pack.direct_hyperlinks_required', false)
                        && (int) data_get($playbook, 'source_pack.minimum_independent_sources', 0) >= 3
                        && (bool) data_get($playbook, 'source_pack.freshness_check_required', false)
                        && (bool) data_get($playbook, 'source_pack.source_disagreement_register_required', false),
                    'data_plane_ready' => $playbook !== []
                        && count((array) data_get($playbook, 'data_plane.lineage_fields', [])) >= 6
                        && (bool) data_get($playbook, 'data_plane.raw_secret_or_sensitive_payload_export_allowed', true) === false,
                    'execution_path_ready' => $playbook !== []
                        && count((array) data_get($playbook, 'execution_path.nodes', [])) >= 8
                        && (bool) data_get($playbook, 'execution_path.durable_state_required', false)
                        && (bool) data_get($playbook, 'execution_path.resume_token_required', false)
                        && (bool) data_get($playbook, 'execution_path.idempotency_key_required', false)
                        && (bool) data_get($playbook, 'execution_path.operator_interrupt_supported', false),
                    'tooling_contract_ready' => $playbook !== []
                        && count((array) data_get($playbook, 'tooling_contract.connector_refs', [])) >= 1
                        && (bool) data_get($playbook, 'tooling_contract.mcp_or_api_adapter_required', false)
                        && (bool) data_get($playbook, 'tooling_contract.sandbox_or_fixture_mode_required_before_live_read', false)
                        && (bool) data_get($playbook, 'tooling_contract.write_spend_trade_publish_deploy_delete_blocked_without_signed_scope', false)
                        && (bool) data_get($playbook, 'tooling_contract.tool_receipt_required', false),
                    'domain_review_ready' => $playbook !== []
                        && (float) data_get($playbook, 'domain_review_contract.domain_correctness_score_required', 0.0) >= 0.9
                        && (float) data_get($playbook, 'domain_review_contract.source_faithfulness_score_required', 0.0) >= 0.95
                        && (int) data_get($playbook, 'domain_review_contract.policy_findings_allowed', 1) === 0
                        && (bool) data_get($playbook, 'domain_review_contract.customer_visible_claims_require_source_refs', false),
                    'benchmark_and_replay_ready' => $playbook !== []
                        && (int) data_get($playbook, 'benchmark_contract.fixture_cases_required', 0) >= 25
                        && (int) data_get($playbook, 'benchmark_contract.shadow_replays_required', 0) >= 5
                        && (int) data_get($playbook, 'benchmark_contract.adversarial_cases_required', 0) >= 5
                        && (bool) data_get($playbook, 'benchmark_contract.regression_pack_required', false)
                        && (bool) data_get($playbook, 'benchmark_contract.synthetic_score_claims_allowed', true) === false,
                    'handoff_evidence_ready' => $playbook !== []
                        && count((array) data_get($playbook, 'handoff_contract.required_evidence', [])) >= 6
                        && (bool) data_get($playbook, 'handoff_contract.target_acceptance_required', false)
                        && (bool) data_get($playbook, 'handoff_contract.external_delivery_requires_operator_mandate', false),
                    'vertical_execution_chain_ready' => $verticalKit !== []
                        && $executionCell !== []
                        && $providerRoute !== []
                        && $dataConnector !== [],
                ];
                $readyGateCount = count(array_filter($gates));
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_flow_domain_solution_pack_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($gates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($gates),
                    'gates' => $gates,
                    'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                    'playbook_hash' => $playbook['playbook_hash'] ?? null,
                    'module_hash' => $module['module_hash'] ?? null,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $record['flow_domain_solution_pack_record_hash'] = MissionCanonicalHash::sha256($record);
                $flowRecords[] = $record;
            }

            $companyGates = [
                'anthropic_financial_services_pattern_generalized' => in_array('anthropic_claude_for_financial_services', (array) data_get($finance, 'unified_sources', []), true)
                    && count((array) data_get($finance, 'provider_connector_classes', [])) >= 7
                    && (bool) data_get($finance, 'source_verification_contract.direct_source_link_required', false)
                    && (bool) data_get($finance, 'source_verification_contract.cross_source_reconciliation_required', false)
                    && (bool) data_get($finance, 'source_verification_contract.claim_without_source_link_allowed', true) === false,
                'domain_solution_stack_ready' => ($solution['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_solution_stack.v1'
                    && count((array) data_get($solution, 'domain_source_catalog', [])) >= 5
                    && count((array) data_get($solution, 'solution_modules', [])) >= $expectedFlowCount
                    && count((array) data_get($solution, 'managed_agent_templates', [])) >= 4
                    && count((array) data_get($solution, 'data_product_catalog', [])) >= 5
                    && count((array) data_get($solution, 'enterprise_solution_playbooks', [])) >= $expectedFlowCount,
                'domain_data_plane_ready' => (bool) data_get($solution, 'domain_data_plane.direct_source_hyperlinks_required', false)
                    && (bool) data_get($solution, 'domain_data_plane.cross_source_verification_required', false)
                    && (bool) data_get($solution, 'domain_data_plane.claim_to_source_traceability_required', false)
                    && (bool) data_get($solution, 'domain_data_plane.private_or_regulated_data_requires_redaction', false)
                    && (bool) data_get($solution, 'domain_data_plane.external_data_mutation_allowed', true) === false,
                'expert_review_board_ready' => count((array) data_get($solution, 'domain_expert_review_board.review_modes', [])) >= 5
                    && (bool) data_get($solution, 'domain_expert_review_board.second_reviewer_required_for_external_action', false)
                    && (bool) data_get($solution, 'domain_expert_review_board.operator_acceptance_required_for_customer_visible_output', false),
                'flow_solution_pack_records_ready' => $expectedFlowCount > 0
                    && count($flowRecords) >= $expectedFlowCount
                    && count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) >= $expectedFlowCount,
                'external_effects_blocked' => (bool) data_get($solution, 'solution_operating_model.external_side_effects_default', true) === false
                    && count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true) || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_domain_solution_pack_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'domain_solution_pack_ready' => $readyGateCount === count($companyGates),
                'ready_flow_solution_pack_count' => count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))),
                'flow_solution_pack_count' => count($flowRecords),
                'domain_source_count' => count((array) data_get($solution, 'domain_source_catalog', [])),
                'solution_module_count' => count((array) data_get($solution, 'solution_modules', [])),
                'managed_agent_template_count' => count((array) data_get($solution, 'managed_agent_templates', [])),
                'data_product_count' => count((array) data_get($solution, 'data_product_catalog', [])),
                'domain_review_mode_count' => count((array) data_get($solution, 'domain_expert_review_board.review_modes', [])),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_solution_pack_records' => $flowRecords,
                'source_hashes' => [
                    'company_receipt_hash' => $company['receipt_hash'] ?? null,
                    'domain_solution_hash' => $solution['domain_solution_hash'] ?? null,
                    'financial_data_interface_hash' => $finance['data_interface_hash'] ?? null,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_domain_solution_pack_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['domain_solution_pack_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_SOLUTION_PACK_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_domain_solution_packs_ready_external_effects_blocked'
                : 'enterprise_company_domain_solution_packs_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'domain_solution_pack_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ready_flow_solution_pack_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_solution_pack_count'] ?? 0), $companies)),
                'flow_solution_pack_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_solution_pack_count'] ?? 0), $companies)),
                'domain_source_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['domain_source_count'] ?? 0), $companies)),
                'solution_module_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['solution_module_count'] ?? 0), $companies)),
                'managed_agent_template_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['managed_agent_template_count'] ?? 0), $companies)),
                'data_product_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['data_product_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => [
                'source_pattern' => 'anthropic_claude_for_financial_services_generalized_to_all_companies',
                'reference_url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'direct_source_links_cross_source_verification_and_audit_trails_required' => true,
                'domain_playbook_required_for_every_flow' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['unsupported_claim', 'claim_without_source_link', 'external_data_mutation', 'auto_publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
            ],
        ];
        $payload['enterprise_company_domain_solution_pack_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyAgentOperationsPackStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $workloadTemplates = $this->enterpriseDomainWorkloadAgentTemplateStatus($wantedCompany);
        $toolchain = $this->hub->companyOperatingStatus->domainAgentToolchainCertificationStatus($wantedCompany);
        $toolExecution = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainToolExecutionReadinessStatus($wantedCompany);
        $toolLedger = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionLedgerStatus($wantedCompany);
        $toolRuntime = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionRuntimeStatus($wantedCompany);
        $adapterEnvelope = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainAdapterExecutionEnvelopeStatus($wantedCompany);

        $workloadByCompany = $this->hub->companyRowsById($workloadTemplates);
        $toolchainByCompany = $this->hub->companyRowsById($toolchain);
        $toolExecutionByCompany = $this->hub->companyRowsById($toolExecution);
        $toolLedgerByCompany = $this->hub->companyRowsById($toolLedger);
        $toolRuntimeByCompany = $this->hub->companyRowsById($toolRuntime);
        $adapterEnvelopeByCompany = $this->hub->companyRowsById($adapterEnvelope);

        $recordForFlow = static function (array $records, string $flowId): array {
            foreach ($records as $record) {
                if ((string) ($record['flow_id'] ?? '') === $flowId) {
                    return (array) $record;
                }
            }

            return [];
        };
        $recordsForFlow = static function (array $records, string $flowId): array {
            return array_values(array_filter($records, static fn (array $record): bool => (string) ($record['flow_id'] ?? '') === $flowId));
        };
        $receiptChainReady = static function (array $record): bool {
            $chain = (array) ($record['receipt_chain'] ?? []);

            return strlen((string) ($chain['decision_receipt_hash'] ?? '')) === 64
                && strlen((string) ($chain['tool_run_receipt_hash'] ?? '')) === 64
                && strlen((string) ($chain['audit_trail_hash'] ?? '')) === 64
                && strlen((string) ($chain['rollback_plan_hash'] ?? '')) === 64;
        };
        $adapterReceiptChainReady = static function (array $record): bool {
            $chain = (array) ($record['receipt_chain'] ?? []);

            return strlen((string) ($chain['decision_receipt_hash'] ?? '')) === 64
                && strlen((string) ($chain['adapter_envelope_receipt_hash'] ?? '')) === 64
                && strlen((string) ($chain['audit_trail_hash'] ?? '')) === 64
                && strlen((string) ($chain['rollback_plan_hash'] ?? '')) === 64;
        };

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);
            $workloadRow = (array) ($workloadByCompany[$id] ?? []);
            $toolchainRow = (array) ($toolchainByCompany[$id] ?? []);
            $toolExecutionRow = (array) ($toolExecutionByCompany[$id] ?? []);
            $toolLedgerRow = (array) ($toolLedgerByCompany[$id] ?? []);
            $toolRuntimeRow = (array) ($toolRuntimeByCompany[$id] ?? []);
            $adapterEnvelopeRow = (array) ($adapterEnvelopeByCompany[$id] ?? []);
            $templateRecords = array_values((array) ($workloadRow['template_records'] ?? []));
            $toolExecutionRecords = array_values((array) ($toolExecutionRow['flow_tool_execution_records'] ?? []));
            $ledgerRecords = array_values((array) ($toolLedgerRow['ledger_records'] ?? []));
            $runtimeRecords = array_values((array) ($toolRuntimeRow['runtime_records'] ?? []));
            $adapterRecords = array_values((array) ($adapterEnvelopeRow['runtime_records'] ?? $adapterEnvelopeRow['envelope_records'] ?? []));

            $flowRecords = [];
            foreach ($flows as $flowId) {
                $template = $recordForFlow($templateRecords, $flowId);
                $toolReadiness = $recordForFlow($toolExecutionRecords, $flowId);
                $ledger = $recordForFlow($ledgerRecords, $flowId);
                $runtime = $recordForFlow($runtimeRecords, $flowId);
                $flowAdapterRecords = $recordsForFlow($adapterRecords, $flowId);
                $staffing = $this->hub->findByFlow($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', $flowId);
                $runbook = $this->hub->findByFlow($company, 'enterprise_operating_system.runbooks', $flowId);
                $queueContract = $this->hub->findByFlow($company, 'enterprise_flow_run_queue_stack.flow_run_queue_contracts', $flowId);
                $adapterReceiptReadyCount = count(array_filter($flowAdapterRecords, $adapterReceiptChainReady));

                $gates = [
                    'workload_template_ready' => (bool) ($template['ready'] ?? false)
                        && (int) ($template['skill_count'] ?? 0) >= 8
                        && (int) ($template['subagent_count'] ?? 0) >= 3
                        && (bool) ($template['tool_permission_matrix_ready'] ?? false)
                        && (bool) ($template['execution_surface_bindings_ready'] ?? false)
                        && (bool) ($template['fixture_smoke_contract_ready'] ?? false)
                        && (bool) ($template['source_pattern_receipts_bound'] ?? false)
                        && (bool) ($template['operator_handoff_contract_ready'] ?? false)
                        && (bool) ($template['guardrail_contract_ready'] ?? false)
                        && (bool) ($template['eval_replay_recipe_ready'] ?? false),
                    'tool_execution_ready' => (bool) ($toolReadiness['tool_execution_ready'] ?? false)
                        && count((array) ($toolReadiness['tool_contract_hashes'] ?? [])) >= 7
                        && ! (bool) ($toolReadiness['external_execution_allowed'] ?? true)
                        && ! (bool) ($toolReadiness['external_side_effects_enabled'] ?? true),
                    'flow_tool_ledger_ready' => (bool) ($ledger['ready'] ?? false)
                        && $receiptChainReady($ledger)
                        && ! (bool) ($ledger['external_execution_allowed'] ?? true)
                        && ! (bool) ($ledger['external_side_effects_enabled'] ?? true),
                    'persisted_tool_runtime_ready' => (bool) ($runtime['ready'] ?? false)
                        && $receiptChainReady($runtime)
                        && ! (bool) ($runtime['external_execution_allowed'] ?? true)
                        && ! (bool) ($runtime['external_side_effects_enabled'] ?? true),
                    'adapter_envelopes_ready' => $flowAdapterRecords !== []
                        && count($flowAdapterRecords) === count(array_filter($flowAdapterRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)))
                        && $adapterReceiptReadyCount === count($flowAdapterRecords),
                    'staffing_runbook_and_queue_bound' => $staffing !== []
                        && $runbook !== []
                        && ($queueContract !== [] || (bool) data_get($template, 'rollout_plan_ready', false)),
                    'external_effects_blocked' => ! (bool) ($template['external_execution_allowed'] ?? true)
                        && ! (bool) ($toolReadiness['external_execution_allowed'] ?? true)
                        && ! (bool) ($ledger['external_execution_allowed'] ?? true)
                        && ! (bool) ($runtime['external_execution_allowed'] ?? true)
                        && count(array_filter($flowAdapterRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true) || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
                ];
                $readyGateCount = count(array_filter($gates));
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_flow_agent_operations_pack_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($gates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($gates),
                    'gates' => $gates,
                    'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                    'skill_count' => (int) ($template['skill_count'] ?? 0),
                    'subagent_count' => (int) ($template['subagent_count'] ?? 0),
                    'tool_contract_hash_count' => count((array) ($toolReadiness['tool_contract_hashes'] ?? [])),
                    'adapter_envelope_count' => count($flowAdapterRecords),
                    'template_source_pattern_receipts_bound' => (bool) ($template['source_pattern_receipts_bound'] ?? false),
                    'template_operator_handoff_contract_ready' => (bool) ($template['operator_handoff_contract_ready'] ?? false),
                    'template_guardrail_contract_ready' => (bool) ($template['guardrail_contract_ready'] ?? false),
                    'template_eval_replay_recipe_ready' => (bool) ($template['eval_replay_recipe_ready'] ?? false),
                    'source_hashes' => [
                        'template_status_record_hash' => $template['template_status_record_hash'] ?? null,
                        'template_source_pattern_receipt_hash' => $template['source_pattern_receipt_hash'] ?? null,
                        'template_operator_handoff_contract_hash' => $template['operator_handoff_contract_hash'] ?? null,
                        'template_guardrail_contract_hash' => $template['guardrail_contract_hash'] ?? null,
                        'template_eval_replay_recipe_hash' => $template['eval_replay_recipe_hash'] ?? null,
                        'domain_tool_execution_flow_readiness_record_hash' => $toolReadiness['domain_tool_execution_flow_readiness_record_hash'] ?? null,
                        'flow_tool_execution_ledger_record_hash' => $ledger['flow_tool_execution_ledger_record_hash'] ?? null,
                        'persisted_flow_tool_execution_runtime_record_hash' => $runtime['persisted_flow_tool_execution_runtime_record_hash'] ?? null,
                        'flow_staffing_matrix_hash' => $staffing['flow_staffing_matrix_hash'] ?? null,
                    ],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $record['flow_agent_operations_pack_record_hash'] = MissionCanonicalHash::sha256($record);
                $flowRecords[] = $record;
            }

            $readyFlowCount = count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $companyGates = [
                'workload_templates_ready' => (bool) ($workloadRow['ready'] ?? false)
                    && (int) ($workloadRow['ready_template_count'] ?? 0) >= $expectedFlowCount,
                'domain_agent_toolchain_certified' => (bool) ($toolchainRow['ready'] ?? false)
                    && (int) ($toolchainRow['ready_gate_count'] ?? 0) === (int) ($toolchainRow['required_gate_count'] ?? -1),
                'domain_tool_execution_ready' => (bool) ($toolExecutionRow['tool_execution_ready'] ?? false)
                    && (int) ($toolExecutionRow['ready_flow_tool_execution_count'] ?? 0) >= $expectedFlowCount,
                'flow_tool_ledger_ready' => (bool) ($toolLedgerRow['flow_tool_execution_ledger_ready'] ?? false)
                    && (int) ($toolLedgerRow['ready_ledger_record_count'] ?? 0) >= $expectedFlowCount,
                'persisted_tool_runtime_ready' => (bool) ($toolRuntimeRow['persisted_runtime_ready'] ?? false)
                    && (int) ($toolRuntimeRow['ready_persisted_run_count'] ?? 0) >= $expectedFlowCount,
                'adapter_envelopes_ready' => (bool) ($adapterEnvelopeRow['adapter_envelope_ready'] ?? false)
                    && (int) ($adapterEnvelopeRow['ready_persisted_envelope_count'] ?? 0) === (int) ($adapterEnvelopeRow['expected_envelope_count'] ?? -1),
                'org_staffing_model_ready' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', [])) >= $expectedFlowCount
                    && count((array) data_get($company, 'enterprise_operating_system.runbooks', [])) >= $expectedFlowCount
                    && data_get($company, 'enterprise_workforce_capacity_stack.schema') === 'atlas.ai.company.enterprise_workforce_capacity_stack.v1',
                'flow_agent_operations_records_ready' => $expectedFlowCount > 0
                    && count($flowRecords) >= $expectedFlowCount
                    && $readyFlowCount === count($flowRecords),
                'external_effects_blocked' => ! (bool) ($workloadRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($toolExecutionRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($toolLedgerRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($toolRuntimeRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($adapterEnvelopeRow['external_execution_allowed'] ?? false),
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_agent_operations_pack_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'agent_operations_pack_ready' => $expectedFlowCount > 0 && $readyGateCount === count($companyGates),
                'ready_flow_agent_operations_pack_count' => $readyFlowCount,
                'flow_agent_operations_pack_count' => count($flowRecords),
                'skill_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['skill_count'] ?? 0), $flowRecords)),
                'subagent_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['subagent_count'] ?? 0), $flowRecords)),
                'adapter_envelope_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['adapter_envelope_count'] ?? 0), $flowRecords)),
                'template_source_pattern_receipt_bound_count' => count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['template_source_pattern_receipts_bound'] ?? false))),
                'template_operator_handoff_contract_ready_count' => count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['template_operator_handoff_contract_ready'] ?? false))),
                'template_guardrail_contract_ready_count' => count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['template_guardrail_contract_ready'] ?? false))),
                'template_eval_replay_recipe_ready_count' => count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['template_eval_replay_recipe_ready'] ?? false))),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_agent_operations_pack_records' => $flowRecords,
                'source_hashes' => [
                    'company_workload_agent_template_status_hash' => $workloadRow['company_workload_agent_template_status_hash'] ?? null,
                    'domain_agent_toolchain_certification_record_hash' => $toolchainRow['domain_agent_toolchain_certification_record_hash'] ?? null,
                    'company_domain_tool_execution_readiness_record_hash' => $toolExecutionRow['company_domain_tool_execution_readiness_record_hash'] ?? null,
                    'company_flow_tool_execution_ledger_record_hash' => $toolLedgerRow['company_flow_tool_execution_ledger_record_hash'] ?? null,
                    'company_flow_tool_execution_runtime_status_record_hash' => $toolRuntimeRow['company_flow_tool_execution_runtime_status_record_hash'] ?? null,
                    'company_domain_adapter_execution_envelope_status_record_hash' => $adapterEnvelopeRow['company_domain_adapter_execution_envelope_status_record_hash'] ?? null,
                    'workforce_capacity_stack_hash' => data_get($company, 'enterprise_workforce_capacity_stack.workforce_capacity_hash'),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_agent_operations_pack_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['agent_operations_pack_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_AGENT_OPERATIONS_PACK_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_agent_operations_packs_ready_external_effects_blocked'
                : 'enterprise_company_agent_operations_packs_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'agent_operations_pack_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ready_flow_agent_operations_pack_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_agent_operations_pack_count'] ?? 0), $companies)),
                'flow_agent_operations_pack_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_agent_operations_pack_count'] ?? 0), $companies)),
                'skill_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['skill_count'] ?? 0), $companies)),
                'subagent_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['subagent_count'] ?? 0), $companies)),
                'adapter_envelope_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['adapter_envelope_count'] ?? 0), $companies)),
                'template_source_pattern_receipt_bound_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['template_source_pattern_receipt_bound_count'] ?? 0), $companies)),
                'template_operator_handoff_contract_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['template_operator_handoff_contract_ready_count'] ?? 0), $companies)),
                'template_guardrail_contract_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['template_guardrail_contract_ready_count'] ?? 0), $companies)),
                'template_eval_replay_recipe_ready_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['template_eval_replay_recipe_ready_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_domain_workload_agent_template_status_hash' => $workloadTemplates['enterprise_domain_workload_agent_template_status_hash'] ?? null,
                'domain_agent_toolchain_certification_status_hash' => $toolchain['domain_agent_toolchain_certification_status_hash'] ?? null,
                'enterprise_company_domain_tool_execution_readiness_status_hash' => $toolExecution['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_ledger_status_hash' => $toolLedger['enterprise_company_flow_tool_execution_ledger_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_runtime_status_hash' => $toolRuntime['enterprise_company_flow_tool_execution_runtime_status_hash'] ?? null,
                'enterprise_company_domain_adapter_execution_envelope_status_hash' => $adapterEnvelope['enterprise_company_domain_adapter_execution_envelope_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'agent_operations_pack_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'skills_subagents_tool_permissions_receipts_envelopes_staffing_and_runbooks_required' => true,
                'template_source_pattern_eval_handoff_and_guardrails_required' => true,
                'blocked_operations' => ['unreviewed_agent_dispatch', 'unreceipted_tool_invocation', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_agent_operations_pack_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyAgentWorkforceRuntimeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        unset($this->hub->runtimeStatusCache['agent_workforce_runtime_status:'.($wantedCompany ?? '*')]);
        $agentOperations = $this->enterpriseCompanyAgentOperationsPackStatus($wantedCompany);
        $agentOperationsByCompany = $this->hub->companyRowsById($agentOperations);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_AGENT_WORKFORCE_RUNTIME_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_agent_workforce_runtime_count' => 0,
                    'registered_agent_workforce_runtime_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->agentWorkforceRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $agentOperationsRow = (array) ($agentOperationsByCompany[$id] ?? []);
            $registeredRuntimes = [];

            foreach ((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', []) as $crew) {
                $flowId = (string) ($crew['flow_id'] ?? '');
                if ($flowId === '') {
                    continue;
                }

                foreach ((array) ($crew['subagents'] ?? []) as $subagentId) {
                    $subagentId = (string) $subagentId;
                    if ($subagentId === '') {
                        continue;
                    }

                    $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'context' => 'agent_workforce_runtime',
                    ]), 0, 24).'.agent_workforce_runtime.v1';
                    $artifact = $this->agentWorkforceRuntimeArtifact($company, (array) $crew, $subagentId, $runContextId);
                    $qualityGates = [
                        'agent_operations_pack_ready' => (bool) ($agentOperationsRow['agent_operations_pack_ready'] ?? false)
                            && (int) ($agentOperationsRow['ready_flow_agent_operations_pack_count'] ?? 0) >= (int) ($agentOperationsRow['expected_flow_count'] ?? 0),
                        'runtime_artifact_bound' => strlen((string) ($artifact['agent_workforce_runtime_artifact_hash'] ?? '')) === 64,
                        'skill_contract_bound' => count((array) data_get($artifact, 'skill_contract.skills', [])) >= 7
                            && strlen((string) data_get($artifact, 'skill_contract.skill_contract_hash', '')) === 64,
                        'connector_scope_bound' => count((array) data_get($artifact, 'connector_scope.connector_refs', [])) >= 1
                            && (bool) data_get($artifact, 'connector_scope.credential_material_export_allowed', true) === false,
                        'subagent_handoff_bound' => strlen((string) data_get($artifact, 'subagent_handoff.handoff_contract_hash', '')) === 64
                            && (bool) data_get($artifact, 'subagent_handoff.context_minimization_required', false),
                        'guardrail_contract_bound' => strlen((string) data_get($artifact, 'guardrail_contract.guardrail_contract_hash', '')) === 64
                            && count((array) data_get($artifact, 'guardrail_contract.blocked_operations', [])) >= 10,
                        'observability_contract_bound' => strlen((string) data_get($artifact, 'observability_contract.observability_contract_hash', '')) === 64
                            && count((array) data_get($artifact, 'observability_contract.required_metrics', [])) >= 7,
                        'eval_replay_contract_bound' => strlen((string) data_get($artifact, 'eval_replay_contract.eval_replay_contract_hash', '')) === 64
                            && count((array) data_get($artifact, 'eval_replay_contract.replay_assertions', [])) >= 6,
                        'receipt_chain_bound' => strlen((string) data_get($artifact, 'receipt_chain.agent_runtime_receipt_hash', '')) === 64
                            && strlen((string) data_get($artifact, 'receipt_chain.handoff_receipt_hash', '')) === 64
                            && strlen((string) data_get($artifact, 'receipt_chain.guardrail_receipt_hash', '')) === 64,
                        'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                            && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                    ];
                    $summary = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'crew_id' => (string) ($crew['crew_id'] ?? ''),
                        'runtime_mode' => 'skills_connectors_subagent_runtime_external_effects_blocked',
                        'quality_gate_count' => count($qualityGates),
                        'ready_quality_gate_count' => count(array_filter($qualityGates)),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $normalized = [
                        'schema' => 'atlas.ai.company.agent_workforce_runtime_result.v1',
                        'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                        'summary' => $summary,
                        'agent_workforce_runtime_artifact' => $artifact,
                        'quality_gates' => $qualityGates,
                        'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                    ];
                    $receiptInput = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'run_context_id' => $runContextId,
                        'artifact_hash' => (string) ($artifact['agent_workforce_runtime_artifact_hash'] ?? ''),
                    ];
                    $metadata = [
                        'source' => 'enterprise_company_agent_workforce_runtime_register',
                        'receipt_schema_version' => 'atlas.company_agent_workforce_runtime_receipt.v1',
                        'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                        'agent_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'agent_runtime_receipt']),
                        'skill_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'skill_contract_receipt']),
                        'connector_scope_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'connector_scope_receipt']),
                        'handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'handoff_receipt']),
                        'guardrail_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'guardrail_receipt']),
                        'observability_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'observability_receipt']),
                        'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                        'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                        'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];

                    $run = AtlasToolRun::query()->updateOrCreate(
                        [
                            'surface' => 'holding_company_agent_workforce_runtime',
                            'run_context_type' => 'holding_company_agent_workforce_runtime',
                            'run_context_id' => $runContextId,
                        ],
                        [
                            'tool_slug' => 'atlas_company_agent_workforce_runtime',
                            'workspace_hash' => hash('sha256', base_path()),
                            'workspace' => base_path(),
                            'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                            'required' => true,
                            'failure_policy' => 'fail_closed',
                            'policy_decision' => 'agent_workforce_registered_blocked',
                            'command_hash' => MissionCanonicalHash::sha256([
                                'run_context_id' => $runContextId,
                                'artifact_hash' => $artifact['agent_workforce_runtime_artifact_hash'] ?? null,
                            ]),
                            'exit_code' => $normalized['status'] === 'passed' ? 0 : 1,
                            'started_at' => now(),
                            'finished_at' => now(),
                            'duration_ms' => 0,
                            'summary_json' => $summary,
                            'normalized_result_json' => $normalized,
                            'policy_decision_json' => [
                                'external_execution_allowed' => false,
                                'external_side_effects_enabled' => false,
                                'blocked_operations' => $this->agentWorkforceRuntimePolicy()['blocked_operations'],
                            ],
                            'metadata_json' => $metadata,
                        ],
                    );

                    $registeredRuntimes[] = [
                        'run_id' => (string) $run->id,
                        'run_context_id' => $runContextId,
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'status' => (string) $run->status,
                        'policy_decision' => (string) $run->policy_decision,
                        'agent_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'agent_runtime_receipt_hash', ''),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                }
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_agent_workforce_runtime_register_record.v1',
                'company_id' => $id,
                'expected_agent_workforce_runtime_count' => count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', [])) * 5,
                'registered_agent_workforce_runtime_count' => count($registeredRuntimes),
                'registered_runtimes' => $registeredRuntimes,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_agent_workforce_runtime_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_agent_workforce_runtime_count'] ?? 0), $companies));
        $expectedCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_agent_workforce_runtime_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedCount > 0 && $registeredCount >= $expectedCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_AGENT_WORKFORCE_RUNTIME_REGISTER_SCHEMA,
            'status' => $expectedCount > 0 && $registeredCount >= $expectedCount
                ? 'enterprise_company_agent_workforce_runtime_registered_external_effects_blocked'
                : 'enterprise_company_agent_workforce_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_agent_workforce_runtime_count' => $expectedCount,
                'registered_agent_workforce_runtime_count' => $registeredCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_agent_operations_pack_status_hash' => $agentOperations['enterprise_company_agent_operations_pack_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->agentWorkforceRuntimePolicy(),
        ];
        $payload['enterprise_company_agent_workforce_runtime_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyAgentWorkforceRuntimeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cacheKey = 'agent_workforce_runtime_status:'.($wantedCompany ?? '*');
        if (isset($this->hub->runtimeStatusCache[$cacheKey])) {
            return $this->hub->runtimeStatusCache[$cacheKey];
        }

        $agentOperations = $this->enterpriseCompanyAgentOperationsPackStatus($wantedCompany);
        $agentOperationsByCompany = $this->hub->companyRowsById($agentOperations);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_AGENT_WORKFORCE_RUNTIME_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_agent_workforce_runtime_count' => 0,
                    'persisted_agent_workforce_runtime_count' => 0,
                    'ready_agent_workforce_runtime_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->agentWorkforceRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $agentOperationsRow = (array) ($agentOperationsByCompany[$id] ?? []);
            $runtimeRecords = [];

            foreach ((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', []) as $crew) {
                $flowId = (string) ($crew['flow_id'] ?? '');
                if ($flowId === '') {
                    continue;
                }

                foreach ((array) ($crew['subagents'] ?? []) as $subagentId) {
                    $subagentId = (string) $subagentId;
                    if ($subagentId === '') {
                        continue;
                    }

                    $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'context' => 'agent_workforce_runtime',
                    ]), 0, 24).'.agent_workforce_runtime.v1';
                    $run = AtlasToolRun::query()
                        ->where('surface', 'holding_company_agent_workforce_runtime')
                        ->where('run_context_type', 'holding_company_agent_workforce_runtime')
                        ->where('run_context_id', $runContextId)
                        ->latest('updated_at')
                        ->first();

                    $recordGates = [
                        'persisted_agent_workforce_runtime_exists' => $run instanceof AtlasToolRun,
                        'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                        'policy_registered_blocked' => $run instanceof AtlasToolRun && $run->policy_decision === 'agent_workforce_registered_blocked',
                        'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.agent_workforce_runtime_result.v1',
                        'artifact_bound' => $run instanceof AtlasToolRun
                            && data_get($run->normalized_result_json, 'agent_workforce_runtime_artifact.schema') === 'atlas.ai.company.agent_workforce_runtime_artifact.v1'
                            && strlen((string) data_get($run->normalized_result_json, 'agent_workforce_runtime_artifact.agent_workforce_runtime_artifact_hash', '')) === 64,
                        'receipt_metadata_bound' => $run instanceof AtlasToolRun
                            && strlen((string) data_get($run->metadata_json, 'agent_runtime_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'handoff_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'guardrail_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'observability_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'eval_replay_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                        'critical_controls_bound' => $run instanceof AtlasToolRun
                            && strlen((string) data_get($run->normalized_result_json, 'agent_workforce_runtime_artifact.subagent_handoff.handoff_contract_hash', '')) === 64
                            && strlen((string) data_get($run->normalized_result_json, 'agent_workforce_runtime_artifact.guardrail_contract.guardrail_contract_hash', '')) === 64
                            && strlen((string) data_get($run->normalized_result_json, 'agent_workforce_runtime_artifact.observability_contract.observability_contract_hash', '')) === 64,
                        'external_effects_blocked' => $run instanceof AtlasToolRun
                            && ! (bool) data_get($run->policy_decision_json, 'external_execution_allowed', true)
                            && ! (bool) data_get($run->policy_decision_json, 'external_side_effects_enabled', true),
                    ];
                    $readyGateCount = count(array_filter($recordGates));
                    $record = [
                        'schema' => 'atlas.ai.company.agent_workforce_runtime_status_record.v1',
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'subagent_id' => $subagentId,
                        'run_context_id' => $runContextId,
                        'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                        'ready' => $readyGateCount === count($recordGates),
                        'ready_gate_count' => $readyGateCount,
                        'required_gate_count' => count($recordGates),
                        'gates' => $recordGates,
                        'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                        'receipt_chain' => $run instanceof AtlasToolRun ? [
                            'agent_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'agent_runtime_receipt_hash', ''),
                            'handoff_receipt_hash' => (string) data_get($run->metadata_json, 'handoff_receipt_hash', ''),
                            'guardrail_receipt_hash' => (string) data_get($run->metadata_json, 'guardrail_receipt_hash', ''),
                            'observability_receipt_hash' => (string) data_get($run->metadata_json, 'observability_receipt_hash', ''),
                            'eval_replay_receipt_hash' => (string) data_get($run->metadata_json, 'eval_replay_receipt_hash', ''),
                        ] : [],
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $record['agent_workforce_runtime_status_record_hash'] = MissionCanonicalHash::sha256($record);
                    $runtimeRecords[] = $record;
                }
            }

            $expectedRuntimeCount = count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', [])) * 5;
            $readyRuntimeCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $gates = [
                'agent_operations_pack_ready' => (bool) ($agentOperationsRow['agent_operations_pack_ready'] ?? false),
                'agent_workforce_runtime_covers_subagents' => $expectedRuntimeCount > 0
                    && count($runtimeRecords) >= $expectedRuntimeCount
                    && $readyRuntimeCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_agent_workforce_runtime_company_status.v1',
                'company_id' => $id,
                'expected_agent_workforce_runtime_count' => $expectedRuntimeCount,
                'persisted_agent_workforce_runtime_count' => count($runtimeRecords),
                'ready_agent_workforce_runtime_count' => $readyRuntimeCount,
                'agent_workforce_runtime_ready' => $expectedRuntimeCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedRuntimeCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_agent_workforce_runtime_ready_external_effects_blocked'
                    : 'agent_workforce_runtime_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_agent_workforce_runtime_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['agent_workforce_runtime_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_AGENT_WORKFORCE_RUNTIME_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_agent_workforce_runtime_ready_external_effects_blocked'
                : 'enterprise_company_agent_workforce_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'agent_workforce_runtime_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_agent_workforce_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_agent_workforce_runtime_count'] ?? 0), $companies)),
                'persisted_agent_workforce_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_agent_workforce_runtime_count'] ?? 0), $companies)),
                'ready_agent_workforce_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_agent_workforce_runtime_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_agent_operations_pack_status_hash' => $agentOperations['enterprise_company_agent_operations_pack_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->agentWorkforceRuntimePolicy(),
        ];
        $payload['enterprise_company_agent_workforce_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $this->hub->runtimeStatusCache[$cacheKey] = $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $crew
     * @return array<string,mixed>
     */
    public function agentWorkforceRuntimeArtifact(array $company, array $crew, string $subagentId, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($crew['flow_id'] ?? 'unknown_flow');
        $receiptInput = [
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'subagent_id' => $subagentId,
            'run_context_id' => $runContextId,
        ];
        $skills = array_values((array) ($crew['skills'] ?? []));
        $connectorRefs = array_values((array) ($crew['connector_refs'] ?? []));
        $sourceRefs = array_values((array) ($crew['source_refs'] ?? []));

        $skillContract = [
            'schema' => 'atlas.ai.company.agent_workforce_skill_contract.v1',
            'skills' => $skills,
            'minimum_skill_count' => 7,
            'methodology_check_required' => in_array('methodology_or_policy_review', $skills, true) || in_array('methodology_check', $skills, true),
            'source_grounding_required' => in_array('source_linked_retrieval', $skills, true) || in_array('source_grounding', $skills, true),
            'operator_handoff_required' => in_array('operator_handoff_packaging', $skills, true) || in_array('handoff_packet', $skills, true),
        ];
        $skillContract['skill_contract_hash'] = MissionCanonicalHash::sha256($skillContract);

        $connectorScope = [
            'schema' => 'atlas.ai.company.agent_workforce_connector_scope.v1',
            'connector_refs' => $connectorRefs,
            'source_refs' => $sourceRefs,
            'default_permission' => 'read_only_probe_or_fixture',
            'credential_binding' => 'vault_reference_only',
            'credential_material_export_allowed' => false,
            'write_or_mutation_allowed' => false,
            'tool_call_receipt_required' => true,
        ];
        $connectorScope['connector_scope_hash'] = MissionCanonicalHash::sha256($connectorScope);

        $handoff = [
            'schema' => 'atlas.ai.company.agent_workforce_subagent_handoff.v1',
            'primary_agent' => (string) ($crew['primary_agent'] ?? 'primary_agent'),
            'subagent_id' => $subagentId,
            'handoff_mode' => 'bounded_specialist_context_packet',
            'context_minimization_required' => true,
            'must_return' => ['finding', 'evidence_refs', 'risk_flags', 'confidence', 'next_action'],
            'may_not_receive' => ['credential_material', 'irrelevant_private_context', 'unscoped_customer_data'],
            'operator_handoff_required_for_external_effect' => true,
        ];
        $handoff['handoff_contract_hash'] = MissionCanonicalHash::sha256($handoff);

        $guardrail = [
            'schema' => 'atlas.ai.company.agent_workforce_guardrail_contract.v1',
            'policy_mode' => 'fail_closed',
            'blocked_operations' => $this->agentWorkforceRuntimePolicy()['blocked_operations'],
            'tool_guardrails_required' => true,
            'handoff_guardrails_required' => true,
            'human_in_loop_before_sensitive_tool' => true,
            'agent_identity_scope_required' => true,
            'external_side_effects_enabled' => false,
        ];
        $guardrail['guardrail_contract_hash'] = MissionCanonicalHash::sha256($guardrail);

        $observability = [
            'schema' => 'atlas.ai.company.agent_workforce_observability_contract.v1',
            'required_metrics' => ['handoff_count', 'tool_call_count', 'source_ref_count', 'guardrail_block_count', 'latency_ms', 'cost_uusd', 'operator_interrupt_count', 'eval_replay_score'],
            'trace_required' => true,
            'audit_log_required' => true,
            'dashboard' => $companyId.'_agent_workforce_runtime',
        ];
        $observability['observability_contract_hash'] = MissionCanonicalHash::sha256($observability);

        $evalReplay = [
            'schema' => 'atlas.ai.company.agent_workforce_eval_replay_contract.v1',
            'replay_assertions' => ['source_lineage_preserved', 'connector_scope_respected', 'subagent_output_schema_valid', 'guardrails_enforced', 'operator_handoff_present', 'external_effects_blocked', 'rollback_plan_present'],
            'fixture_mode_required_before_live_scope' => true,
            'critic_review_required' => true,
            'deterministic_replay_receipt_required' => true,
        ];
        $evalReplay['eval_replay_contract_hash'] = MissionCanonicalHash::sha256($evalReplay);

        $artifact = [
            'schema' => 'atlas.ai.company.agent_workforce_runtime_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'crew_id' => (string) ($crew['crew_id'] ?? ''),
            'subagent_id' => $subagentId,
            'run_context_id' => $runContextId,
            'agent_template_pattern' => (string) ($crew['agent_template_pattern'] ?? 'skills_connectors_subagents_enterprise_pattern'),
            'skill_contract' => $skillContract,
            'connector_scope' => $connectorScope,
            'subagent_handoff' => $handoff,
            'guardrail_contract' => $guardrail,
            'observability_contract' => $observability,
            'eval_replay_contract' => $evalReplay,
            'receipt_chain' => [
                'agent_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'agent_runtime_receipt']),
                'skill_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'skill_contract_receipt']),
                'connector_scope_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'connector_scope_receipt']),
                'handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'handoff_receipt']),
                'guardrail_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'guardrail_receipt']),
                'observability_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'observability_receipt']),
                'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['agent_workforce_runtime_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @return array<string,mixed>
     */
    public function agentWorkforceRuntimePolicy(): array
    {
        return [
            'agent_workforce_runtime_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'required_runtime_evidence' => ['atlas_tool_run', 'skill_contract', 'connector_scope', 'subagent_handoff', 'guardrail_contract', 'observability_contract', 'eval_replay_contract', 'agent_runtime_receipt', 'audit_trail', 'rollback_plan'],
            'blocked_operations' => ['unscoped_subagent_handoff', 'credential_material_export', 'external_write', 'customer_message', 'invoice', 'collect_payment', 'trade', 'capital_transfer', 'paid_campaign', 'public_publish', 'legal_signature', 'deploy', 'delete', 'offensive_security', 'private_data_export', 'skip_guardrails', 'skip_trace', 'skip_operator_handoff'],
        ];
    }

    public function domainOperatingModelArchetype(string $companyId): string
    {
        return match ($companyId) {
            'finance' => 'financial_services_style_research_modeling_risk_compliance_and_committee_ops',
            'marketing' => 'growth_brand_campaign_lifecycle_and_analytics_ops',
            'cyber' => 'defensive_security_posture_appsec_grc_detection_and_remediation_ops',
            'strategy' => 'portfolio_thesis_market_map_gtm_experiment_and_board_decision_ops',
            'research' => 'source_grounded_research_citation_graph_contradiction_and_synthesis_ops',
            'software' => 'software_delivery_code_intelligence_repair_release_and_security_ops',
            'automation' => 'tool_selection_browser_api_mcp_and_reliability_ops',
            'personal_development' => 'private_learning_planning_skill_evidence_and_reflection_ops',
            default => 'enterprise_operations_domain_specific_operating_model',
        };
    }
}
