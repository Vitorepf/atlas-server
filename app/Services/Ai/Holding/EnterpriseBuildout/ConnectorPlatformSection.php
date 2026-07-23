<?php

namespace App\Services\Ai\Holding\EnterpriseBuildout;

/**
 * Method-family section extracted verbatim from AutonomousHoldingEnterpriseBuildoutService (GOD-DEBULK 2026-07-22).
 */
class ConnectorPlatformSection
{
    public function __construct(
        private readonly EnterpriseBuildoutSupport $support,
    ) {}

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function enterpriseCapabilityMatrix(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $flowId, array $spec): array => [
                'schema' => 'atlas.ai.company.enterprise_capability_matrix_row.v1',
                'company_id' => $domainId,
                'flow_id' => $flowId,
                'owner_agent' => (string) $spec[0],
                'capability_stages' => [
                    'sense' => [
                        'connectors' => (array) $spec[1],
                        'evidence_required' => ['source_refs', 'input_state_hash'],
                    ],
                    'reason' => [
                        'mode' => 'domain_specialist_with_manager_review',
                        'artifacts' => ['assumption_log', 'option_set', 'risk_register_delta'],
                    ],
                    'produce' => [
                        'primary_output' => (string) $spec[2],
                        'contract' => $domainId.'.'.$flowId.'.output_contract',
                    ],
                    'verify' => [
                        'critic_agent' => 'independent_reviewer_agent',
                        'minimum_score' => 0.86,
                        'policy_findings_allowed' => 0,
                    ],
                    'handoff' => [
                        'typed_handoff_packet' => true,
                        'target_acceptance_required' => true,
                        'receipt_hash_required' => true,
                    ],
                ],
                'enterprise_readiness' => 'structure_complete',
                'external_side_effects' => false,
                'matrix_hash' => hash('sha256', $domainId.'|capability_matrix|'.$flowId.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function externalIntegrationCatalog(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $connector): array => [
                'schema' => 'atlas.ai.company.external_integration_contract.v1',
                'company_id' => $domainId,
                'connector_id' => $connector,
                'adapter_surface' => str_contains($connector, 'approval') || str_contains($connector, 'gate')
                    ? 'governance_gate'
                    : 'mcp_or_api_adapter',
                'current_mode' => 'contract_ready_read_or_internal_only',
                'production_enablement_requirements' => [
                    'credential_vault_binding',
                    'least_privilege_scope',
                    'sandbox_or_read_only_probe',
                    'receipt_capture',
                    'operator_signed_side_effect_mandate',
                ],
                'blocked_until_requirements_met' => ['write', 'publish', 'spend', 'trade', 'deploy', 'mutate_infrastructure'],
                'integration_hash' => hash('sha256', $domainId.'|external_integration|'.$connector),
            ],
            $this->support->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseConnectorCertificationStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_connector_certification_stack.v1',
            'company_id' => $domainId,
            'certification_policy' => [
                'mode' => 'contract_probe_certified_before_shadow_or_supervised_use',
                'contract_standard' => 'openapi_or_mcp_tool_schema_with_consumer_driven_contract_tests',
                'write_or_paid_mode_allowed_by_default' => false,
                'production_promotion_without_green_probe_allowed' => false,
                'operator_approval_required_for_write_publish_spend_trade_delete_or_secret_scope_expansion' => true,
            ],
            'source_catalog' => $this->connectorCertificationSourceCatalog(),
            'adapter_contract_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_contract_id' => $connector.'.adapter_contract.v1',
                    'supported_contract_forms' => ['openapi', 'mcp_tool_schema', 'manual_import_schema', 'internal_read_model_schema'],
                    'required_contract_fields' => ['operation_id', 'input_schema', 'output_schema', 'auth_scope', 'rate_limit_policy', 'error_model'],
                    'schema_validation_required' => true,
                    'contract_hash' => hash('sha256', 'connector_adapter_contract|'.$connector),
                ],
                $connectors,
            )),
            'auth_and_secret_boundary' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'credential_binding' => 'vault_reference_only',
                    'minimum_scope' => 'read_or_internal_probe',
                    'token_policy' => ['short_lived_if_supported', 'rotation_record_required', 'least_privilege', 'revocation_path_documented'],
                    'blocked_scope_expansions_without_operator' => ['write', 'publish', 'spend', 'trade', 'delete', 'admin', 'secret_export'],
                    'secret_material_in_packet_allowed' => false,
                    'auth_hash' => hash('sha256', 'connector_auth_secret_boundary|'.$connector),
                ],
                $connectors,
            )),
            'sandbox_probe_matrix' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'probe_id' => $connector.'.sandbox_probe.v1',
                    'probe_modes' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
                    'success_criteria' => ['contract_resolves', 'auth_scope_matches', 'no_external_mutation', 'latency_within_budget', 'receipt_hash_emitted'],
                    'failure_action' => 'block_connector_and_open_integration_review',
                    'probe_hash' => hash('sha256', 'connector_sandbox_probe|'.$connector),
                ],
                $connectors,
            )),
            'consumer_provider_contract_tests' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'test_suite' => $connector.'.consumer_provider_contract_tests.v1',
                    'consumer_assumptions' => ['required_fields_present', 'stable_error_shape', 'pagination_or_batching_disclosed', 'idempotency_semantics_documented'],
                    'provider_verification' => ['response_matches_schema', 'status_code_contract', 'rate_limit_contract', 'permission_denial_contract'],
                    'deployment_gate' => 'cannot_promote_connector_until_contract_verified',
                    'test_hash' => hash('sha256', 'connector_contract_tests|'.$connector),
                ],
                $connectors,
            )),
            'connector_data_mapping_and_lineage' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'mapping_id' => $connector.'.data_mapping.v1',
                    'canonical_entities' => ['source_record', 'normalized_record', 'tool_receipt', 'metric_observation', 'work_product_reference'],
                    'lineage_required' => ['source_uri_or_record_id', 'retrieved_at_or_version', 'transform_hash', 'consumer_flow_id', 'output_hash'],
                    'redaction_required_before_provider_payload' => true,
                    'mapping_hash' => hash('sha256', 'connector_data_mapping|'.$connector),
                ],
                $connectors,
            )),
            'flow_connector_usage_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'connectors' => array_values((array) $spec[1]),
                    'allowed_modes' => ['read', 'analyze', 'propose'],
                    'blocked_modes' => ['write', 'publish', 'spend', 'trade', 'delete'],
                    'pre_run_requirements' => ['adapter_contract_green', 'auth_boundary_green', 'sandbox_probe_green', 'rate_limit_budget_available'],
                    'usage_hash' => hash('sha256', 'flow_connector_usage|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'replay_fixture_and_mock_server_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'fixture_id' => $connector.'.replay_fixture.v1',
                    'fixture_requirements' => ['golden_response', 'error_response', 'rate_limit_response', 'permission_denied_response', 'stale_data_response'],
                    'mock_or_stub_modes' => ['contract_fixture', 'sandbox_fixture', 'manual_import_fixture'],
                    'required_for_offline_eval' => true,
                    'fixture_hash' => hash('sha256', 'connector_replay_fixture|'.$connector),
                ],
                $connectors,
            )),
            'connector_slo_and_failure_mode_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'slo' => ['availability_probe_success_rate' => 0.95, 'receipt_export_rate' => 1.0, 'schema_match_rate' => 1.0],
                    'failure_modes' => ['auth_expired', 'schema_drift', 'rate_limit', 'permission_denied', 'provider_outage', 'terms_change'],
                    'fallback' => 'degrade_to_manual_import_or_cached_read_model_with_staleness_disclosure',
                    'slo_hash' => hash('sha256', 'connector_slo_failure|'.$connector),
                ],
                $connectors,
            )),
            'certification_promotion_gates' => [
                'contract_ready_requires' => ['adapter_contract_catalogued', 'source_terms_reviewed', 'auth_boundary_defined'],
                'sandbox_requires' => ['sandbox_probe_green', 'fixture_pack_present', 'contract_tests_green'],
                'shadow_requires' => ['flow_usage_matrix_bound', 'replay_eval_green', 'observability_green'],
                'supervised_production_requires' => ['operator_mandate', 'rollback_or_manual_fallback', 'incident_route', 'cost_budget'],
                'external_write_or_paid_mode_requires_operator_approval' => true,
            ],
            'connector_certification_observability' => [
                'required_metrics' => ['adapter_contract_coverage', 'sandbox_probe_success_rate', 'contract_test_pass_rate', 'schema_drift_count', 'auth_scope_exception_count', 'receipt_export_rate'],
                'dashboard' => $domainId.'_connector_certification_board',
                'alert_on' => ['schema_drift', 'auth_scope_exception', 'probe_failure', 'contract_test_failure', 'write_mode_request'],
            ],
            'connector_certification_hash' => hash('sha256', $domainId.'|enterprise_connector_certification|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function connectorCertificationSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'openapi_initiative', 'url' => 'https://www.openapis.org/', 'pattern' => 'portable_vendor_neutral_api_contract_metadata'],
            ['source_id' => 'model_context_protocol_tools', 'url' => 'https://modelcontextprotocol.info/specification/2024-11-05/server/tools', 'pattern' => 'tool_schema_for_language_model_invocable_connectors'],
            ['source_id' => 'pact_contract_testing', 'url' => 'https://docs.pact.io/', 'pattern' => 'consumer_provider_contract_tests_for_http_and_message_integrations'],
            ['source_id' => 'postman_api_contract_testing', 'url' => 'https://www.postman.com/postman/postman-intergalactic/documentation/o4masc1/postman-api-contract-testing', 'pattern' => 'schema_validation_payload_assertions_and_repeatable_contract_tests'],
            ['source_id' => 'anthropic_financial_services_connectors', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'domain_data_connectors_with_source_verification_and_audit_trails'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'security_review', 'sandbox_probe_receipt', 'contract_test_result'],
                'source_hash' => hash('sha256', 'connector_certification_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseProductionConnectorPreflightStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_production_connector_preflight_stack.v1',
            'company_id' => $domainId,
            'preflight_policy' => [
                'mode' => 'production_connector_cutover_preflight_without_auto_execution',
                'calendar_wait_blocker_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'real_credential_material_in_packet_allowed' => false,
                'external_side_effects_default' => false,
                'manual_execution_handoff_only_after_signed_mandate' => true,
            ],
            'connector_preflight_contracts' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_production_preflight_contract.v1',
                    'connector_id' => $connector,
                    'credential_vault_binding' => [
                        'binding_mode' => 'vault_reference_required_no_secret_material',
                        'attestation_required' => true,
                        'rotation_policy_required' => true,
                        'revocation_path_required' => true,
                        'credential_material_in_packet_allowed' => false,
                    ],
                    'scope_contract' => [
                        'minimum_scope' => 'read_only_or_fixture',
                        'production_scope_requires_operator_and_second_reviewer' => true,
                        'blocked_scope_without_signed_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                    ],
                    'live_data_readiness' => [
                        'schema_snapshot_required' => true,
                        'sample_record_fixture_required' => true,
                        'freshness_slo_required' => true,
                        'source_lineage_required' => true,
                        'live_mutation_allowed' => false,
                    ],
                    'non_production_dress_rehearsal' => [
                        'required' => true,
                        'must_emit' => ['probe_receipt_hash', 'latency_ms', 'schema_match', 'permission_scope_match', 'no_external_mutation_attestation'],
                        'promotion_requires_green_rehearsal' => true,
                    ],
                    'cost_and_rate_limit_envelope' => [
                        'budget_cap_required_for_paid_api' => true,
                        'rate_limit_policy_required' => true,
                        'burst_behavior_required' => true,
                        'spend_without_cap_allowed' => false,
                    ],
                    'rollback_and_fallback' => [
                        'manual_fallback_required' => true,
                        'disable_switch_required' => true,
                        'last_good_fixture_required' => true,
                        'rollback_drill_required_before_external_mutation' => true,
                    ],
                    'preflight_hash' => hash('sha256', 'production_connector_preflight|'.$connector),
                ],
                $connectors,
            )),
            'flow_connector_cutover_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'required_cutover_evidence' => [
                        'connector_certification_green',
                        'production_preflight_contract_green',
                        'vault_binding_attested',
                        'read_only_or_sandbox_probe_green',
                        'operator_signed_scope',
                        'rollback_drill_green',
                    ],
                    'manual_handoff_packet_required' => true,
                    'auto_execute_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'cutover_hash' => hash('sha256', 'flow_connector_cutover|'.$flowId.'|'.implode('|', (array) $spec[1])),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'production_readiness_evidence_register' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'required_evidence' => [
                        'adapter_contract_hash',
                        'auth_boundary_hash',
                        'sandbox_probe_receipt_hash',
                        'consumer_provider_contract_hash',
                        'lineage_attestation_hash',
                        'slo_failure_mode_hash',
                        'vault_scope_attestation_hash',
                        'operator_mandate_hash',
                    ],
                    'current_state' => 'preflight_contract_ready_external_execution_blocked',
                    'missing_before_real_execution' => ['real_vault_binding', 'signed_production_scope', 'manual_execution_owner'],
                    'external_side_effects_enabled' => false,
                    'evidence_register_hash' => hash('sha256', 'production_readiness_evidence|'.$connector),
                ],
                $connectors,
            )),
            'cutover_observability' => [
                'required_metrics' => ['preflight_contract_coverage', 'vault_binding_attestation_rate', 'dress_rehearsal_pass_rate', 'rollback_drill_pass_rate', 'signed_scope_coverage', 'manual_handoff_readiness'],
                'dashboard' => $domainId.'_production_connector_preflight_board',
                'alert_on' => ['credential_scope_missing', 'signed_scope_missing', 'paid_api_without_budget_cap', 'write_scope_requested_without_mandate', 'rollback_drill_missing'],
            ],
            'production_connector_preflight_hash' => hash('sha256', $domainId.'|production_connector_preflight|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function apiSurface(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $commandBase = $this->support->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.api_surface.v1',
            'company_id' => $domainId,
            'commands' => [
                'readiness' => 'php artisan '.$commandBase.' readiness --json',
                'smoke' => 'php artisan '.$commandBase.' smoke --json',
                'enterprise_analysis' => $domainId === 'software'
                    ? 'php artisan atlas:ai:engineering-company enterprise-analysis --json'
                    : 'php artisan '.$commandBase.' --action=enterprise-analysis --json',
            ],
            'packet_endpoints' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'input_packet' => $flowId.'.request.v1',
                    'output_packet' => $flowId.'.response.v1',
                    'receipt_packet' => $flowId.'.receipt.v1',
                ],
                $flowIds,
            )),
            'state_contract' => [
                'checkpoint_required' => true,
                'resume_token_required' => true,
                'idempotency_key_required' => true,
            ],
            'api_hash' => hash('sha256', $domainId.'|api_surface|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function evaluationHarness(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);

        return [
            'schema' => 'atlas.ai.company.evaluation_harness.v1',
            'company_id' => $domainId,
            'evaluation_modes' => [
                'contract_fixture',
                'source_link_audit',
                'policy_violation_scan',
                'handoff_acceptance_test',
                'budget_and_latency_probe',
                'operator_review_sampling',
            ],
            'required_trace_fields' => [
                'flow_id',
                'agent_role',
                'input_hash',
                'tool_call_receipts',
                'output_hash',
                'critic_score',
                'policy_findings',
                'handoff_acceptance_status',
            ],
            'suite_per_flow' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'suite' => $domainId.'.'.$flowId.'.enterprise_eval',
                    'minimum_score' => 0.86,
                    'failure_action' => 'block_promotion_and_open_review_queue',
                    'suite_hash' => hash('sha256', $domainId.'|eval_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'harness_hash' => hash('sha256', $domainId.'|evaluation_harness|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseToolingResearchStack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $referencePatterns = array_values((array) $blueprint['reference_patterns']);

        return [
            'schema' => 'atlas.ai.company.enterprise_tooling_research_stack.v1',
            'company_id' => $domainId,
            'source_catalog' => $this->support->agentFrameworkSourceCatalog($domainId),
            'domain_adoption_strategy' => [
                'reference_patterns' => $referencePatterns,
                'default_orchestration' => 'typed_flow_with_specialist_crews_handoffs_guardrails_tracing_and_durable_resume',
                'tool_selection_method' => 'evidence_weighted_fit_security_maturity_and_operational_cost',
                'production_rule' => 'adopt_as_internal_or_read_only_until_eval_integration_probe_and_operator_mandate_are_green',
                'reject_when' => [
                    'no_license_or_security_posture',
                    'no_state_or_trace_model_for_long_running_work',
                    'no_human_review_checkpoint_for_external_side_effects',
                    'no_receipt_or_evidence_export',
                ],
            ],
            'per_flow_tooling_benchmark' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'candidate_patterns' => $this->candidateToolingPatterns($domainId),
                    'minimum_benchmark_dimensions' => [
                        'task_success',
                        'evidence_quality',
                        'handoff_reliability',
                        'trace_completeness',
                        'durable_resume',
                        'security_boundary',
                        'cost_latency',
                    ],
                    'required_artifacts' => [
                        'baseline_prompt_or_fixture',
                        'agent_trace',
                        'tool_receipts',
                        'critic_review',
                        'operator_checkpoint',
                        'benchmark_hash',
                    ],
                    'promotion_gate' => 'flow_cannot_enter_shadow_mode_until_best_fit_pattern_has_green_eval_and_integration_probe',
                    'benchmark_hash' => hash('sha256', $domainId.'|tooling_benchmark|'.$flowId),
                ],
                $flowIds,
            )),
            'connector_integration_backlog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_options' => ['mcp_server', 'api_adapter', 'read_model_projection', 'manual_import_bridge'],
                    'enablement_sequence' => [
                        'document_contract',
                        'bind_least_privilege_secret',
                        'run_read_only_probe',
                        'capture_receipt',
                        'add_fixture_to_evaluation_harness',
                        'request_operator_write_mandate_if_needed',
                    ],
                    'current_mode' => 'research_ready_contract_ready_read_or_internal_only',
                    'external_side_effects_enabled' => false,
                    'backlog_hash' => hash('sha256', 'tooling_integration_backlog|'.$connector),
                ],
                $connectors,
            )),
            'agent_repository_watchlist' => [
                'frameworks' => ['openai_agents_sdk', 'crewai_flows_crews', 'microsoft_agent_framework', 'microsoft_agent_framework_autogen_lineage', 'langgraph_durable_execution', 'model_context_protocol_servers', 'temporal_durable_workflows', 'opentelemetry_collector_tracing'],
                'domain_specific_watch' => $domainId === 'finance'
                    ? ['financial_data_mcp_connectors', 'excel_financial_modeling', 'market_data_research_sources', 'compliance_automation_agents']
                    : ['domain_mcp_connectors', 'workflow_specific_agent_templates', 'evaluation_datasets', 'observability_adapters'],
                'review_cadence' => 'weekly_tooling_research_review',
                'watchlist_hash' => hash('sha256', $domainId.'|agent_repository_watchlist|'.implode('|', $referencePatterns)),
            ],
            'enterprise_adoption_gates' => [
                'license_and_security_review',
                'sandbox_probe_green',
                'evaluation_harness_green',
                'receipts_exported',
                'operator_checkpoint_supported',
                'rollback_or_manual_fallback_documented',
            ],
            'research_stack_hash' => hash('sha256', $domainId.'|enterprise_tooling_research|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function candidateToolingPatterns(string $domainId): array
    {
        $patterns = [
            [
                'pattern_id' => 'typed_agents_sdk_runtime',
                'best_for' => 'code_owned_orchestration_with_tools_handoffs_guardrails_and_tracing',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'flow_plus_crew_runtime',
                'best_for' => 'structured_process_with_specialist_agent_teams',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'durable_graph_runtime',
                'best_for' => 'long_running_checkpointed_flows_with_resume',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'enterprise_agent_framework_runtime',
                'best_for' => 'multi_provider_enterprise_orchestration_with_mcp_a2a_and_long_term_support',
                'fit' => 'medium',
            ],
        ];

        if ($domainId === 'finance') {
            $patterns[] = [
                'pattern_id' => 'financial_services_connector_runtime',
                'best_for' => 'financial_research_modeling_due_diligence_risk_and_compliance_with_trusted_data_connectors',
                'fit' => 'high',
            ];
        }

        return array_values(array_map(
            static fn (array $pattern): array => [
                ...$pattern,
                'must_support' => ['source_refs', 'tool_receipts', 'policy_gate', 'human_checkpoint', 'evaluation_export'],
                'external_side_effects_default' => false,
                'pattern_hash' => hash('sha256', 'candidate_tooling_pattern|'.$pattern['pattern_id']),
            ],
            $patterns,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    public function enterpriseDomainDataConnectorOperatingStack(string $domainId, array $blueprint, array $workProducts, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->domainCompanyExecutionSuiteSources($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->support->domainCompanyExecutionSuiteProfile($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_data_connector_operating_stack.v1',
            'company_id' => $domainId,
            'data_connector_policy' => [
                'mode' => 'governed_domain_data_room_and_connector_operations',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'source_lineage_permission_profile_fixture_eval_connector_probe_and_operator_acceptance',
                'read_only_probe_required_before_live_use' => true,
                'write_tools_enabled' => false,
                'external_data_mutation_allowed' => false,
                'secret_export_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_data_room_catalog' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'schema' => 'atlas.ai.company.source_data_room_entry.v1',
                    'source_id' => (string) ($source['source_id'] ?? 'unknown_source'),
                    'source_url' => (string) ($source['url'] ?? ''),
                    'source_use' => (string) ($source['use'] ?? 'domain_reference'),
                    'data_room_id' => 'data_room.source.'.(string) ($source['source_id'] ?? 'unknown_source'),
                    'minimum_evidence' => ['terms_review', 'security_review', 'schema_snapshot', 'freshness_policy', 'lineage_capture', 'fallback_source'],
                    'default_access_mode' => $index === 0 ? 'canonical_reference_read' : 'reference_or_fixture_read',
                    'external_side_effects_enabled' => false,
                    'entry_hash' => hash('sha256', 'source_data_room_entry|'.$domainId.'|'.(string) ($source['source_id'] ?? 'unknown_source')),
                ],
                $sources,
                array_keys($sources),
            )),
            'domain_data_products' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_data_product_contract.v1',
                    'data_product_id' => $workProduct.'.data_product.v1',
                    'work_product_id' => $workProduct,
                    'owning_lane' => 'domain_data_room',
                    'source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'contract_sections' => ['schema', 'source_lineage', 'freshness', 'quality_rules', 'privacy_boundary', 'consumer_flows', 'receipt_requirements'],
                    'quality_rules' => ['schema_valid', 'source_linked', 'freshness_disclosed', 'duplicates_handled', 'redaction_checked', 'operator_readable'],
                    'mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'domain_data_product_contract|'.$domainId.'|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'connector_permission_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_permission_profile.v1',
                    'connector_id' => $connector,
                    'profile_id' => $connector.'.permission_profile.v1',
                    'allowed_modes' => ['fixture', 'manual_import', 'schema_snapshot', 'read_only_probe', 'receipt_export'],
                    'blocked_modes_without_operator_mandate' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'required_controls' => ['least_privilege_scope', 'credential_vault_reference', 'permission_report', 'rate_limit_plan', 'audit_log_export', 'disable_plan'],
                    'write_tools_enabled' => false,
                    'permission_hash' => hash('sha256', 'connector_permission_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_data_connector_contracts' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_data_connector_contract.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'work_product_id' => (string) $spec[2],
                    'required_connectors' => array_values((array) $spec[1]),
                    'required_source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'required_data_products' => [(string) $spec[2].'.data_product.v1', 'source_lineage_bundle', 'tool_receipt_bundle'],
                    'data_contract_gates' => ['schema_snapshot_present', 'permission_profile_present', 'source_lineage_complete', 'freshness_policy_applied', 'privacy_boundary_passed', 'operator_handoff_ready'],
                    'minimum_fixture_cases' => 25,
                    'live_connector_mode' => 'read_only_probe_until_operator_mandate',
                    'external_mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_data_connector_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_fixture_eval_suites' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.connector_fixture_eval_suite.v1',
                    'flow_id' => $flowId,
                    'case_mix' => ['happy_path', 'missing_source', 'stale_source', 'conflicting_source', 'permission_denied', 'connector_timeout', 'private_data_boundary', 'external_mutation_request'],
                    'minimum_case_count' => 25,
                    'required_scores' => ['schema_validity', 'lineage_completeness', 'freshness_disclosure', 'permission_boundary', 'fallback_quality', 'receipt_completeness'],
                    'promotion_requires_green_eval' => true,
                    'eval_hash' => hash('sha256', 'connector_fixture_eval_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'domain_data_room_operating_model' => [
                'schema' => 'atlas.ai.company.domain_data_room_operating_model.v1',
                'operating_roles' => (array) $profile['roles'],
                'workbenches' => (array) $profile['workbenches'],
                'decision_cadences' => (array) $profile['cadences'],
                'required_controls' => ['source_lineage', 'permission_profiles', 'fixture_eval', 'privacy_boundary', 'receipt_export', 'fallback_source', 'operator_acceptance'],
                'blocked_external_actions' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                'operating_model_hash' => hash('sha256', $domainId.'|domain_data_room_operating_model'),
            ],
            'data_connector_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['source_lineage_coverage', 'permission_profile_coverage', 'schema_snapshot_coverage', 'fixture_eval_pass_rate', 'read_only_probe_pass_rate', 'freshness_disclosure_rate', 'external_mutation_block_rate']
                ))),
                'dashboard' => $domainId.'_domain_data_connector_operating_board',
                'alert_on' => ['missing_permission_profile', 'schema_snapshot_missing', 'lineage_gap', 'fixture_eval_failed', 'external_mutation_requested', 'secret_export_requested'],
            ],
            'data_connector_stack_hash' => hash('sha256', $domainId.'|domain_data_connector_operating|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $sourceIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseIntegrationActivationPlan(string $domainId, array $blueprint): array
    {
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $flowIds = array_keys((array) $blueprint['flow_specs']);

        return [
            'schema' => 'atlas.ai.company.enterprise_integration_activation_plan.v1',
            'company_id' => $domainId,
            'activation_policy' => [
                'buildout_blocked_by_observed_history_window' => false,
                'external_write_blocked_until_operator_mandate' => true,
                'default_mode' => 'contract_ready_read_only_probe',
                'promotion_sequence' => ['contract', 'sandbox_probe', 'shadow_mode', 'supervised_production'],
                'kill_switch_required' => true,
            ],
            'source_activation_tracks' => array_values(array_map(
                static fn (array $source): array => [
                    'source_id' => (string) $source['source_id'],
                    'url' => (string) $source['url'],
                    'activation_steps' => [
                        'confirm_terms_and_allowed_use',
                        'define_read_only_adapter_contract',
                        'create_fixture_payload',
                        'run_sandbox_or_documentation_probe',
                        'capture_probe_receipt',
                        'attach_to_evaluation_harness',
                    ],
                    'required_evidence' => ['terms_review', 'adapter_contract', 'fixture_hash', 'probe_receipt', 'eval_result'],
                    'current_mode' => 'reference_catalog_ready',
                    'external_side_effects_enabled' => false,
                    'track_hash' => hash('sha256', 'source_activation_track|'.(string) $source['source_id']),
                ],
                $sources,
            )),
            'connector_activation_tracks' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'activation_steps' => [
                        'bind_secret_placeholder_or_internal_adapter',
                        'verify_least_privilege_scope',
                        'execute_read_only_health_check',
                        'record_tool_receipt',
                        'wire_metric_and_alert',
                        'document_manual_fallback',
                    ],
                    'health_check_contract' => [
                        'timeout_seconds' => 10,
                        'must_return_receipt' => true,
                        'must_not_mutate_external_state' => true,
                        'failure_mode' => 'block_flow_and_emit_review_packet',
                    ],
                    'shadow_mode_ready_when' => ['health_check_green', 'fixture_eval_green', 'fallback_documented', 'operator_reviewed'],
                    'external_side_effects_enabled' => false,
                    'track_hash' => hash('sha256', 'connector_activation_track|'.$connector),
                ],
                $connectors,
            )),
            'flow_activation_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'minimum_stage' => 'sandbox_probe',
                    'required_activation_evidence' => [
                        'input_fixture',
                        'runtime_blueprint',
                        'source_track_receipt',
                        'connector_health_receipt',
                        'critic_review',
                        'operator_checkpoint_if_external',
                    ],
                    'shadow_mode_entry_criteria' => ['all_required_connectors_green', 'eval_score_at_or_above_floor', 'policy_findings_zero'],
                    'supervised_production_entry_criteria' => ['operator_signed_mandate', 'rollback_plan_present', 'incident_route_configured'],
                    'matrix_hash' => hash('sha256', 'flow_activation_matrix|'.$flowId),
                ],
                $flowIds,
            )),
            'activation_observability' => [
                'required_signals' => ['activation_stage', 'probe_status', 'receipt_hash', 'policy_status', 'fixture_eval_score', 'fallback_status'],
                'dashboard' => $domainId.'_integration_activation_board',
                'alert_route' => (string) $blueprint['review_queue'],
                'weekly_review_required' => true,
            ],
            'activation_hash' => hash('sha256', $domainId.'|integration_activation|'.implode('|', array_column($sources, 'source_id')).'|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseOperationalDressRehearsalStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.enterprise_operational_dress_rehearsal_stack.v1',
            'company_id' => $domainId,
            'rehearsal_policy' => [
                'mode' => 'staging_and_read_only_live_rehearsal_before_supervised_production',
                'calendar_wait_blocker_enabled' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'production_cutover_allowed_without_signed_acceptance' => false,
                'operator_and_domain_owner_acceptance_required' => true,
                'second_reviewer_required_for_spend_trade_publish_security_or_delete_scope' => true,
            ],
            'flow_rehearsal_runbooks' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_operational_dress_rehearsal_runbook.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'target_artifact' => (string) $spec[2],
                    'staging_sequence' => [
                        'load_latest_company_packet',
                        'resolve_connector_preflight_contracts',
                        'bind_read_only_or_fixture_credentials',
                        'execute_shadow_run_with_live_read_if_available',
                        'compare_against_fixture_baseline',
                        'emit_rehearsal_receipt',
                        'open_operator_acceptance_packet',
                    ],
                    'acceptance_criteria' => [
                        'policy_findings_zero',
                        'source_lineage_complete',
                        'tool_receipts_complete',
                        'artifact_quality_score_green',
                        'rollback_drill_green',
                        'operator_acceptance_recorded',
                    ],
                    'blocked_during_rehearsal' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security'],
                    'runbook_hash' => hash('sha256', 'operational_dress_rehearsal_runbook|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'live_read_probe_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'probe_mode' => 'live_read_only_or_sandbox_fixture',
                    'required_before_supervised_production' => [
                        'vault_reference_attested',
                        'scope_matches_contract',
                        'sample_payload_redacted',
                        'lineage_ref_present',
                        'receipt_hash_emitted',
                        'no_external_mutation_attested',
                    ],
                    'failure_action' => 'fall_back_to_fixture_and_block_cutover',
                    'mutation_allowed' => false,
                    'probe_hash' => hash('sha256', 'operational_live_read_probe|'.$connector),
                ],
                $connectors,
            )),
            'operator_acceptance_packets' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'acceptance_packet_id' => $domainId.'.'.$flowId.'.operator_acceptance.v1',
                    'required_signatures' => ['operator', 'domain_owner'],
                    'second_reviewer_required_when' => ['spend_scope', 'trade_scope', 'publish_scope', 'security_scope', 'delete_scope'],
                    'required_artifacts' => [
                        'rehearsal_receipt_hash',
                        'artifact_hash',
                        'source_lineage_summary',
                        'policy_gate_result',
                        'rollback_drill_receipt',
                        'customer_or_stakeholder_acceptance_note',
                    ],
                    'auto_accept_allowed' => false,
                    'external_execution_enabled_by_packet' => false,
                    'acceptance_hash' => hash('sha256', 'operator_acceptance_packet|'.$domainId.'|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'rollback_drill_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'drill_steps' => [
                        'snapshot_before_rehearsal',
                        'simulate_connector_failure',
                        'degrade_to_fixture_or_manual_import',
                        'emit_compensation_packet_if_external_intent_exists',
                        'verify_dashboard_and_alert_state',
                    ],
                    'success_criteria' => ['no_external_state_changed', 'fallback_artifact_available', 'incident_route_ready', 'operator_interrupt_verified'],
                    'required_before_any_external_mutation' => true,
                    'rollback_hash' => hash('sha256', 'operational_rollback_drill|'.$flowId),
                ],
                $flowIds,
            )),
            'promotion_evidence_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'promotion_stage' => 'supervised_production_candidate_external_blocked_until_signed_scope',
                    'must_have_green' => [
                        'fixture_suite',
                        'shadow_runtime',
                        'connector_preflight',
                        'live_read_probe',
                        'rollback_drill',
                        'operator_acceptance',
                    ],
                    'calendar_wait_days_required' => 0,
                    'external_side_effects_enabled' => false,
                    'promotion_hash' => hash('sha256', 'operational_promotion_evidence|'.$flowId),
                ],
                $flowIds,
            )),
            'dress_rehearsal_observability' => [
                'required_metrics' => [
                    'rehearsal_pass_rate',
                    'live_read_probe_pass_rate',
                    'rollback_drill_pass_rate',
                    'operator_acceptance_coverage',
                    'artifact_quality_green_rate',
                    'policy_findings_zero_rate',
                    'cutover_blocker_count',
                ],
                'dashboard' => $domainId.'_operational_dress_rehearsal_board',
                'alert_on' => ['live_probe_failed', 'rollback_drill_failed', 'operator_acceptance_missing', 'policy_finding_present', 'external_mutation_requested'],
            ],
            'dress_rehearsal_hash' => hash('sha256', $domainId.'|operational_dress_rehearsal|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function connectors(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $connector): array => [
                'id' => $connector,
                'domain_id' => $domainId,
                'kind' => str_contains($connector, 'approval') || str_contains($connector, 'gate')
                    ? 'governance_gate'
                    : 'read_or_internal_adapter',
                'side_effect_profile' => 'read_only_or_governed_internal',
                'external_side_effects' => false,
                'source_links_required' => true,
                'contract_hash' => hash('sha256', $domainId.'|'.$connector),
            ],
            $this->support->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function toolchain(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (array $connector): array => [
                'connector_id' => (string) $connector['id'],
                'domain_id' => $domainId,
                'adapter_kind' => (string) $connector['kind'],
                'permission_model' => (string) $connector['side_effect_profile'],
                'mcp_or_adapter_ready' => true,
                'requires_source_links' => (bool) $connector['source_links_required'],
                'requires_receipt' => true,
                'external_side_effects' => false,
                'tool_contract_hash' => hash('sha256', $domainId.'|toolchain|'.(string) $connector['contract_hash']),
            ],
            $this->connectors($domainId, $blueprint),
        ));
    }
}
