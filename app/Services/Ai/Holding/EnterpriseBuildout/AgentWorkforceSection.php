<?php

namespace App\Services\Ai\Holding\EnterpriseBuildout;

/**
 * Method-family section extracted verbatim from AutonomousHoldingEnterpriseBuildoutService (GOD-DEBULK 2026-07-22).
 */
class AgentWorkforceSection
{
    public function __construct(
        private readonly EnterpriseBuildoutSupport $support,
    ) {}

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return list<array<string,mixed>>
     */
    public function enterpriseAgentRegistry(string $domainId, array $blueprint, array $agentRoles): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $agents = array_values(array_unique($agentRoles));

        return array_values(array_map(
            fn (string $agent, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_agent_registry_entry.v1',
                'agent_id' => $domainId.'.'.$agent,
                'company_id' => $domainId,
                'role' => $agent,
                'version' => '2026.05.enterprise',
                'ownership' => [
                    'primary_flows' => array_values(array_filter(
                        array_keys($flowSpecs),
                        static fn (string $flowId): bool => (string) ($flowSpecs[$flowId][0] ?? '') === $agent,
                    )),
                    'backup_role' => $index === 0 ? 'portfolio_governor' : (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                    'escalation_queue' => (string) $blueprint['review_queue'],
                ],
                'capabilities' => [
                    'plan',
                    'retrieve',
                    'analyze',
                    'produce_typed_artifact',
                    'handoff',
                    'explain_with_evidence',
                ],
                'allowed_connector_scope' => $this->support->enterpriseConnectorsForBlueprint($blueprint),
                'memory_scope' => [
                    'company_scoped_memory' => true,
                    'cross_company_memory_requires_handoff' => true,
                    'private_or_regulated_data_requires_policy_profile' => true,
                ],
                'evaluation_contract' => [
                    'minimum_flow_score' => 0.86,
                    'tool_receipts_required' => true,
                    'policy_findings_allowed' => 0,
                    'reviewer' => 'independent_reviewer_agent',
                ],
                'external_side_effects' => false,
                'registry_hash' => hash('sha256', $domainId.'|agent_registry|'.$agent.'|'.$index),
            ],
            $agents,
            array_keys($agents),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return array<string,mixed>
     */
    public function enterpriseDomainAgentToolkitStack(string $domainId, array $blueprint, array $agentRoles): array
    {
        $agents = array_values(array_unique($agentRoles));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $frameworkSources = $this->support->agentFrameworkSourceCatalog($domainId);
        $domainSources = $this->support->domainSolutionSourceCatalog($domainId);
        $frameworkIds = array_column($frameworkSources, 'source_id');
        $domainSourceIds = array_column($domainSources, 'source_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_agent_toolkit_stack.v1',
            'company_id' => $domainId,
            'toolkit_policy' => [
                'mode' => 'domain_specialist_agents_with_certified_toolkits',
                'source_basis' => ['openai_agents_sdk', 'crewai_flows_crews', 'microsoft_agent_framework', 'microsoft_autogen_agent_framework_lineage', 'langgraph_durable_execution', 'model_context_protocol_servers', 'temporal_durable_workflows', 'opentelemetry_collector_tracing', 'anthropic_claude_for_financial_services'],
                'buildout_blocked_by_observed_history_window' => false,
                'runtime_use_before_toolkit_certification_allowed' => false,
                'external_side_effects_default' => false,
                'operator_approval_required_for_toolkit_external_action' => true,
            ],
            'framework_source_catalog' => $frameworkSources,
            'domain_source_catalog_refs' => array_values(array_slice($domainSourceIds, 0, min(6, count($domainSourceIds)))),
            'agent_toolkit_profiles' => array_values(array_map(
                fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'toolkit_id' => $domainId.'.'.$agent.'.toolkit.v1',
                    'primary_framework_patterns' => array_values(array_slice($frameworkIds, 0, min(4, count($frameworkIds)))),
                    'domain_source_refs' => array_values(array_slice($domainSourceIds, $index % max(1, count($domainSourceIds)), min(3, count($domainSourceIds)))),
                    'core_skills' => [
                        'context_pack_loading',
                        'domain_source_selection',
                        'tool_schema_reasoning',
                        'structured_artifact_authoring',
                        'critic_response_repair',
                        'handoff_packet_production',
                    ],
                    'tool_groups' => [
                        'context' => ['open_brain_context_pack', 'domain_memory_read_model', 'source_registry'],
                        'reasoning' => ['scratchpad_state', 'assumption_ledger', 'option_comparison_matrix'],
                        'connector' => $connectors,
                        'verification' => ['policy_gate', 'critic_review', 'evaluation_harness', 'receipt_verifier'],
                    ],
                    'blocked_tool_groups_without_operator' => ['external_write', 'external_publish', 'real_spend', 'live_trade', 'offensive_security', 'secret_export'],
                    'certification_required_before_shadow_mode' => true,
                    'profile_hash' => hash('sha256', $domainId.'|agent_toolkit_profile|'.$agent.'|'.$index),
                ],
                $agents,
                array_keys($agents),
            )),
            'flow_toolkit_assignments' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'assigned_toolkit' => $domainId.'.'.(string) $spec[0].'.toolkit.v1',
                    'required_connector_subset' => array_values((array) $spec[1]),
                    'required_skill_sequence' => ['intake_classification', 'source_grounding', 'tool_plan', 'domain_analysis', 'artifact_generation', 'critic_review', 'policy_gate', 'operator_checkpoint'],
                    'minimum_certification_state' => 'contract_and_fixture_green_before_shadow',
                    'assignment_hash' => hash('sha256', $domainId.'|flow_toolkit_assignment|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'repository_and_agent_watchlist' => [
                'global_agent_frameworks' => [
                    'https://github.com/openai/openai-agents-python',
                    'https://github.com/crewAIInc/crewAI',
                    'https://github.com/microsoft/agent-framework',
                    'https://github.com/microsoft/autogen',
                    'https://github.com/langchain-ai/langgraph',
                    'https://github.com/modelcontextprotocol/servers',
                    'https://github.com/temporalio/sdk-php',
                    'https://github.com/open-telemetry/opentelemetry-collector',
                ],
                'domain_specific_sources' => array_values(array_map(
                    static fn (array $source): string => (string) $source['url'],
                    $domainSources,
                )),
                'watch_review_cadence' => 'weekly_domain_agent_toolkit_review',
                'adoption_requires' => ['license_review', 'security_review', 'local_fixture_eval', 'operator_acceptance', 'rollback_plan'],
                'watchlist_hash' => hash('sha256', $domainId.'|domain_agent_toolkit_watchlist|'.implode('|', $flowIds)),
            ],
            'toolkit_certification_matrix' => array_values(array_map(
                static fn (string $agent): array => [
                    'agent_role' => $agent,
                    'certification_suite' => $agent.'.toolkit_certification.v1',
                    'required_checks' => ['prompt_contract_snapshot', 'tool_schema_contract', 'fixture_replay_green', 'policy_boundary_green', 'receipt_export_green', 'handoff_acceptance_green'],
                    'promotion_blockers' => ['missing_tool_schema', 'missing_receipt', 'policy_finding', 'unsupported_domain_source', 'operator_checkpoint_missing'],
                    'certification_hash' => hash('sha256', 'agent_toolkit_certification|'.$agent),
                ],
                $agents,
            )),
            'toolkit_observability' => [
                'required_metrics' => ['toolkit_profile_coverage', 'flow_toolkit_assignment_coverage', 'tool_schema_contract_coverage', 'fixture_replay_pass_rate', 'policy_boundary_pass_rate', 'handoff_acceptance_rate', 'toolkit_drift_count'],
                'dashboard' => $domainId.'_domain_agent_toolkit_board',
                'alert_on' => ['toolkit_drift', 'missing_schema_contract', 'policy_boundary_failure', 'uncertified_toolkit_used'],
            ],
            'toolkit_stack_hash' => hash('sha256', $domainId.'|enterprise_domain_agent_toolkit|'.implode('|', $agents).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return array<string,mixed>
     */
    public function enterpriseDomainWorkloadAgentTemplateStack(string $domainId, array $blueprint, array $agentRoles): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $domainSources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($domainSources, 'source_id'));

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_workload_agent_template_stack.v1',
            'company_id' => $domainId,
            'reference_architecture' => [
                'pattern' => 'anthropic_financial_services_agents_generalized_to_every_atlas_company',
                'source_url' => 'https://www.anthropic.com/news/finance-agents',
                'announced_at' => '2026-05-05',
                'templates_package' => ['skills', 'connectors', 'subagents'],
                'enterprise_runtime_features' => ['long_running_sessions', 'per_tool_permissions', 'managed_credential_vault', 'full_audit_log', 'human_in_the_loop_approval'],
                'calendar_wait_blocker_enabled' => false,
                'external_execution_authority_granted' => false,
            ],
            'template_policy' => [
                'mode' => 'ready_to_run_domain_workload_templates_external_effects_blocked',
                'template_required_for_every_flow' => true,
                'skills_are_trigger_loaded_not_always_on_context' => true,
                'connectors_are_governed_read_or_fixture_until_operator_scope' => true,
                'subagents_start_with_minimal_scoped_context' => true,
                'audit_log_required_for_every_tool_call_and_decision' => true,
                'human_review_required_before_client_delivery_filing_payment_trade_publish_deploy_delete_or_security_action' => true,
                'external_write_spend_trade_publish_deploy_delete_or_offensive_security_allowed' => false,
            ],
            'workload_agent_templates' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_workload_agent_template.v1',
                    'template_id' => $domainId.'.'.$flowId.'.workload_agent_template.v1',
                    'flow_id' => $flowId,
                    'display_name' => str_replace('_', ' ', $flowId).' agent',
                    'owner_agent' => (string) ($spec[0] ?? ($agentRoles[0] ?? 'domain_operator_agent')),
                    'workload_family' => $this->domainWorkloadFamily($domainId),
                    'target_artifact' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'skills' => $this->support->domainWorkloadSkills($domainId, $flowId),
                    'connectors' => [
                        'required' => array_values((array) ($spec[1] ?? [])),
                        'available_company_connectors' => $connectors,
                        'domain_source_refs' => array_values(array_slice($sourceIds, 0, min(5, count($sourceIds)))),
                        'governance' => ['least_privilege_scope', 'read_only_or_fixture_default', 'schema_snapshot_required', 'source_lineage_required', 'receipt_export_required'],
                    ],
                    'subagents' => $this->support->domainWorkloadSubagents($domainId, $flowId),
                    'source_pattern_receipts' => [
                        'schema' => 'atlas.ai.company.domain_workload_source_pattern_receipts.v1',
                        'reference_pattern' => 'anthropic_financial_services_agents_generalized_to_domain_workloads',
                        'framework_reference_ids' => [
                            'anthropic_financial_services_agents_2026',
                            'openai_agents_sdk',
                            'model_context_protocol_servers',
                            'langgraph_durable_agent_execution',
                            'microsoft_agent_framework',
                            'autogen_multi_agent_conversation',
                        ],
                        'domain_source_refs' => array_values(array_slice($sourceIds, 0, min(5, count($sourceIds)))),
                        'cross_source_verification_required' => true,
                        'unsupported_claim_blocker_enabled' => true,
                        'source_pattern_receipt_hash' => hash('sha256', $domainId.'|'.$flowId.'|source_pattern_receipts|'.implode('|', $sourceIds)),
                    ],
                    'runtime_contract' => [
                        'session_mode' => 'long_running_supervised_session',
                        'credential_binding' => 'managed_vault_reference_only',
                        'tool_permission_model' => 'per_tool_permission_with_operator_scope',
                        'audit_log' => 'decision_tool_call_artifact_and_handoff_receipts',
                        'context_carryover' => 'company_memory_and_flow_handoff_packet',
                        'failure_mode' => 'pause_emit_repair_packet_and_fallback_to_fixture_or_manual_handoff',
                    ],
                    'approval_contract' => [
                        'operator_review_required' => true,
                        'second_reviewer_required_for' => ['client_delivery', 'filing', 'payment', 'trade', 'public_publish', 'deploy', 'delete', 'security_action'],
                        'auto_send_post_pay_trade_publish_deploy_delete_allowed' => false,
                    ],
                    'distribution_package' => [
                        'schema' => 'atlas.ai.company.domain_workload_agent_distribution_package.v1',
                        'package_id' => $domainId.'.'.$flowId.'.agent_package.v1',
                        'surfaces' => ['atlas_cli', 'atlas_desktop', 'atlas_mcp_readonly', 'managed_agent_cookbook'],
                        'plugin_manifest' => [
                            'manifest_id' => $domainId.'.'.$flowId.'.plugin_manifest.v1',
                            'entrypoint' => 'workload_agent_template',
                            'trigger_skills' => $this->support->domainWorkloadSkills($domainId, $flowId),
                            'permission_profile' => 'read_fixture_shadow_until_operator_scope',
                            'external_effects_enabled' => false,
                        ],
                        'managed_agent_cookbook' => [
                            'cookbook_id' => $domainId.'.'.$flowId.'.managed_agent_cookbook.v1',
                            'session_model' => 'long_running_supervised_session',
                            'tool_permissions' => 'per_tool_operator_scoped',
                            'credential_vault' => 'managed_vault_reference_only',
                            'audit_log' => 'full_tool_decision_artifact_handoff_log',
                            'deployment_mode' => 'internal_supervised_candidate',
                        ],
                        'skill_bundle' => [
                            'load_mode' => 'trigger_loaded',
                            'always_on_context_allowed' => false,
                            'skill_count' => count($this->support->domainWorkloadSkills($domainId, $flowId)),
                            'bundle_hash' => hash('sha256', $domainId.'|'.$flowId.'|workload_skill_bundle'),
                        ],
                        'subagent_bundle' => [
                            'context_mode' => 'minimal_scoped_context',
                            'subagent_count' => count($this->support->domainWorkloadSubagents($domainId, $flowId)),
                            'external_effects_enabled' => false,
                            'bundle_hash' => hash('sha256', $domainId.'|'.$flowId.'|workload_subagent_bundle'),
                        ],
                        'connector_permission_manifest' => [
                            'connector_refs' => array_values((array) ($spec[1] ?? [])),
                            'default_mode' => 'fixture_or_read_only_probe',
                            'write_spend_trade_publish_deploy_delete_allowed' => false,
                            'operator_scope_required_for_live_connector' => true,
                            'tool_permission_matrix' => array_values(array_map(
                                static fn (string $connector): array => [
                                    'connector_ref' => $connector,
                                    'default_permission' => 'fixture_or_read_only_probe',
                                    'live_scope_requires_operator' => true,
                                    'write_spend_trade_publish_deploy_delete_allowed' => false,
                                    'permission_hash' => hash('sha256', $domainId.'|'.$flowId.'|tool_permission|'.$connector),
                                ],
                                array_values((array) ($spec[1] ?? [])),
                            )),
                            'permission_matrix_hash' => hash('sha256', $domainId.'|'.$flowId.'|tool_permission_matrix'),
                            'manifest_hash' => hash('sha256', $domainId.'|'.$flowId.'|connector_permission_manifest'),
                        ],
                        'audit_manifest' => [
                            'required_events' => ['template_loaded', 'skill_loaded', 'connector_called', 'subagent_called', 'artifact_created', 'policy_gate_evaluated', 'operator_review_requested'],
                            'receipt_required' => true,
                            'replay_manifest_required' => true,
                            'manifest_hash' => hash('sha256', $domainId.'|'.$flowId.'|audit_manifest'),
                        ],
                        'rollout_plan' => [
                            'stage' => 'internal_shadow_candidate',
                            'install_steps' => ['register_plugin_manifest', 'register_managed_agent_cookbook', 'bind_skill_bundle', 'bind_subagent_bundle', 'bind_connector_permissions', 'bind_audit_manifest'],
                            'smoke_suite' => ['fixture_context_load', 'skill_trigger_load', 'subagent_handoff', 'connector_permission_check', 'artifact_stub_generation', 'audit_receipt_export'],
                            'promotion_gates' => ['all_smoke_checks_green', 'policy_findings_zero', 'operator_review_packet_ready', 'rollback_plan_bound', 'evidence_sink_bound'],
                            'rollback_plan' => ['disable_plugin_entrypoint', 'fallback_to_manual_handoff', 'restore_previous_template_hash', 'record_rollback_receipt'],
                            'evidence_sink' => 'evidence_ledger.domain_workload_agent_package',
                            'auto_promotion_allowed' => false,
                            'external_execution_enabled_after_rollout' => false,
                            'rollout_hash' => hash('sha256', $domainId.'|'.$flowId.'|agent_rollout_plan'),
                        ],
                        'execution_surface_bindings' => [
                            'cli_action' => 'atlas:ai:autonomous-holding --action=enterprise-flow-run-queue-execute --company='.$domainId.' --flow='.$flowId,
                            'mcp_tool' => 'atlas_holding_'.$domainId.'_'.$flowId.'_readonly_shadow',
                            'desktop_panel' => $domainId.'_company_command_center.'.$flowId,
                            'run_queue_contract' => 'enterprise_flow_run_queue_item.v1',
                        'operator_review_surface' => (string) $blueprint['review_queue'],
                        'external_execution_allowed' => false,
                        'surface_binding_hash' => hash('sha256', $domainId.'|'.$flowId.'|execution_surface_bindings'),
                    ],
                    'operator_handoff_contract' => [
                        'schema' => 'atlas.ai.company.domain_workload_operator_handoff_contract.v1',
                        'handoff_id' => $domainId.'.'.$flowId.'.operator_handoff.v1',
                        'required_sections' => ['decision_context', 'source_links', 'tool_calls', 'artifact_diff', 'risk_register', 'rollback_plan', 'approval_scope'],
                        'human_review_required' => true,
                        'second_reviewer_required_for' => ['client_delivery', 'filing', 'payment', 'trade', 'public_publish', 'deploy', 'delete', 'security_action'],
                        'approval_scope_required_before_external_effect' => true,
                        'handoff_contract_hash' => hash('sha256', $domainId.'|'.$flowId.'|operator_handoff_contract'),
                    ],
                    'guardrail_contract' => [
                        'schema' => 'atlas.ai.company.domain_workload_guardrail_contract.v1',
                        'blocked_operations' => ['external_write', 'paid_spend', 'capital_transfer', 'trade', 'public_publish', 'deploy', 'delete', 'offensive_security', 'secret_export', 'unsupported_claim'],
                        'policy_checks' => ['source_grounding', 'credential_scope', 'tool_permission', 'external_effect_boundary', 'operator_approval', 'receipt_export', 'rollback_bound'],
                        'calendar_wait_blocker_enabled' => false,
                        'external_effects_allowed' => false,
                        'guardrail_contract_hash' => hash('sha256', $domainId.'|'.$flowId.'|guardrail_contract'),
                    ],
                    'eval_replay_recipe' => [
                        'schema' => 'atlas.ai.company.domain_workload_eval_replay_recipe.v1',
                        'recipe_id' => $domainId.'.'.$flowId.'.eval_replay.v1',
                        'fixture_suite' => $domainId.'.'.$flowId.'.agent_fixture_suite.v1',
                        'minimum_fixture_cases' => 12,
                        'replay_assertions' => ['source_links_present', 'tool_permissions_read_or_fixture', 'subagent_handoff_receipted', 'artifact_schema_valid', 'policy_findings_zero', 'operator_handoff_ready', 'rollback_plan_bound'],
                        'benchmark_families' => ['domain_claim_grounding', 'connector_scope_control', 'multi_agent_handoff_quality', 'external_effect_boundary', 'artifact_acceptance'],
                        'promotion_requires_green_replay' => true,
                        'external_effects_allowed_during_replay' => false,
                        'eval_replay_recipe_hash' => hash('sha256', $domainId.'|'.$flowId.'|eval_replay_recipe'),
                    ],
                    'fixture_smoke_contract' => [
                        'scenario_id' => $domainId.'.'.$flowId.'.fixture_smoke.v1',
                        'required_fixture_inputs' => ['company_context', 'flow_contract', 'source_snapshot', 'connector_scope', 'expected_artifact_schema'],
                        'replay_steps' => ['load_template', 'load_triggered_skills', 'hydrate_fixture_context', 'validate_connector_scope', 'handoff_to_subagents', 'produce_artifact_stub', 'export_receipt'],
                        'expected_artifacts' => ['tool_plan', 'typed_artifact_stub', 'policy_gate_report', 'handoff_packet'],
                            'pass_criteria' => ['all_required_inputs_present', 'all_tool_permissions_read_or_fixture', 'artifact_schema_valid', 'policy_findings_zero', 'receipt_hash_present'],
                            'policy_boundary_checks' => ['no_external_write', 'no_real_spend', 'no_trade', 'no_public_publish', 'no_deploy_or_delete', 'no_secret_export'],
                            'external_effects_allowed_during_smoke' => false,
                            'fixture_hash' => hash('sha256', $domainId.'|'.$flowId.'|fixture_smoke_contract'),
                        ],
                        'package_hash' => hash('sha256', $domainId.'|'.$flowId.'|agent_distribution_package'),
                    ],
                    'template_hash' => hash('sha256', $domainId.'|workload_agent_template|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_template_coverage_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'template_id' => $domainId.'.'.$flowId.'.workload_agent_template.v1',
                    'required_components' => ['skills', 'connectors', 'subagents', 'runtime_contract', 'approval_contract', 'audit_log'],
                    'coverage_ready' => true,
                    'external_execution_enabled' => false,
                    'coverage_hash' => hash('sha256', $domainId.'|workload_template_coverage|'.$flowId),
                ],
                $flowIds,
            )),
            'template_observability' => [
                'required_metrics' => ['template_coverage_rate', 'skill_load_success_rate', 'connector_scope_pass_rate', 'subagent_handoff_quality', 'audit_log_completeness', 'operator_review_coverage', 'external_effect_block_rate'],
                'dashboard' => $domainId.'_workload_agent_template_board',
                'alert_on' => ['missing_template', 'missing_skill', 'missing_connector_scope', 'subagent_context_overreach', 'audit_gap', 'operator_review_missing', 'external_effect_requested'],
            ],
            'workload_template_stack_hash' => hash('sha256', $domainId.'|domain_workload_agent_templates|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    public function domainWorkloadFamily(string $domainId): string
    {
        return match ($domainId) {
            'finance' => 'financial_services_research_modeling_compliance_and_close',
            'marketing' => 'growth_campaign_lifecycle_brand_and_attribution',
            'cyber' => 'defensive_security_posture_detection_compliance_and_response',
            'software' => 'software_engineering_code_intelligence_repair_and_release',
            'research' => 'source_grounded_research_synthesis_and_claim_verification',
            'strategy' => 'market_strategy_portfolio_experiment_and_board_decision',
            'automation' => 'tool_automation_mcp_adapter_and_reliability',
            'personal_development' => 'private_learning_habit_reflection_and_goal_operations',
            default => 'enterprise_operations_readiness_incident_and_continuity',
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseAgentRepositoryAdoptionPipeline(string $domainId, array $blueprint): array
    {
        $repositoryCatalog = $this->externalResearchRepositoryCatalog($domainId);
        $frameworks = array_values((array) ($repositoryCatalog['official_framework_repositories'] ?? []));
        $domainRepositories = array_values((array) ($repositoryCatalog['domain_repository_candidates'] ?? []));
        $repositories = array_values(array_merge($frameworks, $domainRepositories));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_agent_repository_adoption_pipeline.v1',
            'company_id' => $domainId,
            'pipeline_policy' => [
                'purpose' => 'turn_agent_framework_and_domain_repository_research_into_versioned_enterprise_adoption_work',
                'calendar_wait_blocker_enabled' => false,
                'adoption_requires_license_security_sbo_m_fixture_eval_and_operator_acceptance' => true,
                'adoption_requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'per_flow_repository_adoption_matrix_required' => true,
                'per_flow_tool_permission_manifest_required' => true,
                'per_flow_eval_replay_recipe_required' => true,
                'maintenance_mode_or_deprecation_requires_migration_plan' => true,
                'runtime_use_before_local_contract_tests_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_write_or_procurement' => true,
            ],
            'repository_intake_queue' => array_values(array_map(
                static fn (array $repo, int $index): array => [
                    'schema' => 'atlas.ai.company.repository_intake_item.v1',
                    'repository_id' => (string) ($repo['source_id'] ?? 'repo_'.$index),
                    'url' => (string) ($repo['repository_url'] ?? $repo['repository_or_doc_url'] ?? ''),
                    'capability_or_use' => (string) ($repo['capability'] ?? $repo['use'] ?? 'enterprise_agentic_runtime_candidate'),
                    'source_kind' => isset($repo['repository_url']) ? 'agent_framework_repository' : 'domain_repository_or_api_docs',
                    'intake_status' => 'candidate_requires_review',
                    'required_reviews' => ['license', 'security', 'maintenance_status', 'runtime_boundary', 'data_boundary', 'operator_fit'],
                    'required_artifacts' => ['version_pin', 'sbom_or_dependency_snapshot', 'fixture_eval_result', 'rollback_plan', 'adoption_decision_receipt'],
                    'review_contract' => [
                        'license_security_review_required' => true,
                        'runtime_boundary_review_required' => true,
                        'secret_and_credential_boundary_review_required' => true,
                        'mcp_or_tool_permission_review_required' => true,
                        'operator_acceptance_required_before_runtime_use' => true,
                    ],
                    'external_side_effects_enabled' => false,
                    'intake_hash' => hash('sha256', $domainId.'|repository_intake|'.(string) ($repo['source_id'] ?? 'repo_'.$index)),
                ],
                $repositories,
                array_keys($repositories),
            )),
            'framework_adoption_scorecards' => array_values(array_map(
                static fn (array $repo): array => [
                    'schema' => 'atlas.ai.company.framework_adoption_scorecard.v1',
                    'framework_id' => (string) ($repo['source_id'] ?? 'unknown_framework'),
                    'url' => (string) ($repo['repository_url'] ?? ''),
                    'fit_dimensions' => ['handoffs', 'tooling', 'guardrails', 'tracing', 'durable_resume', 'human_checkpoint', 'mcp_or_a2a', 'maintenance_posture'],
                    'risk_findings' => (string) ($repo['source_id'] ?? '') === 'microsoft_autogen'
                        ? ['maintenance_mode_detected_use_microsoft_agent_framework_migration_path_before_new_adoption']
                        : [],
                    'enterprise_pattern_contract' => [
                        'tool_call_receipts_required' => true,
                        'handoff_contract_required' => true,
                        'trace_export_required' => true,
                        'durable_resume_or_checkpoint_required' => true,
                        'human_interrupt_required_for_sensitive_tools' => true,
                        'mcp_or_connector_permission_manifest_required' => true,
                    ],
                    'minimum_evidence_before_adoption' => ['sample_flow_trace', 'tool_receipt_export', 'fixture_eval_green', 'security_review_green', 'license_review_green'],
                    'adoption_state' => (string) ($repo['source_id'] ?? '') === 'microsoft_autogen'
                        ? 'migration_reference_only'
                        : 'candidate_for_contract_fixture',
                    'external_side_effects_enabled' => false,
                    'scorecard_hash' => hash('sha256', $domainId.'|framework_scorecard|'.(string) ($repo['source_id'] ?? 'unknown_framework')),
                ],
                $frameworks,
            )),
            'flow_repository_adoption_matrix' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_repository_adoption_matrix_row.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'framework_refs' => array_values(array_unique(array_slice(
                        array_merge(
                            array_slice(array_column($frameworks, 'source_id'), $index % max(1, count($frameworks)), min(4, count($frameworks))),
                            ['openai_agents_python', 'langgraph', 'model_context_protocol_servers', 'opentelemetry_collector'],
                        ),
                        0,
                        6,
                    ))),
                    'domain_repository_refs' => array_values(array_slice(
                        array_column($domainRepositories, 'source_id'),
                        $index % max(1, count($domainRepositories)),
                        min(3, count($domainRepositories)),
                    )),
                    'license_security_review' => [
                        'license_record_required' => true,
                        'security_review_required' => true,
                        'sbom_or_dependency_snapshot_required' => true,
                        'known_vulnerability_check_required' => true,
                        'unreviewed_repository_runtime_use_allowed' => false,
                    ],
                    'runtime_boundary_review' => [
                        'laravel_orchestration_contract_required' => true,
                        'specialized_runtime_adapter_required_before_non_php_execution' => true,
                        'credential_material_in_packets_allowed' => false,
                        'external_mutation_allowed_before_operator_mandate' => false,
                    ],
                    'tool_permission_manifest_ref' => $domainId.'.'.$flowId.'.repository_tool_permission_manifest.v1',
                    'eval_replay_recipe_ref' => $domainId.'.'.$flowId.'.repository_eval_replay_recipe.v1',
                    'operator_acceptance_contract_ref' => $domainId.'.'.$flowId.'.repository_operator_acceptance.v1',
                    'external_side_effects_enabled' => false,
                    'matrix_hash' => hash('sha256', $domainId.'|flow_repository_adoption_matrix|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_repository_implementation_epics' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_repository_implementation_epic.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_framework_ref' => (string) ($frameworks[$index % max(1, count($frameworks))]['source_id'] ?? 'openai_agents_python'),
                    'domain_repository_refs' => array_values(array_slice(
                        array_column($domainRepositories, 'source_id'),
                        0,
                        min(3, count($domainRepositories)),
                    )),
                    'implementation_steps' => [
                        'pin_repository_versions',
                        'generate_adapter_contract',
                        'build_fixture_dataset',
                        'run_policy_and_security_review',
                        'execute_local_fixture_eval',
                        'wire_receipts_and_trace_export',
                        'document_rollback_or_migration_plan',
                    ],
                    'definition_of_done' => [
                        'version_pin_recorded',
                        'sbom_or_dependency_snapshot_present',
                        'fixture_eval_green',
                        'tool_receipts_complete',
                        'operator_checkpoint_supported',
                        'external_side_effects_false',
                    ],
                    'external_execution_allowed' => false,
                    'epic_hash' => hash('sha256', $domainId.'|flow_repository_epic|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_tool_permission_manifests' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_repository_tool_permission_manifest.v1',
                    'manifest_id' => $domainId.'.'.$flowId.'.repository_tool_permission_manifest.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'allowed_permissions_before_operator_mandate' => ['read_fixture', 'read_manual_import', 'draft_artifact', 'run_fixture_eval', 'export_trace_receipt', 'prepare_operator_handoff'],
                    'blocked_permissions' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'credential_policy' => 'vault_reference_only_no_secret_material_in_repository_adoption_packets',
                    'mcp_security_profile_required' => true,
                    'human_interrupt_required_for_sensitive_tool' => true,
                    'external_side_effects_enabled' => false,
                    'manifest_hash' => hash('sha256', $domainId.'|repository_tool_permission_manifest|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_eval_replay_recipes' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_repository_eval_replay_recipe.v1',
                    'recipe_id' => $domainId.'.'.$flowId.'.repository_eval_replay_recipe.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'minimum_fixture_cases' => 25,
                    'adversarial_tool_prompt_cases' => 5,
                    'required_replay_modes' => ['contract_fixture', 'shadow_read_only', 'state_checkpoint_replay', 'handoff_trace_replay', 'rollback_rehearsal'],
                    'required_assertions' => ['tool_permission_enforced', 'trace_export_present', 'receipt_export_present', 'source_lineage_present', 'external_side_effects_false'],
                    'promotion_requires_operator_acceptance' => true,
                    'external_execution_allowed' => false,
                    'recipe_hash' => hash('sha256', $domainId.'|repository_eval_replay_recipe|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'version_pin_and_supply_chain_plan' => array_values(array_map(
                static fn (array $repo, int $index): array => [
                    'repository_id' => (string) ($repo['source_id'] ?? 'repo_'.$index),
                    'pinning_mode' => 'explicit_version_or_commit_before_runtime',
                    'required_supply_chain_artifacts' => ['license_record', 'dependency_snapshot', 'security_review', 'known_vulnerability_check', 'upgrade_rollback_plan'],
                    'auto_upgrade_allowed' => false,
                    'production_runtime_allowed_before_pin' => false,
                    'pin_hash' => hash('sha256', 'repository_version_pin|'.(string) ($repo['source_id'] ?? 'repo_'.$index)),
                ],
                $repositories,
                array_keys($repositories),
            )),
            'migration_and_deprecation_matrix' => [
                'schema' => 'atlas.ai.company.repository_migration_and_deprecation_matrix.v1',
                'tracked_risks' => ['maintenance_mode', 'license_change', 'security_advisory', 'api_breaking_change', 'provider_lock_in', 'missing_trace_export'],
                'known_migration_paths' => [
                    [
                        'from' => 'microsoft_autogen',
                        'to' => 'microsoft_agent_framework',
                        'reason' => 'autogen_maintenance_mode_successor_framework_for_new_enterprise_adoption',
                        'migration_required_before_new_runtime_adoption' => true,
                    ],
                    [
                        'from' => 'framework_without_durable_resume',
                        'to' => 'langgraph_or_temporal_backed_runtime',
                        'reason' => 'long_running_company_flows_require_checkpoint_resume_and_idempotent_replay',
                        'migration_required_before_supervised_runtime' => true,
                    ],
                ],
                'matrix_hash' => hash('sha256', $domainId.'|repository_migration_deprecation_matrix'),
            ],
            'pipeline_observability' => [
                'required_metrics' => [
                    'repository_review_coverage',
                    'framework_scorecard_coverage',
                    'flow_epic_coverage',
                    'version_pin_coverage',
                    'fixture_eval_green_rate',
                    'security_review_green_rate',
                    'migration_risk_count',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_agent_repository_adoption_pipeline_board',
                'alert_on' => ['unreviewed_repo_used', 'missing_version_pin', 'security_review_missing', 'maintenance_mode_without_migration', 'runtime_used_before_fixture_green'],
            ],
            'pipeline_hash' => hash('sha256', $domainId.'|agent_repository_adoption_pipeline|'.implode('|', array_column($repositories, 'source_id')).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseAgentRepositoryOperatingCatalog(string $domainId, array $blueprint): array
    {
        $repositoryCatalog = $this->externalResearchRepositoryCatalog($domainId);
        $frameworks = array_values((array) ($repositoryCatalog['official_framework_repositories'] ?? []));
        $domainRepositories = array_values((array) ($repositoryCatalog['domain_repository_candidates'] ?? []));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $frameworkIds = array_values(array_map(static fn (array $repo): string => (string) ($repo['source_id'] ?? 'unknown_framework'), $frameworks));
        $domainRepoIds = array_values(array_map(static fn (array $repo): string => (string) ($repo['source_id'] ?? 'unknown_domain_repo'), $domainRepositories));
        $securityThreats = [
            'untrusted_mcp_stdio_command_surface',
            'prompt_injection_through_tool_descriptions_or_remote_content',
            'credential_scope_expansion',
            'supply_chain_version_drift',
            'trace_export_of_sensitive_inputs',
            'external_side_effect_without_receipt',
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_agent_repository_operating_catalog.v1',
            'company_id' => $domainId,
            'source_basis' => [
                [
                    'source_id' => 'openai_agents_sdk',
                    'url' => 'https://github.com/openai/openai-agents-python',
                    'adopted_pattern' => 'agents_with_tools_handoffs_guardrails_sessions_tracing_and_sandbox_workspace_patterns',
                ],
                [
                    'source_id' => 'model_context_protocol_reference_servers',
                    'url' => 'https://github.com/modelcontextprotocol/servers',
                    'adopted_pattern' => 'reference_mcp_servers_for_secure_controlled_tool_and_data_access_not_drop_in_production_without_hardening',
                ],
                [
                    'source_id' => 'langgraph_human_in_loop_interrupts',
                    'url' => 'https://docs.langchain.com/oss/python/langgraph/human-in-the-loop',
                    'adopted_pattern' => 'checkpointed_interrupt_resume_for_human_approval_and_error_recovery',
                ],
                [
                    'source_id' => 'microsoft_agent_framework',
                    'url' => 'https://learn.microsoft.com/en-us/agent-framework/',
                    'adopted_pattern' => 'enterprise_agent_orchestration_with_workflows_observability_and_governance',
                ],
                [
                    'source_id' => 'microsoft_autogen',
                    'url' => 'https://github.com/microsoft/autogen',
                    'adopted_pattern' => 'multi_agent_orchestration_lineage_and_migration_signal_toward_microsoft_agent_framework',
                ],
                [
                    'source_id' => 'model_context_protocol_php_sdk',
                    'url' => 'https://github.com/modelcontextprotocol/php-sdk',
                    'adopted_pattern' => 'php_mcp_client_server_contract_reference_for_laravel_boundary_compatible_adapters',
                ],
            ],
            'catalog_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'current_research_and_fixture_evidence_replaces_fixed_day_wait' => true,
                'reference_repositories_are_not_runtime_authority' => true,
                'mcp_reference_servers_require_security_hardening_before_live_use' => true,
                'runtime_use_requires_version_pin_contract_tests_trace_export_and_receipts' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
            ],
            'framework_operating_profiles' => array_values(array_map(
                static fn (array $repo): array => [
                    'framework_id' => (string) ($repo['source_id'] ?? 'unknown_framework'),
                    'repository_url' => (string) ($repo['repository_url'] ?? ''),
                    'operating_role' => match ((string) ($repo['source_id'] ?? '')) {
                        'openai_agents_python' => 'typed_agent_orchestration_tools_handoffs_guardrails_and_tracing',
                        'model_context_protocol_servers' => 'connector_reference_patterns_and_mcp_adapter_contract_tests',
                        'langgraph' => 'durable_graph_checkpoint_interrupt_resume_and_human_in_loop',
                        'microsoft_agent_framework' => 'enterprise_multi_agent_workflow_governance_and_observability_candidate',
                        'temporal' => 'durable_business_workflow_replay_retry_and_idempotency_backplane',
                        'opentelemetry_collector' => 'runtime_trace_metric_log_collection_and_audit_export',
                        default => 'agentic_runtime_or_tooling_reference_candidate',
                    },
                    'required_before_runtime' => ['version_pin', 'license_review', 'security_review', 'fixture_eval_green', 'trace_export_contract', 'receipt_export_contract', 'rollback_plan'],
                    'pattern_bindings' => [
                        'tools' => true,
                        'handoffs_or_agent_collaboration' => true,
                        'guardrails_or_policy_checks' => true,
                        'tracing_or_telemetry' => true,
                        'durable_resume_or_checkpoint' => in_array((string) ($repo['source_id'] ?? ''), ['langgraph', 'temporal', 'microsoft_agent_framework'], true),
                        'human_interrupt_or_checkpoint' => true,
                        'mcp_or_connector_contract' => in_array((string) ($repo['source_id'] ?? ''), ['model_context_protocol_servers', 'microsoft_agent_framework', 'openai_agents_python'], true),
                    ],
                    'runtime_boundary_contract' => [
                        'adapter_required' => true,
                        'local_contract_tests_required' => true,
                        'trace_receipt_export_required' => true,
                        'external_side_effects_blocked_without_operator_mandate' => true,
                    ],
                    'blocked_without_operator' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_security', 'secret_export'],
                    'profile_hash' => hash('sha256', 'agent_repository_operating_profile|'.(string) ($repo['source_id'] ?? 'unknown_framework')),
                ],
                $frameworks,
            )),
            'mcp_connector_security_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'mcp_profile_id' => $connector.'.mcp_security_profile.v1',
                    'allowed_transports_before_review' => ['fixture', 'manual_import', 'read_only_http_or_sdk_adapter'],
                    'stdio_process_execution_allowed' => false,
                    'tool_description_trust_boundary' => 'untrusted_until_schema_signed_and_fixture_replayed',
                    'required_controls' => ['allowlisted_tool_names', 'argument_schema_validation', 'credential_vault_ref', 'rate_limit', 'audit_log', 'receipt_export', 'disable_switch'],
                    'threats_modelled' => $securityThreats,
                    'external_mutation_allowed' => false,
                    'security_profile_hash' => hash('sha256', 'mcp_connector_security_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_runtime_adoption_map' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_runtime_repository_adoption_map.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_framework' => (string) ($frameworkIds[$index % max(1, count($frameworkIds))] ?? 'openai_agents_python'),
                    'required_framework_patterns' => ['tool_plan', 'handoff_contract', 'guardrails', 'trace_export', 'durable_resume_or_checkpoint', 'human_interrupt', 'receipt_export'],
                    'domain_repository_refs' => array_values(array_slice($domainRepoIds, $index % max(1, count($domainRepoIds)), min(3, count($domainRepoIds)))),
                    'connector_security_profiles' => array_values(array_map(
                        static fn (string $connector): string => $connector.'.mcp_security_profile.v1',
                        (array) ($spec[1] ?? []),
                    )),
                    'eval_requirements' => ['contract_fixture', 'adversarial_tool_prompt', 'trace_grade', 'state_assertion', 'source_faithfulness', 'policy_boundary'],
                    'runtime_authority' => 'internal_fixture_or_read_only_shadow_until_operator_signed_scope',
                    'external_side_effects_enabled' => false,
                    'map_hash' => hash('sha256', $domainId.'|flow_runtime_repository_adoption_map|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'supply_chain_and_eval_controls' => [
                'schema' => 'atlas.ai.company.agent_repository_supply_chain_eval_controls.v1',
                'required_artifacts' => ['pinned_version_or_commit', 'license_record', 'security_review', 'dependency_snapshot', 'fixture_eval_result', 'trace_sample', 'receipt_export_sample', 'rollback_plan'],
                'minimum_eval_cases_per_flow' => 25,
                'adversarial_tool_prompt_cases_required' => 5,
                'state_replay_required' => true,
                'synthetic_performance_claims_allowed' => false,
                'auto_upgrade_allowed' => false,
                'controls_hash' => hash('sha256', $domainId.'|agent_repository_supply_chain_eval_controls'),
            ],
            'operating_catalog_observability' => [
                'required_metrics' => ['framework_profile_coverage', 'mcp_security_profile_coverage', 'flow_runtime_adoption_coverage', 'version_pin_coverage', 'fixture_eval_green_rate', 'trace_export_coverage', 'external_effect_block_rate'],
                'dashboard' => $domainId.'_agent_repository_operating_catalog_board',
                'alert_on' => ['unprofiled_framework_used', 'mcp_security_profile_missing', 'flow_without_runtime_map', 'fixture_eval_missing', 'external_effect_requested'],
            ],
            'operating_catalog_hash' => hash('sha256', $domainId.'|agent_repository_operating_catalog|'.implode('|', $frameworkIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalResearchRepositoryCatalog(string $domainId): array
    {
        $domainRepositories = match ($domainId) {
            'software' => [
                ['source_id' => 'tree_sitter', 'repository_or_doc_url' => 'https://github.com/tree-sitter/tree-sitter', 'use' => 'incremental_ast_parsing_for_code_intelligence'],
                ['source_id' => 'semgrep', 'repository_or_doc_url' => 'https://github.com/semgrep/semgrep', 'use' => 'static_analysis_and_security_rule_benchmarking'],
                ['source_id' => 'opentelemetry_collector', 'repository_or_doc_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'use' => 'runtime_telemetry_pipeline_reference'],
            ],
            'research' => [
                ['source_id' => 'semantic_scholar_api', 'repository_or_doc_url' => 'https://www.semanticscholar.org/product/api', 'use' => 'citation_graph_and_source_quality'],
                ['source_id' => 'openalex_docs', 'repository_or_doc_url' => 'https://docs.openalex.org/', 'use' => 'scholarly_graph_reference'],
                ['source_id' => 'grobid', 'repository_or_doc_url' => 'https://github.com/kermitt2/grobid', 'use' => 'paper_pdf_structure_extraction_candidate'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'repository_or_doc_url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_strategy_inputs'],
                ['source_id' => 'world_bank_api', 'repository_or_doc_url' => 'https://datahelpdesk.worldbank.org/knowledgebase/topics/125589-developer-information', 'use' => 'macro_market_data'],
                ['source_id' => 'fred_api', 'repository_or_doc_url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'economic_series_for_scenario_models'],
            ],
            'finance' => [
                ['source_id' => 'openbb', 'repository_or_doc_url' => 'https://github.com/OpenBB-finance/OpenBB', 'use' => 'financial_research_terminal_reference'],
                ['source_id' => 'sec_edgar_apis', 'repository_or_doc_url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'filings_and_disclosure_data'],
                ['source_id' => 'anthropic_financial_services', 'repository_or_doc_url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'skills_connectors_subagents_financial_agent_pattern'],
            ],
            'marketing' => [
                ['source_id' => 'google_ads_api', 'repository_or_doc_url' => 'https://developers.google.com/google-ads/api/docs/campaigns', 'use' => 'campaign_context_and_reporting'],
                ['source_id' => 'hubspot_crm_api', 'repository_or_doc_url' => 'https://developers.hubspot.com/docs/api/crm/understanding-the-crm', 'use' => 'crm_lifecycle_context'],
                ['source_id' => 'posthog', 'repository_or_doc_url' => 'https://github.com/PostHog/posthog', 'use' => 'product_analytics_and_experiment_reference'],
            ],
            'cyber' => [
                ['source_id' => 'mitre_attack', 'repository_or_doc_url' => 'https://attack.mitre.org/', 'use' => 'threat_modeling_and_detection_mapping'],
                ['source_id' => 'osquery', 'repository_or_doc_url' => 'https://github.com/osquery/osquery', 'use' => 'endpoint_state_query_reference'],
                ['source_id' => 'opencti', 'repository_or_doc_url' => 'https://github.com/OpenCTI-Platform/opencti', 'use' => 'threat_intelligence_graph_reference'],
            ],
            'automation' => [
                ['source_id' => 'model_context_protocol_servers', 'repository_or_doc_url' => 'https://github.com/modelcontextprotocol/servers', 'use' => 'mcp_server_catalog_reference'],
                ['source_id' => 'playwright', 'repository_or_doc_url' => 'https://github.com/microsoft/playwright', 'use' => 'browser_automation_reference'],
                ['source_id' => 'n8n', 'repository_or_doc_url' => 'https://github.com/n8n-io/n8n', 'use' => 'workflow_automation_reference'],
            ],
            'personal_development' => [
                ['source_id' => 'xapi_spec', 'repository_or_doc_url' => 'https://github.com/adlnet/xAPI-Spec', 'use' => 'learning_experience_records'],
                ['source_id' => 'open_badges', 'repository_or_doc_url' => 'https://www.imsglobal.org/spec/ob/v3p0/', 'use' => 'skill_credential_record_reference'],
                ['source_id' => 'caldav', 'repository_or_doc_url' => 'https://www.rfc-editor.org/rfc/rfc4791', 'use' => 'calendar_context_reference'],
            ],
            default => [
                ['source_id' => 'opentelemetry_collector', 'repository_or_doc_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'use' => 'observability_pipeline_reference'],
                ['source_id' => 'prometheus', 'repository_or_doc_url' => 'https://github.com/prometheus/prometheus', 'use' => 'metrics_alerting_reference'],
                ['source_id' => 'grafana', 'repository_or_doc_url' => 'https://github.com/grafana/grafana', 'use' => 'dashboard_operations_reference'],
            ],
        };

        return [
            'official_framework_repositories' => [
                ['source_id' => 'openai_agents_python', 'repository_url' => 'https://github.com/openai/openai-agents-python', 'capability' => 'agents_tools_handoffs_guardrails_tracing'],
                ['source_id' => 'crewai', 'repository_url' => 'https://github.com/crewAIInc/crewAI', 'capability' => 'crew_and_flow_orchestration'],
                ['source_id' => 'microsoft_agent_framework', 'repository_url' => 'https://github.com/microsoft/agent-framework', 'capability' => 'production_grade_agents_multi_agent_workflows_durability_observability_governance_human_in_loop'],
                ['source_id' => 'microsoft_autogen', 'repository_url' => 'https://github.com/microsoft/autogen', 'capability' => 'multi_agent_conversation_and_enterprise_framework_lineage'],
                ['source_id' => 'langgraph', 'repository_url' => 'https://github.com/langchain-ai/langgraph', 'capability' => 'durable_graph_execution_human_interrupts'],
                ['source_id' => 'temporal', 'repository_url' => 'https://github.com/temporalio/sdk-php', 'capability' => 'durable_workflow_replay_and_failure_recovery_candidate'],
                ['source_id' => 'model_context_protocol_servers', 'repository_url' => 'https://github.com/modelcontextprotocol/servers', 'capability' => 'mcp_tool_server_patterns'],
                ['source_id' => 'opentelemetry_collector', 'repository_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'capability' => 'agent_runtime_trace_metric_log_collection_and_export_reference'],
            ],
            'domain_repository_candidates' => array_values(array_map(
                static fn (array $repo): array => [
                    ...$repo,
                    'adoption_requires' => ['license_review', 'security_review', 'local_fixture_eval', 'operator_acceptance'],
                    'external_side_effects_enabled' => false,
                    'repository_hash' => hash('sha256', 'domain_repository_candidate|'.(string) $repo['source_id']),
                ],
                $domainRepositories,
            )),
            'repository_watch_policy' => [
                'review_cadence' => 'weekly_or_before_adoption',
                'pin_version_before_runtime_use' => true,
                'security_review_required' => true,
                'license_review_required' => true,
                'runtime_adoption_requires_local_contract_tests' => true,
            ],
            'repository_catalog_hash' => hash('sha256', $domainId.'|external_research_repository_catalog|'.implode('|', array_column($domainRepositories, 'source_id'))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseWorkforceCapacityStack(string $domainId, array $blueprint): array
    {
        $agents = array_values((array) $blueprint['agent_roles']);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_workforce_capacity_stack.v1',
            'company_id' => $domainId,
            'org_model' => [
                'manager_agent' => (string) ($agents[0] ?? 'company_manager_agent'),
                'specialist_count' => max(0, count($agents) - 1),
                'independent_reviewer' => 'independent_reviewer_agent',
                'operator_escalation' => 'operator',
                'coverage_model' => 'manager_specialists_independent_review_operator_checkpoint',
            ],
            'agent_capacity_plan' => array_values(array_map(
                static fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'primary_capacity_units' => $index === 0 ? 4 : 3,
                    'review_capacity_units' => $agent === 'independent_reviewer_agent' ? 6 : 2,
                    'max_parallel_flows' => $index === 0 ? 3 : 2,
                    'requires_backup' => true,
                    'capacity_hash' => hash('sha256', 'agent_capacity|'.$agent),
                ],
                $agents,
                array_keys($agents),
            )),
            'flow_staffing_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'backup_agent' => 'independent_reviewer_agent',
                    'reviewer' => 'independent_reviewer_agent',
                    'operator_checkpoint_required' => true,
                    'minimum_staffing_state' => 'primary_backup_reviewer_defined',
                    'staffing_hash' => hash('sha256', 'flow_staffing|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'training_and_enablement' => array_values(array_map(
                static fn (string $agent): array => [
                    'agent_role' => $agent,
                    'required_training' => [
                        'policy_profile_handling',
                        'source_and_receipt_discipline',
                        'handoff_packet_quality',
                        'incident_and_escalation_protocol',
                    ],
                    'certification_required_before_shadow_mode' => true,
                    'recertification_cadence' => 'monthly_or_after_policy_change',
                    'training_hash' => hash('sha256', 'agent_training|'.$agent),
                ],
                $agents,
            )),
            'succession_and_continuity' => [
                'single_agent_bottleneck_allowed' => false,
                'manual_operator_fallback_required' => true,
                'backup_assignment_required_for_every_flow' => true,
                'continuity_artifacts' => ['runbook', 'handoff_packet', 'last_good_checkpoint', 'decision_log'],
            ],
            'capacity_observability' => [
                'required_metrics' => ['agent_utilization', 'review_queue_depth', 'flow_wip', 'handoff_wait_time', 'blocked_capacity_count'],
                'dashboard' => $domainId.'_workforce_capacity_board',
                'alert_on' => ['review_queue_over_limit', 'missing_backup_agent', 'flow_wip_over_limit'],
            ],
            'workforce_hash' => hash('sha256', $domainId.'|workforce_capacity|'.implode('|', $agents).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function agentCollaborationModel(string $domainId, array $blueprint): array
    {
        $agents = array_values((array) $blueprint['agent_roles']);

        return [
            'domain_id' => $domainId,
            'orchestration' => 'manager_with_specialist_handoffs_and_independent_reviewer',
            'patterns' => [
                'planner_assigns_specialists',
                'specialists_emit_typed_work_products',
                'critic_reviews_before_operator_checkpoint',
                'operator_checkpoint_required_for_external_action',
                'run_hooks_capture_agent_tool_handoff_events',
            ],
            'manager_agent' => (string) ($agents[0] ?? 'company_manager_agent'),
            'specialist_agents' => array_slice($agents, 1),
            'review_agent' => 'independent_reviewer_agent',
            'handoff_contract' => [
                'requires_handoff_description' => true,
                'requires_input_schema' => true,
                'requires_output_schema' => true,
                'handoff_hash' => hash('sha256', $domainId.'|agent_collaboration|'.implode('|', $agents)),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseDomainAgentWorkforceStack(string $domainId, array $blueprint): array
    {
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $profile = $this->support->domainOperatingDepthProfile($domainId);
        $domainSkills = array_values((array) $profile['skills']);
        $domainSystems = array_values((array) $profile['systems']);
        $domainDataProducts = array_values((array) $profile['data_products']);
        $agentRoles = array_values((array) $blueprint['agent_roles']);

        $commonWorkforceSkills = [
            'objective_intake',
            'source_linked_retrieval',
            'tool_permission_planning',
            'domain_artifact_authoring',
            'methodology_or_policy_review',
            'operator_handoff_packaging',
            'learning_update',
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_agent_workforce_stack.v1',
            'company_id' => $domainId,
            'reference_architecture' => [
                'anthropic_finance_agents' => 'skills_connectors_subagents_packaged_per_workflow',
                'anthropic_finance_agents_url' => 'https://www.anthropic.com/news/finance-agents',
                'anthropic_financial_services_solution' => 'unified_data_sources_direct_source_links_enterprise_connectors_implementation_support',
                'anthropic_financial_services_solution_url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'openai_agents_sdk' => 'tools_handoffs_guardrails_sessions_and_tracing',
                'openai_agents_sdk_url' => 'https://openai.github.io/openai-agents-python/agents/',
                'langgraph_pattern' => 'durable_state_checkpointing_and_human_interrupts_for_sensitive_workflows',
                'langgraph_url' => 'https://www.langchain.com/langgraph',
                'microsoft_autogen_pattern' => 'multi_agent_collaboration_and_enterprise_agent_framework_research_input',
                'microsoft_autogen_url' => 'https://github.com/microsoft/autogen',
            ],
            'workforce_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'managed_agent_crew_required_for_every_flow' => true,
                'skills_connectors_subagents_required_for_every_flow' => true,
                'per_tool_permission_manifest_required' => true,
                'credential_vault_reference_required' => true,
                'full_audit_log_required' => true,
                'long_running_session_resume_required' => true,
                'human_review_required_before_customer_filing_or_external_action' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
            ],
            'managed_agent_catalog' => array_values(array_map(
                static fn (string $role, int $index): array => [
                    'schema' => 'atlas.ai.company.managed_domain_agent.v1',
                    'agent_id' => $domainId.'.'.$role.'.managed_agent',
                    'role' => $role,
                    'skill_refs' => array_values(array_unique(array_merge(
                        ['source_grounding', 'tool_receipt_capture', 'artifact_quality_review', 'operator_handoff'],
                        array_slice($domainSkills, $index % max(1, count($domainSkills)), min(4, count($domainSkills))),
                    ))),
                    'default_connector_refs' => array_values(array_slice($connectors, $index % max(1, count($connectors)), min(3, count($connectors)))),
                    'default_system_refs' => array_values(array_slice($domainSystems, $index % max(1, count($domainSystems)), min(3, count($domainSystems)))),
                    'permissions' => ['read_fixture', 'read_manual_import', 'draft_artifact', 'run_critic', 'prepare_handoff'],
                    'blocked_permissions' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                    'agent_hash' => hash('sha256', 'managed_domain_agent|'.$role),
                ],
                $agentRoles,
                array_keys($agentRoles),
            )),
            'flow_agent_crews' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_agent_crew.v1',
                    'crew_id' => $domainId.'.'.$flowId.'.agent_crew.v1',
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'agent_template_pattern' => $domainId === 'finance'
                        ? 'claude_financial_services_ready_to_run_agent_template'
                        : 'claude_financial_services_style_agent_template_generalized_to_'.$domainId,
                    'skills' => array_values(array_unique(array_merge(
                        $commonWorkforceSkills,
                        array_slice($domainSkills, $index % max(1, count($domainSkills)), min(5, count($domainSkills))),
                    ))),
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'subagents' => [
                        $domainId.'.'.$flowId.'.research_or_source_subagent',
                        $domainId.'.'.$flowId.'.model_or_methodology_subagent',
                        $domainId.'.'.$flowId.'.artifact_factory_subagent',
                        $domainId.'.'.$flowId.'.risk_compliance_subagent',
                        $domainId.'.'.$flowId.'.operator_handoff_subagent',
                    ],
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'enterprise_system_refs' => array_values(array_slice($domainSystems, $index % max(1, count($domainSystems)), min(4, count($domainSystems)))),
                    'data_product_refs' => array_values(array_slice($domainDataProducts, $index % max(1, count($domainDataProducts)), min(4, count($domainDataProducts)))),
                    'work_surface_adapters' => ['spreadsheet', 'document', 'presentation', 'email_draft', 'case_queue', 'dashboard'],
                    'managed_runtime_controls' => [
                        'long_running_session' => true,
                        'resume_token_required' => true,
                        'per_tool_permissions' => true,
                        'credential_vault_ref_only' => true,
                        'full_audit_log' => true,
                        'tool_call_receipts_required' => true,
                        'human_interrupt_before_sensitive_tool' => true,
                        'external_side_effects_enabled' => false,
                    ],
                    'work_queue_contract' => [
                        'queue_id' => $domainId.'.'.$flowId.'.work_queue',
                        'states' => ['queued', 'scoped', 'retrieving', 'analyzing', 'artifact_draft', 'critic_review', 'operator_handoff', 'accepted', 'learning_update'],
                        'dead_letter_required' => true,
                        'idempotency_key_required' => true,
                    ],
                    'acceptance_contract' => [
                        'minimum_fixture_cases' => 25,
                        'minimum_shadow_replays' => 5,
                        'source_faithfulness_floor' => 0.95,
                        'domain_correctness_floor' => 0.9,
                        'policy_findings_allowed' => 0,
                        'operator_acceptance_required' => true,
                    ],
                    'crew_hash' => hash('sha256', $domainId.'|enterprise_flow_agent_crew|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'workforce_control_plane' => [
                'session_store' => $domainId.'_managed_agent_sessions',
                'audit_log' => $domainId.'_managed_agent_audit_log',
                'permission_manifest' => $domainId.'_tool_permission_manifest',
                'credential_vault_binding' => 'vault_reference_only_no_secret_material_in_packets',
                'operator_review_queue' => $domainId.'_agent_workforce_operator_review',
                'external_effect_worker_enabled' => false,
            ],
            'workforce_observability' => [
                'required_metrics' => [
                    'crew_coverage',
                    'skill_coverage',
                    'connector_coverage',
                    'subagent_coverage',
                    'tool_permission_manifest_coverage',
                    'audit_log_coverage',
                    'session_resume_success_rate',
                    'human_interrupt_rate_for_sensitive_tools',
                    'operator_acceptance_rate',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_agent_workforce_board',
                'alert_on' => ['missing_crew', 'missing_subagent', 'missing_permission_manifest', 'missing_audit_log', 'sensitive_tool_without_interrupt', 'external_effect_requested'],
            ],
            'workforce_hash' => hash('sha256', $domainId.'|enterprise_domain_agent_workforce|'.implode('|', $flowIds).'|'.implode('|', $agentRoles)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function premiumEnterpriseAgentReferenceModel(string $domainId, array $blueprint): array
    {
        $templates = $this->support->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sourceIds = array_column($this->support->domainSolutionSourceCatalog($domainId), 'source_id');

        return [
            'schema' => 'atlas.ai.company.premium_enterprise_agent_reference_model.v1',
            'company_id' => $domainId,
            'model_policy' => [
                'target_tier' => 'ultra_premium_enterprise_company',
                'calendar_wait_blocker_enabled' => false,
                'calendar_history_replaced_by' => 'fixture_shadow_connector_probe_replay_and_current_operating_packet_evidence',
                'external_side_effects_default' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_or_security_action' => true,
                'completion_claim_requires' => [
                    'all_flow_templates_mapped',
                    'all_connectors_have_read_only_probe_plan',
                    'all_templates_have_replay_harness',
                    'current_operating_packet_green',
                    'zero_policy_findings',
                ],
            ],
            'reference_source_basis' => $this->support->premiumReferenceSourceBasis($domainId),
            'managed_agent_templates' => $templates,
            'enterprise_agentic_architecture_basis' => [
                'schema' => 'atlas.ai.company.enterprise_agentic_architecture_basis.v1',
                'agent_runtime_patterns' => [
                    'openai_agents_sdk' => 'handoffs_guardrails_tools_sessions_and_full_trace_export',
                    'crewai_flows' => 'stateful_flow_orchestration_with_crews_persistence_and_resume',
                    'microsoft_agent_framework' => 'durable_workflows_human_in_loop_parallel_branching_and_observability',
                    'model_context_protocol' => 'standard_connector_protocol_for_secure_tool_and_data_access',
                    'temporal_workflows' => 'replayable_business_process_state_retries_and_idempotency',
                    'opentelemetry' => 'trace_metric_log_export_for_agent_runtime_audit',
                ],
                'required_runtime_properties' => ['typed_inputs_outputs', 'tool_permission_scope', 'handoff_contracts', 'guardrails', 'durable_state', 'human_interrupts', 'trace_export', 'replay_dataset', 'policy_receipts'],
                'architecture_hash' => hash('sha256', $domainId.'|enterprise_agentic_architecture_basis|'.implode('|', $templateIds)),
            ],
            'template_runtime_contracts' => array_values(array_map(
                static fn (array $template): array => [
                    'schema' => 'atlas.ai.company.premium_template_runtime_contract.v1',
                    'template_id' => (string) $template['template_id'],
                    'required_capabilities' => ['domain_context_loading', 'tool_use_policy', 'handoff_emit_and_accept', 'guardrail_check', 'trace_export', 'critic_review', 'receipt_export'],
                    'required_runtime_events' => ['template_selected', 'context_loaded', 'tool_scope_checked', 'handoff_emitted', 'guardrail_evaluated', 'artifact_reviewed', 'receipt_exported'],
                    'forbidden_without_operator' => ['external_write', 'spend', 'trade', 'publish', 'deploy', 'delete', 'offensive_security', 'secret_export'],
                    'contract_hash' => hash('sha256', 'premium_template_runtime_contract|'.$template['template_id']),
                ],
                $templates,
            )),
            'flow_template_map' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_template_id' => (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator'),
                    'supporting_template_ids' => array_values(array_slice($templateIds, 0, min(3, count($templateIds)))),
                    'required_workbench' => $flowId.'.premium_workbench.v1',
                    'required_artifacts' => [(string) $spec[2], 'source_lineage', 'tool_receipts', 'critic_review', 'operator_checkpoint'],
                    'external_side_effects' => false,
                    'map_hash' => hash('sha256', 'premium_flow_template_map|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_managed_agent_workflows' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_managed_agent_workflow.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_template_id' => (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator'),
                    'workflow_nodes' => ['intake', 'classify', 'retrieve', 'plan', 'tool_scope_check', 'execute_internal_or_read_only', 'critic_review', 'policy_gate', 'operator_checkpoint', 'artifact_handoff'],
                    'handoff_contracts' => ['owner_to_researcher', 'researcher_to_builder', 'builder_to_critic', 'critic_to_policy_gate', 'policy_gate_to_operator'],
                    'guardrails' => ['prompt_injection_check', 'pii_and_secret_boundary', 'regulated_claim_check', 'external_side_effect_block', 'budget_and_rate_limit_check'],
                    'durable_state' => ['state_hash_required' => true, 'resume_token_required' => true, 'idempotency_key_required' => true],
                    'trace_export_required' => true,
                    'workflow_hash' => hash('sha256', 'flow_managed_agent_workflow|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'data_and_tool_workbenches' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'workbench_id' => $connector.'.premium_workbench.v1',
                    'access_mode' => 'read_only_or_internal_fixture_until_operator_mandate',
                    'required_capabilities' => ['schema_snapshot', 'sample_fixture', 'lineage_capture', 'tool_receipt_export', 'permission_scope_report'],
                    'blocked_capabilities_without_operator' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'offensive_operation', 'secret_export'],
                    'probe_required_before_shadow' => true,
                    'workbench_hash' => hash('sha256', 'premium_workbench|'.$connector),
                ],
                $connectors,
            )),
            'connector_mcp_server_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_mcp_server_plan.v1',
                    'connector_id' => $connector,
                    'server_mode' => 'reference_or_internal_adapter_until_security_review',
                    'required_tools' => ['schema_snapshot', 'read_sample', 'search_or_query', 'export_receipt', 'permission_report'],
                    'required_security_reviews' => ['auth_scope', 'rate_limit', 'data_boundary', 'audit_log', 'rollback_or_disable_plan'],
                    'write_tools_enabled' => false,
                    'plan_hash' => hash('sha256', 'connector_mcp_server_plan|'.$connector),
                ],
                $connectors,
            )),
            'domain_source_alignment' => array_values(array_map(
                static fn (string $sourceId): array => [
                    'source_id' => $sourceId,
                    'alignment_use' => 'domain_specific_reference_or_connector_candidate',
                    'adoption_state' => 'reference_ready_probe_required_before_runtime',
                    'review_required' => ['terms', 'security', 'data_boundary', 'rate_limits', 'fallback_plan'],
                    'alignment_hash' => hash('sha256', 'premium_source_alignment|'.$sourceId),
                ],
                $sourceIds,
            )),
            'replay_and_audit_harness' => [
                'schema' => 'atlas.ai.company.premium_replay_audit_harness.v1',
                'benchmarks_per_flow' => array_values(array_map(
                    static fn (string $flowId): array => [
                        'flow_id' => $flowId,
                        'dataset_contract' => $flowId.'.offline_cases.v1',
                        'minimum_case_count_before_shadow' => 25,
                        'required_scores' => ['task_success', 'evidence_faithfulness', 'decision_determinism', 'trace_completeness', 'policy_boundary', 'handoff_quality', 'guardrail_precision'],
                        'replay_required_before_promotion' => true,
                        'benchmark_hash' => hash('sha256', 'premium_replay_benchmark|'.$flowId),
                    ],
                    $flowIds,
                )),
                'audit_artifacts' => ['input_hash', 'tool_call_trace', 'source_refs', 'output_hash', 'critic_score', 'decision_receipt_hash'],
                'determinism_and_faithfulness_measured_separately' => true,
                'telemetry_first_governance' => true,
                'harness_hash' => hash('sha256', $domainId.'|premium_replay_audit_harness|'.implode('|', $flowIds)),
            ],
            'accelerated_activation_contract' => [
                'buildout_wait_days_required' => 0,
                'why_no_calendar_wait' => 'maturity_is_proven_by_green_evidence_not_time_elapsed',
                'activation_sequence' => [
                    'contract_packet_green',
                    'fixture_suite_green',
                    'connector_read_only_probe_green',
                    'runbook_drill_green',
                    'shadow_readiness_green',
                    'operator_mandate_before_external_effect',
                ],
                'promotion_blockers' => ['missing_current_evidence', 'policy_finding', 'connector_probe_missing', 'runbook_drill_missing', 'operator_mandate_missing_for_external_action'],
            ],
            'premium_model_hash' => hash('sha256', $domainId.'|premium_enterprise_agent_reference_model|'.implode('|', $templateIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseExternalResearchAdoptionStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->externalResearchSourceBasis($domainId);
        $sourceIds = array_column($sources, 'source_id');
        $repositoryCatalog = $this->externalResearchRepositoryCatalog($domainId);
        $templates = $this->support->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_external_research_adoption_stack.v1',
            'company_id' => $domainId,
            'research_policy' => [
                'objective' => 'turn_external_agent_framework_financial_services_and_domain_tooling_research_into_local_enterprise_contracts',
                'calendar_wait_blocker_enabled' => false,
                'external_research_is_architecture_input_only' => true,
                'runtime_ingestion_without_source_review_allowed' => false,
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => false,
                'external_side_effects_default' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_basis' => $sources,
            'repository_and_framework_catalog' => $repositoryCatalog,
            'domain_agent_operating_blueprint' => [
                'pattern' => 'skills_connectors_subagents_receipts_and_policy_gates_per_domain',
                'finance_inspiration' => $domainId === 'finance'
                    ? 'claude_financial_services_style_pitch_model_market_research_valuation_accounting_audit_and_kyc_agents'
                    : 'claude_financial_services_pattern_generalized_to_'.$domainId,
                'required_components' => ['skills', 'connectors', 'subagents', 'tool_receipts', 'source_lineage', 'critic_review', 'operator_checkpoint'],
                'component_hash' => hash('sha256', $domainId.'|external_research_agent_operating_blueprint|'.implode('|', $sourceIds)),
            ],
            'per_flow_adoption_matrix' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'source_refs' => array_values(array_slice($sourceIds, 0, min(8, count($sourceIds)))),
                    'repository_refs' => array_values(array_slice(
                        array_column($repositoryCatalog['official_framework_repositories'], 'repository_url'),
                        0,
                        5,
                    )),
                    'domain_repository_refs' => array_values(array_column($repositoryCatalog['domain_repository_candidates'], 'repository_or_doc_url')),
                    'agent_template_refs' => array_values(array_slice($templateIds, $index % max(1, count($templateIds)), min(3, count($templateIds)))),
                    'connector_candidates' => array_values((array) $spec[1]),
                    'skills' => ['source_review', 'framework_selection', 'domain_tool_mapping', 'fixture_replay_design', 'risk_and_policy_review'],
                    'subagent_roles' => ['domain_methodology_reviewer', 'tool_security_reviewer', 'quality_critic_agent'],
                    'adoption_gates' => ['source_review_green', 'license_review_green', 'security_review_green', 'local_fixture_eval_green', 'operator_acceptance_green'],
                    'blocked_until_gate_green' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_security'],
                    'external_side_effects_enabled' => false,
                    'matrix_hash' => hash('sha256', $domainId.'|external_research_adoption|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'source_to_company_capability_map' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'source_id' => (string) $source['source_id'],
                    'capability' => (string) ($source['capability'] ?? $source['adopted_pattern'] ?? $source['pattern'] ?? 'enterprise_agentic_capability'),
                    'local_contract_target' => 'company_flow_runtime_contract_'.$index,
                    'review_artifacts_required' => ['source_summary', 'allowed_use_review', 'risk_review', 'local_test_result', 'operator_decision'],
                    'adoption_state' => 'contract_candidate_until_local_evidence_green',
                    'external_side_effects_enabled' => false,
                    'capability_hash' => hash('sha256', 'source_capability_map|'.(string) $source['source_id']),
                ],
                $sources,
                array_keys($sources),
            )),
            'connector_and_data_provider_backlog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'activation_model' => 'read_only_probe_fixture_mock_then_operator_mandate_for_any_mutation',
                    'required_artifacts' => ['tool_schema_snapshot', 'permission_scope_report', 'sample_fixture', 'receipt_hash', 'fallback_plan'],
                    'candidate_adapter_forms' => ['mcp_server', 'openapi_adapter', 'read_only_export_import', 'local_fixture_adapter'],
                    'external_side_effects_enabled' => false,
                    'backlog_hash' => hash('sha256', 'external_research_connector_backlog|'.$connector),
                ],
                $connectors,
            )),
            'productionization_gates' => [
                'contract_ready' => ['source_basis_reviewed', 'repository_catalog_reviewed', 'flow_adoption_matrix_complete'],
                'fixture_ready' => ['local_fixture_eval_green', 'tool_receipts_present', 'source_lineage_present'],
                'shadow_ready' => ['read_only_connector_probe_green', 'policy_findings_zero', 'rollback_plan_present'],
                'supervised_ready' => ['operator_signed_mandate', 'second_reviewer_for_risky_external_action', 'incident_route_bound'],
                'autonomy_claim_ready' => ['current_operating_packet_green', 'observed_external_results_reviewed', 'zero_unreviewed_policy_exceptions'],
            ],
            'research_observability' => [
                'required_metrics' => ['source_review_coverage', 'repo_review_coverage', 'flow_adoption_matrix_coverage', 'fixture_eval_pass_rate', 'security_review_pass_rate', 'operator_acceptance_rate'],
                'dashboard' => $domainId.'_external_research_adoption_board',
                'alert_on' => ['unreviewed_source_used', 'repo_license_unknown', 'security_review_missing', 'external_tool_used_without_operator_mandate'],
            ],
            'research_adoption_hash' => hash('sha256', $domainId.'|external_research_adoption|'.implode('|', $sourceIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }
}
