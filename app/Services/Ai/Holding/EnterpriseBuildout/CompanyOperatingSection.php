<?php

namespace App\Services\Ai\Holding\EnterpriseBuildout;

/**
 * Method-family section extracted verbatim from AutonomousHoldingEnterpriseBuildoutService (GOD-DEBULK 2026-07-22).
 */
class CompanyOperatingSection
{
    public function __construct(
        private readonly EnterpriseBuildoutSupport $support,
    ) {}

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingBlueprintStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->externalResearchSourceBasis($domainId);
        $sourceIds = array_column($sources, 'source_id');
        $archetypes = $this->enterpriseWorkloadArchetypes($domainId);
        $archetypeIds = array_column($archetypes, 'archetype_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_company_operating_blueprint_stack.v1',
            'company_id' => $domainId,
            'reference_model' => [
                'source_pattern' => 'claude_financial_services_style_skills_connectors_subagents_generalized_to_every_company',
                'source_urls' => [
                    'https://www.anthropic.com/news/claude-for-financial-services',
                    'https://www.anthropic.com/news/finance-agents',
                ],
                'adopted_enterprise_capabilities' => [
                    'task_specific_workload_templates',
                    'least_privilege_connector_permissions',
                    'managed_credential_vault_boundary',
                    'long_running_durable_agent_sessions',
                    'full_decision_tool_artifact_audit_log',
                    'human_in_the_loop_before_external_effects',
                    'source_grounded_artifact_generation',
                    'trace_replay_eval_and_acceptance_packet',
                ],
                'calendar_wait_blocker_enabled' => false,
            ],
            'workload_archetype_catalog' => $archetypes,
            'data_provider_contracts' => $this->enterpriseDataProviderContracts($domainId, $connectors),
            'connector_permission_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.enterprise_connector_permission_profile.v1',
                    'connector_id' => $connector,
                    'default_mode' => 'read_only_probe_or_fixture',
                    'live_scope_requires_operator_mandate' => true,
                    'credential_binding' => 'vault_reference_only_no_secret_material_export',
                    'source_lineage_required' => true,
                    'tool_call_receipt_required' => true,
                    'write_spend_trade_publish_deploy_delete_or_security_action_allowed' => false,
                    'profile_hash' => hash('sha256', 'connector_permission_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_operating_blueprints' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_operating_blueprint.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) ($spec[0] ?? 'company_manager_agent'),
                    'primary_archetype' => (string) ($archetypeIds[$index % max(1, count($archetypeIds))] ?? 'enterprise_operator_workload'),
                    'required_skills' => $this->support->domainWorkloadSkills($domainId, $flowId),
                    'required_connectors' => array_values((array) ($spec[1] ?? [])),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(6, count($sourceIds)))),
                    'subagent_lanes' => [
                        'source_grounding_lane' => $flowId.'.source_grounding_subagent',
                        'domain_methodology_lane' => $flowId.'.'.(string) data_get($this->support->domainWorkloadSubagents($domainId, $flowId), '1.subagent_id', 'domain_reviewer_subagent'),
                        'risk_policy_lane' => $flowId.'.risk_policy_reviewer_subagent',
                        'artifact_quality_lane' => $flowId.'.artifact_quality_reviewer_subagent',
                        'handoff_audit_lane' => $flowId.'.handoff_and_audit_subagent',
                    ],
                    'artifact_assembly_contract' => [
                        'target_artifact' => (string) ($spec[2] ?? 'enterprise_artifact'),
                        'sections_required' => ['source_snapshot', 'methodology', 'analysis_or_plan', 'risk_register', 'decision_packet', 'operator_handoff'],
                        'source_link_required_per_claim' => true,
                        'cross_source_reconciliation_required' => true,
                        'customer_visible_claims_require_operator_acceptance' => true,
                        'contract_hash' => hash('sha256', $domainId.'|'.$flowId.'|artifact_assembly_contract'),
                    ],
                    'runtime_handoff_contract' => [
                        'durable_session_required' => true,
                        'checkpoint_resume_required' => true,
                        'tool_receipts_required' => true,
                        'trace_export_required' => true,
                        'eval_replay_required' => true,
                        'rollback_or_manual_fallback_required' => true,
                        'external_effects_allowed' => false,
                        'handoff_hash' => hash('sha256', $domainId.'|'.$flowId.'|runtime_handoff_contract'),
                    ],
                    'quality_and_eval_gates' => [
                        'domain_correctness_floor' => 0.92,
                        'source_faithfulness_floor' => 0.97,
                        'policy_findings_allowed' => 0,
                        'fixture_replay_cases_required' => 25,
                        'adversarial_cases_required' => 5,
                        'second_reviewer_required_for_external_action' => true,
                    ],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'flow_operating_blueprint_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_blueprint|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'artifact_assembly_lines' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'pipeline' => ['source_snapshot', 'connector_read', 'analysis_workspace', 'artifact_draft', 'critic_review', 'policy_gate', 'acceptance_packet', 'operator_handoff'],
                    'all_claims_source_linked' => true,
                    'receipt_export_required' => true,
                    'customer_delivery_allowed_without_operator_acceptance' => false,
                    'assembly_hash' => hash('sha256', 'artifact_assembly_line|'.$flowId),
                ],
                $flowIds,
            )),
            'control_room_handoffs' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'review_queue' => (string) $blueprint['review_queue'],
                    'required_packet' => ['scope', 'sources', 'artifact_hash', 'tool_receipts', 'risk_register', 'rollback_plan', 'decision_options'],
                    'operator_acceptance_required' => true,
                    'second_reviewer_required_for_risky_external_effect' => true,
                    'auto_delivery_or_external_action_allowed' => false,
                    'handoff_hash' => hash('sha256', 'control_room_handoff|'.$flowId),
                ],
                $flowIds,
            )),
            'operating_blueprint_observability' => [
                'required_metrics' => [
                    'flow_blueprint_coverage',
                    'archetype_coverage',
                    'connector_permission_coverage',
                    'source_lineage_coverage',
                    'artifact_acceptance_rate',
                    'tool_receipt_completeness',
                    'eval_replay_pass_rate',
                    'operator_handoff_latency',
                    'external_effect_block_rate',
                    'policy_finding_zero_rate',
                ],
                'dashboard' => $domainId.'_enterprise_company_operating_blueprint_board',
                'alert_on' => ['missing_flow_blueprint', 'unscoped_connector', 'claim_without_source_link', 'missing_tool_receipt', 'operator_handoff_missing', 'external_effect_requested'],
            ],
            'blueprint_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'blueprint_is_internal_execution_contract' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'operating_blueprint_hash' => hash('sha256', $domainId.'|enterprise_company_operating_blueprint|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $archetypeIds)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function enterpriseWorkloadArchetypes(string $domainId): array
    {
        $map = [
            'finance' => [
                ['pitch_builder', 'build_source_linked_investment_or_customer_pitch', ['filings', 'market_data', 'company_data_room']],
                ['meeting_preparer', 'prepare_ic_board_or_customer_finance_meeting', ['crm_context', 'filings', 'notes']],
                ['earnings_reviewer', 'review_earnings_filings_and_call_materials', ['sec_filings', 'transcripts', 'market_data']],
                ['model_builder', 'build_and_audit_financial_model', ['financial_statements', 'assumptions', 'scenario_data']],
                ['market_researcher', 'research_market_and_comparable_companies', ['market_data', 'news', 'filings']],
                ['valuation_reviewer', 'review_dcf_comps_and_sensitivity', ['model_outputs', 'comps', 'risk_factors']],
                ['general_ledger_reconciler', 'reconcile_ledger_and_billing_artifacts', ['billing_ledger', 'bank_export_fixture', 'invoice_register']],
                ['month_end_closer', 'assemble_month_end_close_packet', ['ledger', 'accruals', 'variance_analysis']],
                ['statement_auditor', 'audit_financial_statement_claims', ['statements', 'source_docs', 'audit_trail']],
                ['kyc_screener', 'screen_counterparty_kyc_aml_risk', ['counterparty_profile', 'sanctions_fixture', 'risk_register']],
            ],
            'marketing' => [
                ['audience_researcher', 'build_source_linked_segment_and_persona_packet', ['analytics', 'crm', 'research']],
                ['campaign_strategist', 'design_campaign_strategy_with_budget_guardrails', ['analytics', 'experiment_registry', 'content_repository']],
                ['creative_brief_builder', 'produce_reviewable_creative_and_copy_brief', ['brand_system', 'content_repository', 'approval_gate']],
                ['attribution_analyst', 'analyze_channel_and_funnel_performance', ['analytics', 'experiment_registry', 'crm']],
                ['lifecycle_operator', 'prepare_lifecycle_journey_and_message_plan', ['crm', 'approval_gate', 'customer_segments']],
                ['brand_compliance_reviewer', 'verify_claims_tone_and_public_evidence', ['content_repository', 'source_registry', 'approval_gate']],
                ['experiment_allocator', 'rank_growth_tests_and_capacity', ['experiment_registry', 'analytics', 'budget_fixture']],
                ['voc_synthesizer', 'synthesize_customer_voice_into_positioning', ['crm', 'support_exports', 'research_handoff']],
            ],
            'cyber' => [
                ['posture_reviewer', 'assemble_defensive_security_posture_report', ['sbom', 'vulnerability_feeds', 'repo_state']],
                ['finding_triager', 'triage_appsec_findings_with_evidence', ['repo_read', 'evidence_chain', 'vulnerability_feeds']],
                ['control_mapper', 'map_obligations_controls_and_evidence', ['evidence_chain', 'policy_catalog', 'asset_inventory']],
                ['detection_reviewer', 'draft_detection_rule_proposal_without_deploying', ['threat_intel', 'logs_fixture', 'detection_catalog']],
                ['remediation_planner', 'prepare_remediation_program_and_ticket_proposals', ['ticketing_proposal_adapter', 'evidence_chain', 'repo_read']],
                ['incident_readiness_lead', 'rehearse_incident_response_without_external_action', ['runbooks', 'alert_fixture', 'evidence_chain']],
                ['attack_surface_reviewer', 'review_attack_surface_delta_defensively', ['repo_read', 'sbom', 'asset_fixture']],
            ],
        ];
        $fallback = [
            ['source_grounded_operator', 'produce_source_grounded_domain_artifact', ['source_registry', 'read_only_connector', 'evidence_ledger']],
            ['workflow_planner', 'design_durable_flow_execution_plan', ['runbook_repository', 'tool_registry', 'policy_gate']],
            ['artifact_reviewer', 'review_artifact_quality_risk_and_policy', ['critic_review', 'quality_scorecard', 'receipt_verifier']],
            ['handoff_coordinator', 'assemble_operator_handoff_packet', ['operator_review_queue', 'receipt_verifier', 'rollback_plan']],
            ['outcome_analyst', 'measure_internal_outcome_and_feedback', ['metrics_read_adapter', 'evidence_ledger', 'learning_loop']],
        ];

        return array_values(array_map(
            static fn (array $item): array => [
                'schema' => 'atlas.ai.company.enterprise_workload_archetype.v1',
                'archetype_id' => (string) $item[0],
                'purpose' => (string) $item[1],
                'data_inputs' => array_values((array) $item[2]),
                'required_controls' => ['source_lineage', 'least_privilege_connector_scope', 'tool_receipts', 'critic_review', 'operator_handoff'],
                'external_effects_allowed' => false,
                'archetype_hash' => hash('sha256', 'enterprise_workload_archetype|'.(string) $item[0]),
            ],
            $map[$domainId] ?? $fallback,
        ));
    }

    /**
     * @param list<string> $connectors
     * @return list<array<string,mixed>>
     */
    public function enterpriseDataProviderContracts(string $domainId, array $connectors): array
    {
        return array_values(array_map(
            static fn (string $connector, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_data_provider_contract.v1',
                'provider_id' => $domainId.'.'.$connector.'.provider_contract.v1',
                'connector_id' => $connector,
                'contract_mode' => 'read_only_or_fixture_until_operator_scope',
                'schema_snapshot_required' => true,
                'sample_fixture_required' => true,
                'source_lineage_required' => true,
                'cross_source_reconciliation_required' => true,
                'rate_limit_and_cost_guardrail_required' => true,
                'credential_secret_material_export_allowed' => false,
                'external_mutation_allowed' => false,
                'contract_hash' => hash('sha256', $domainId.'|enterprise_data_provider_contract|'.$connector.'|'.$index),
            ],
            $connectors,
            array_keys($connectors),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function domainDataModel(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $workProducts = array_values((array) $blueprint['work_products']);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.domain_data_model.v1',
            'company_id' => $domainId,
            'entities' => [
                [
                    'entity' => 'flow_run',
                    'primary_key' => 'flow_run_id',
                    'required_fields' => ['company_id', 'flow_id', 'input_hash', 'output_hash', 'status', 'receipt_hash'],
                ],
                [
                    'entity' => 'work_product',
                    'primary_key' => 'work_product_id',
                    'required_fields' => ['company_id', 'kind', 'artifact_hash', 'quality_status', 'evidence_refs'],
                ],
                [
                    'entity' => 'handoff_packet',
                    'primary_key' => 'handoff_hash',
                    'required_fields' => ['source_company', 'target_company', 'context_hash', 'expected_output', 'acceptance_status'],
                ],
                [
                    'entity' => 'metric_observation',
                    'primary_key' => 'metric_hash',
                    'required_fields' => ['company_id', 'metric_key', 'value', 'observed_at', 'source_receipt_hash'],
                ],
            ],
            'flow_ids' => $flowIds,
            'work_product_kinds' => $workProducts,
            'metric_keys' => $metrics,
            'retention_and_lineage' => [
                'source_refs_required' => true,
                'state_hash_required' => true,
                'lineage_links' => ['input_packet', 'tool_receipts', 'output_artifact', 'review_packet'],
            ],
            'data_model_hash' => hash('sha256', $domainId.'|domain_data_model|'.implode('|', $flowIds).'|'.implode('|', $workProducts)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function businessProcessMap(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $flowId, array $spec): array => [
                'schema' => 'atlas.ai.company.business_process_map.v1',
                'company_id' => $domainId,
                'process_id' => $domainId.'.process.'.$flowId,
                'flow_id' => $flowId,
                'trigger' => $flowId.'.request.received',
                'swimlanes' => [
                    'operator',
                    (string) $spec[0],
                    'independent_reviewer_agent',
                    'portfolio_governor',
                ],
                'states' => [
                    'intake',
                    'context_loaded',
                    'planned',
                    'analysis_complete',
                    'critic_reviewed',
                    'operator_checkpointed',
                    'published_internal',
                    'handoff_ready',
                ],
                'controls' => [
                    'idempotency_key_required',
                    'checkpoint_before_tool_use',
                    'receipt_after_tool_use',
                    'policy_gate_before_publish',
                    'operator_checkpoint_before_external_action',
                ],
                'outputs' => [(string) $spec[2], $flowId.'.review_packet', $flowId.'.handoff_packet'],
                'process_hash' => hash('sha256', $domainId.'|business_process|'.$flowId.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function deliverableQualityContracts(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $workProduct): array => [
                'schema' => 'atlas.ai.company.deliverable_quality_contract.v1',
                'company_id' => $domainId,
                'work_product' => $workProduct,
                'required_sections' => ['objective', 'method', 'evidence', 'assumptions', 'risks', 'decision_or_recommendation', 'next_actions'],
                'acceptance_criteria' => [
                    'source_refs_or_input_refs_present',
                    'risk_section_non_empty',
                    'policy_profile_attached',
                    'quality_gate_status_green_or_blocked',
                    'receipt_hash_attached',
                ],
                'rejection_criteria' => [
                    'unsupported_claim',
                    'missing_evidence',
                    'external_action_without_operator_mandate',
                    'stale_context_without_disclosure',
                ],
                'quality_score_floor' => 0.86,
                'contract_hash' => hash('sha256', $domainId.'|deliverable_quality|'.$workProduct),
            ],
            array_values((array) $blueprint['work_products']),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function goToProductionPack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.go_to_production_pack.v1',
            'company_id' => $domainId,
            'environment_model' => [
                'stages' => ['contract', 'sandbox', 'shadow', 'supervised_production'],
                'current_stage' => 'contract',
                'promotion_requires' => [
                    'green_eval_harness',
                    'integration_probe_green',
                    'operator_signed_side_effect_mandate',
                    'rollback_plan_present',
                ],
                'external_side_effects_default' => false,
            ],
            'observability' => [
                'required_signals' => ['trace', 'tool_receipt', 'cost', 'latency', 'quality_score', 'policy_findings', 'handoff_status'],
                'dashboards' => array_values((array) $blueprint['dashboards']),
                'alert_routes' => [(string) $blueprint['review_queue'], 'portfolio_governor_queue', 'operator_review_queue'],
                'trace_retention' => 'company_memory_scope_or_longer',
            ],
            'slo_sli_catalog' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'availability_sli' => 'routine_run_success_rate',
                    'quality_sli' => 'critic_score_and_policy_findings',
                    'freshness_sli' => 'latest_context_age',
                    'target' => [
                        'routine_success_rate' => 0.95,
                        'minimum_quality_score' => 0.86,
                        'policy_findings_allowed' => 0,
                    ],
                    'slo_hash' => hash('sha256', 'slo|'.$flowId),
                ],
                $flowIds,
            )),
            'incident_response' => [
                'severity_levels' => ['sev4_quality_warning', 'sev3_flow_blocked', 'sev2_policy_violation', 'sev1_external_side_effect_risk'],
                'first_responder' => (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                'escalation_chain' => ['independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'required_artifacts' => ['incident_packet', 'timeline', 'root_cause', 'rollback_or_compensation_plan', 'postmortem_actions'],
            ],
            'capacity_plan' => [
                'named_agents' => array_values((array) $blueprint['agent_roles']),
                'parallel_flow_limit' => min(4, max(2, count($flowIds))),
                'review_wip_limit' => 5,
                'human_checkpoint_capacity_required' => true,
            ],
            'integration_enablement_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'enablement_steps' => [
                        'bind_least_privilege_credentials',
                        'run_read_only_probe',
                        'record_receipt',
                        'attach_policy_profile',
                        'require_operator_mandate_before_write',
                    ],
                    'status' => 'contract_ready',
                    'external_side_effects_enabled' => false,
                    'enablement_hash' => hash('sha256', 'integration_enablement|'.$connector),
                ],
                $connectors,
            )),
            'production_readiness_hash' => hash('sha256', $domainId.'|go_to_production|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function autonomyPromotionLadder(string $domainId): array
    {
        return [
            [
                'stage' => 'stage_1_internal_advisory',
                'allowed' => ['read_context', 'draft_internal_packet', 'open_review_item'],
                'blocked' => ['external_write', 'spend', 'trade', 'publish', 'deploy'],
                'promotion_evidence' => ['enterprise_buildout_ready', 'flow_contracts_green'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_1'),
            ],
            [
                'stage' => 'stage_2_supervised_internal_execution',
                'allowed' => ['execute_internal_flow', 'create_receipted_artifact', 'prepare_handoff_packet'],
                'blocked' => ['ungoverned_external_side_effect'],
                'promotion_evidence' => ['operating_cycle_observed', 'critic_score_green', 'policy_findings_zero'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_2'),
            ],
            [
                'stage' => 'stage_3_limited_external_preparation',
                'allowed' => ['prepare_external_action_packet', 'run_read_only_external_probe'],
                'blocked' => ['external_mutation_without_signed_operator_mandate'],
                'promotion_evidence' => ['integration_contract_ready', 'rollback_or_compensation_plan', 'operator_signed_checkpoint'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_3'),
            ],
            [
                'stage' => 'stage_4_real_external_autonomy_claim',
                'allowed' => ['bounded_external_action_after_signed_mandate'],
                'blocked' => ['unbounded_action', 'missing_audit_receipt', 'policy_exception'],
                'promotion_evidence' => ['current_operational_evidence_green', 'external_integration_proven', 'incident_free_or_reviewed_operation'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_4'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseReferenceArchitecture(string $domainId, array $blueprint): array
    {
        return [
            'domain_id' => $domainId,
            'source_references' => [
                [
                    'id' => 'anthropic_claude_financial_services',
                    'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                    'adopted_for' => ['finance', 'research', 'strategy'],
                    'patterns' => ['unified_data_interface', 'source_linked_verification', 'model_audit_trail', 'compliance_automation'],
                ],
                [
                    'id' => 'microsoft_agent_framework_autogen_successor',
                    'url' => 'https://github.com/microsoft/autogen',
                    'adopted_for' => ['software', 'automation', 'operations'],
                    'patterns' => ['multi_agent_orchestration', 'mcp_interop', 'benchmarks', 'long_term_support_bias'],
                ],
                [
                    'id' => 'crewai_open_source_orchestration',
                    'url' => 'https://crewai.com/open-source',
                    'adopted_for' => ['marketing', 'research', 'automation'],
                    'patterns' => ['planning', 'reasoning', 'tools', 'memory', 'knowledge', 'collaboration'],
                ],
                [
                    'id' => 'openai_agents_sdk',
                    'url' => 'https://openai.github.io/openai-agents-python/agents/',
                    'adopted_for' => ['all'],
                    'patterns' => ['tools', 'guardrails', 'handoffs', 'sessions', 'run_hooks'],
                ],
                [
                    'id' => 'langgraph_durable_execution',
                    'url' => 'https://docs.langchain.com/oss/javascript/langgraph/durable-execution',
                    'adopted_for' => ['cyber', 'operations', 'automation', 'personal_development'],
                    'patterns' => ['durable_execution', 'checkpoint_resume', 'human_in_loop_interrupts'],
                ],
            ],
            'adopted_patterns' => array_values(array_unique(array_merge(
                ['durable_graph_state', 'human_in_loop_checkpoint', 'guardrailed_tool_use', 'source_linked_outputs', 'receipt_hashes'],
                (array) ($blueprint['reference_patterns'] ?? []),
            ))),
            'runtime_contract' => [
                'stateful_runs_required' => true,
                'tool_calls_must_be_receipted' => true,
                'handoffs_must_be_typed' => true,
                'guardrails_required' => true,
                'external_side_effects_default' => false,
            ],
            'architecture_hash' => hash('sha256', $domainId.'|enterprise_reference_architecture|'.implode('|', (array) ($blueprint['reference_patterns'] ?? []))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseOperatingSystem(string $domainId, array $blueprint): array
    {
        $metrics = array_values((array) $blueprint['metrics']);
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'domain_id' => $domainId,
            'schema' => 'atlas.ai.company.enterprise_operating_system.v1',
            'governance_board' => [
                'cadence' => 'weekly_operating_board',
                'standing_agenda' => [
                    'scorecard_review',
                    'risk_register_review',
                    'flow_throughput_review',
                    'quality_gate_exceptions',
                    'next_commitments',
                ],
                'decision_rights' => [
                    'internal_prioritization' => 'company_manager_agent',
                    'cross_domain_handoff' => 'portfolio_governor_advisory',
                    'external_action' => 'operator_approval_required',
                ],
            ],
            'okr_scorecard' => $this->okrScorecard($domainId, $metrics),
            'sla_catalog' => $this->slaCatalog($domainId, $flowIds),
            'risk_register' => $this->riskRegister($domainId),
            'backlog_system' => [
                'intake_queue' => $domainId.'_enterprise_intake_queue',
                'prioritization_method' => 'impact_confidence_effort_with_policy_risk',
                'lanes' => ['intake', 'triage', 'planned', 'in_progress', 'review', 'blocked', 'done'],
                'wip_limits' => [
                    'in_progress' => 3,
                    'review' => 5,
                ],
                'backlog_hash' => hash('sha256', $domainId.'|backlog_system|'.implode('|', $flowIds)),
            ],
            'runbooks' => $this->runbooks($domainId, $flowIds, $workProducts),
            'escalation_policy' => [
                'level_1' => 'company_manager_agent',
                'level_2' => 'independent_reviewer_agent',
                'level_3' => 'portfolio_governor',
                'level_4' => 'operator',
                'escalate_on' => ['policy_block', 'quality_gate_failure', 'external_action_request', 'repeated_flow_failure'],
            ],
            'audit' => [
                'decision_log_required' => true,
                'evidence_ledger_required' => true,
                'receipt_hash_required' => true,
                'retention_policy' => 'company_memory_scope_or_longer',
            ],
            'operating_system_hash' => hash('sha256', $domainId.'|enterprise_operating_system|'.implode('|', $metrics).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    public function okrScorecard(string $domainId, array $metrics): array
    {
        return array_values(array_map(
            static fn (string $metric, int $index): array => [
                'objective' => $domainId.'.objective.'.($index + 1),
                'key_result' => $metric,
                'target' => $index % 2 === 0 ? 'improve' : 'maintain_green',
                'measurement_source' => 'company_operating_packet.observed_metrics',
                'review_cadence' => 'weekly_operating_board',
                'score_hash' => hash('sha256', $domainId.'|okr|'.$metric),
            ],
            $metrics,
            array_keys($metrics),
        ));
    }

    /**
     * @param list<string> $flowIds
     * @return list<array<string,mixed>>
     */
    public function slaCatalog(string $domainId, array $flowIds): array
    {
        return array_values(array_map(
            static fn (string $flowId, int $index): array => [
                'flow_id' => $flowId,
                'response_sla' => $index % 3 === 0 ? 'same_business_day' : 'next_business_day',
                'quality_sla' => 'all_required_gates_green_or_blocked_with_reason',
                'escalation_after' => 'one_failed_review_cycle',
                'sla_hash' => hash('sha256', $domainId.'|sla|'.$flowId),
            ],
            $flowIds,
            array_keys($flowIds),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function riskRegister(string $domainId): array
    {
        return array_map(
            static fn (string $risk): array => [
                'risk_id' => $domainId.'.risk.'.$risk,
                'risk' => $risk,
                'mitigation' => match ($risk) {
                    'policy_violation' => 'fail_closed_policy_gate',
                    'source_quality_failure' => 'source_link_and_evidence_review',
                    'tool_failure' => 'adapter_health_probe_and_manual_fallback',
                    'external_side_effect_request' => 'operator_checkpoint_required',
                    default => 'portfolio_review',
                },
                'owner' => 'company_manager_agent',
                'status' => 'monitored',
                'risk_hash' => hash('sha256', $domainId.'|risk|'.$risk),
            ],
            ['policy_violation', 'source_quality_failure', 'tool_failure', 'external_side_effect_request'],
        );
    }

    /**
     * @param list<string> $flowIds
     * @param list<string> $workProducts
     * @return list<array<string,mixed>>
     */
    public function runbooks(string $domainId, array $flowIds, array $workProducts): array
    {
        return array_values(array_map(
            static fn (string $flowId, int $index): array => [
                'runbook_id' => $domainId.'.runbook.'.$flowId,
                'flow_id' => $flowId,
                'primary_output' => (string) ($workProducts[$index % max(1, count($workProducts))] ?? 'review_packet'),
                'entry_conditions' => ['scoped_request_present', 'policy_profile_loaded', 'evidence_contract_loaded'],
                'exit_conditions' => ['work_product_recorded', 'quality_gates_checked', 'receipt_hash_attached'],
                'fallback' => 'manual_operator_review',
                'runbook_hash' => hash('sha256', $domainId.'|runbook|'.$flowId),
            ],
            $flowIds,
            array_keys($flowIds),
        ));
    }
}
