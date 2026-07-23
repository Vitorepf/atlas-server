<?php

namespace App\Services\Ai\Holding\EnterpriseBuildout;

use App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService;

/**
 * Shared helpers used by two or more sections of the enterprise buildout.
 */
class EnterpriseBuildoutSupport
{
    public function __construct(
        private readonly FinanceEnterpriseAnalysisService $finance,
    ) {}

    /**
     * @param array<string,mixed> $blueprint
     * @return list<string>
     */
    public function enterpriseConnectorsForBlueprint(array $blueprint): array
    {
        $connectors = array_values(array_filter((array) ($blueprint['connectors'] ?? []), 'is_string'));

        foreach ((array) ($blueprint['flow_specs'] ?? []) as $spec) {
            foreach ((array) ($spec[1] ?? []) as $connector) {
                if (is_string($connector) && $connector !== '') {
                    $connectors[] = $connector;
                }
            }
        }

        return array_values(array_unique($connectors));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function domainSolutionSourceCatalog(string $domainId): array
    {
        $catalog = match ($domainId) {
            'software' => [
                ['source_id' => 'github_rest_api', 'url' => 'https://docs.github.com/en/rest', 'use' => 'repository_pull_request_issue_and_ci_context'],
                ['source_id' => 'opentelemetry_docs', 'url' => 'https://opentelemetry.io/docs/', 'use' => 'trace_metric_log_instrumentation'],
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'secure_software_verification_controls'],
                ['source_id' => 'openai_agents_sdk', 'url' => 'https://developers.openai.com/api/docs/guides/agents', 'use' => 'agent_tools_handoffs_guardrails_tracing'],
                ['source_id' => 'microsoft_autogen', 'url' => 'https://github.com/microsoft/autogen', 'use' => 'multi_agent_orchestration_benchmark'],
            ],
            'research' => [
                ['source_id' => 'semantic_scholar_api', 'url' => 'https://www.semanticscholar.org/product/api', 'use' => 'paper_citation_author_graph'],
                ['source_id' => 'arxiv_api', 'url' => 'https://info.arxiv.org/help/api/index.html', 'use' => 'preprint_discovery'],
                ['source_id' => 'crossref_api', 'url' => 'https://www.crossref.org/documentation/retrieve-metadata/rest-api/', 'use' => 'doi_metadata_and_references'],
                ['source_id' => 'pubmed_eutilities', 'url' => 'https://www.ncbi.nlm.nih.gov/books/NBK25501/', 'use' => 'biomedical_literature'],
                ['source_id' => 'openalex_api', 'url' => 'https://docs.openalex.org/', 'use' => 'open_scholarly_graph'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_filings'],
                ['source_id' => 'world_bank_api', 'url' => 'https://datahelpdesk.worldbank.org/knowledgebase/topics/125589-developer-information', 'use' => 'macro_market_indicators'],
                ['source_id' => 'fred_api', 'url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'economic_time_series'],
                ['source_id' => 'oecd_data_api', 'url' => 'https://data-explorer.oecd.org/', 'use' => 'country_and_sector_indicators'],
                ['source_id' => 'google_trends', 'url' => 'https://trends.google.com/trends/', 'use' => 'demand_signal_research'],
            ],
            'finance' => [
                ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'financial_services_agent_solution_pattern'],
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'filings_fundamentals_disclosure'],
                ['source_id' => 'fred_api', 'url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'macro_rates_and_economic_series'],
                ['source_id' => 'alpha_vantage_docs', 'url' => 'https://www.alphavantage.co/documentation/', 'use' => 'market_data_sandbox'],
                ['source_id' => 'openbb_docs', 'url' => 'https://docs.openbb.co/', 'use' => 'financial_research_terminal_pattern'],
            ],
            'marketing' => [
                ['source_id' => 'google_ads_api', 'url' => 'https://developers.google.com/google-ads/api/docs/campaigns', 'use' => 'campaign_reporting_and_management'],
                ['source_id' => 'google_analytics_data_api', 'url' => 'https://developers.google.com/analytics/devguides/reporting/data/v1', 'use' => 'web_and_product_analytics'],
                ['source_id' => 'hubspot_crm_api', 'url' => 'https://developers.hubspot.com/docs/api/crm/understanding-the-crm', 'use' => 'crm_lifecycle_context'],
                ['source_id' => 'meta_marketing_api', 'url' => 'https://developers.facebook.com/docs/marketing-apis/', 'use' => 'paid_social_campaign_context'],
                ['source_id' => 'linkedin_marketing_api', 'url' => 'https://learn.microsoft.com/en-us/linkedin/marketing/', 'use' => 'b2b_campaign_context'],
            ],
            'cyber' => [
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'application_security_control_verification'],
                ['source_id' => 'mitre_attack', 'url' => 'https://attack.mitre.org/', 'use' => 'adversary_tactics_techniques'],
                ['source_id' => 'nvd_api', 'url' => 'https://nvd.nist.gov/developers/vulnerabilities', 'use' => 'vulnerability_enrichment'],
                ['source_id' => 'cisa_kev', 'url' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog', 'use' => 'known_exploited_vulnerability_priority'],
                ['source_id' => 'opencti_docs', 'url' => 'https://docs.opencti.io/latest/', 'use' => 'threat_intelligence_graph'],
            ],
            'automation' => [
                ['source_id' => 'model_context_protocol', 'url' => 'https://modelcontextprotocol.io/docs/getting-started/intro', 'use' => 'tool_and_context_integration_standard'],
                ['source_id' => 'playwright_docs', 'url' => 'https://playwright.dev/docs/intro', 'use' => 'browser_automation'],
                ['source_id' => 'n8n_docs', 'url' => 'https://docs.n8n.io/', 'use' => 'workflow_automation_patterns'],
                ['source_id' => 'zapier_platform_docs', 'url' => 'https://platform.zapier.com/docs', 'use' => 'saas_connector_patterns'],
                ['source_id' => 'openapi_spec', 'url' => 'https://spec.openapis.org/oas/latest.html', 'use' => 'api_adapter_contracts'],
            ],
            'personal_development' => [
                ['source_id' => 'xapi_spec', 'url' => 'https://github.com/adlnet/xAPI-Spec', 'use' => 'learning_experience_records'],
                ['source_id' => 'open_badges', 'url' => 'https://www.imsglobal.org/spec/ob/v3p0/', 'use' => 'skill_and_credential_records'],
                ['source_id' => 'caldav_rfc', 'url' => 'https://www.rfc-editor.org/rfc/rfc4791', 'use' => 'calendar_and_schedule_context'],
                ['source_id' => 'apple_healthkit_docs', 'url' => 'https://developer.apple.com/documentation/healthkit', 'use' => 'health_context_with_privacy_review'],
                ['source_id' => 'schema_org', 'url' => 'https://schema.org/', 'use' => 'structured_goal_learning_event_metadata'],
            ],
            default => [
                ['source_id' => 'opentelemetry_docs', 'url' => 'https://opentelemetry.io/docs/', 'use' => 'trace_metric_log_instrumentation'],
                ['source_id' => 'prometheus_docs', 'url' => 'https://prometheus.io/docs/introduction/overview/', 'use' => 'metrics_and_alerting'],
                ['source_id' => 'kubernetes_api', 'url' => 'https://kubernetes.io/docs/reference/kubernetes-api/', 'use' => 'platform_state_and_workload_context'],
                ['source_id' => 'grafana_docs', 'url' => 'https://grafana.com/docs/', 'use' => 'dashboard_and_observability_context'],
                ['source_id' => 'pagerduty_api', 'url' => 'https://developer.pagerduty.com/api-reference/', 'use' => 'incident_response_context'],
            ],
        };

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'mode' => 'source_catalog_reference_until_connector_probe_green',
                'source_links_required' => true,
                'external_side_effects_default' => false,
                'source_hash' => hash('sha256', 'domain_solution_source|'.$source['source_id']),
            ],
            $catalog,
        ));
    }

    public function commandBase(string $domainId): string
    {
        return match ($domainId) {
            'software' => 'atlas:ai:engineering-company',
            'personal_development' => 'atlas:ai:personal-development-domain',
            default => 'atlas:ai:'.str_replace('_', '-', $domainId).'-domain',
        };
    }

    /**
     * @return array<string,list<string>>
     */
    public function domainOperatingDepthProfile(string $domainId): array
    {
        return match ($domainId) {
            'software' => [
                'value_chain' => ['intake', 'architecture', 'implementation', 'test_repair', 'security_review', 'release', 'learning'],
                'skills' => ['ast_reasoning', 'dependency_impact_analysis', 'test_failure_triage', 'patch_review', 'release_risk_assessment'],
                'systems' => ['git_provider', 'ci_system', 'issue_tracker', 'artifact_registry', 'observability_stack'],
                'data_products' => ['repo_graph', 'test_failure_corpus', 'release_evidence_pack', 'security_findings_register', 'dependency_impact_map'],
                'controls' => ['no_unreviewed_destructive_git_operation', 'tests_before_release', 'security_findings_closed_or_waived'],
            ],
            'research' => [
                'value_chain' => ['question_scope', 'source_discovery', 'citation_graph', 'claim_extraction', 'contradiction_review', 'synthesis', 'knowledge_update'],
                'skills' => ['primary_source_retrieval', 'citation_quality_scoring', 'claim_support_mapping', 'contradiction_adjudication', 'evidence_synthesis'],
                'systems' => ['web_search', 'paper_index', 'citation_graph', 'source_registry', 'knowledge_base'],
                'data_products' => ['source_pack', 'claim_table', 'citation_graph', 'contradiction_register', 'synthesis_memo'],
                'controls' => ['primary_sources_required', 'unsupported_claims_blocked', 'source_disagreement_disclosed'],
            ],
            'strategy' => [
                'value_chain' => ['opportunity_scan', 'market_map', 'business_model', 'experiment_design', 'capital_option', 'board_dossier'],
                'skills' => ['market_mapping', 'assumption_modeling', 'scenario_analysis', 'experiment_portfolio_design', 'board_memo_writing'],
                'systems' => ['market_dataset', 'research_handoff', 'finance_model', 'experiment_registry', 'board_decision_log'],
                'data_products' => ['opportunity_map', 'tam_model', 'assumption_ledger', 'experiment_scorecard', 'board_decision_packet'],
                'controls' => ['assumptions_must_be_explicit', 'capital_commitment_operator_only', 'forecast_uncertainty_disclosed'],
            ],
            'finance' => [
                'value_chain' => ['market_research', 'filing_ingestion', 'model_build', 'valuation_review', 'risk_compliance', 'committee_pack'],
                'skills' => ['pitchbook_building', 'earnings_review', 'model_building', 'valuation_methodology_check', 'kyc_screening'],
                'systems' => ['market_data_terminal', 'sec_filings', 'spreadsheet_model', 'portfolio_analytics', 'compliance_system'],
                'data_products' => ['comps_model', 'earnings_update', 'valuation_packet', 'kyc_file', 'investment_committee_memo'],
                'controls' => ['source_attribution_required', 'model_audit_trail_required', 'live_trade_blocked', 'compliance_escalation_required'],
            ],
            'marketing' => [
                'value_chain' => ['market_insight', 'positioning', 'creative_brief', 'campaign_plan', 'experiment_readout', 'lifecycle_iteration'],
                'skills' => ['audience_segmentation', 'positioning_strategy', 'creative_briefing', 'funnel_analysis', 'lifecycle_orchestration'],
                'systems' => ['crm', 'analytics', 'content_repository', 'ads_platform', 'approval_system'],
                'data_products' => ['audience_segments', 'positioning_system', 'creative_pack', 'experiment_readout', 'campaign_plan'],
                'controls' => ['claims_review_required', 'campaign_publish_operator_only', 'regulated_targeting_review_required'],
            ],
            'cyber' => [
                'value_chain' => ['scope_and_roe', 'asset_inventory', 'finding_triage', 'control_mapping', 'detection_proposal', 'remediation_plan'],
                'skills' => ['threat_modeling', 'vulnerability_enrichment', 'appsec_review', 'detection_engineering', 'grc_mapping'],
                'systems' => ['sbom', 'repo_read_adapter', 'vulnerability_feed', 'ticketing_system', 'evidence_chain'],
                'data_products' => ['attack_surface_delta', 'finding_triage_packet', 'control_map', 'detection_rule_proposal', 'remediation_plan'],
                'controls' => ['authorized_scope_required', 'offensive_execution_blocked', 'remediation_requires_owner_acceptance'],
            ],
            'automation' => [
                'value_chain' => ['opportunity_intake', 'tool_selection', 'workflow_design', 'fixture_replay', 'reliability_review', 'supervised_handoff'],
                'skills' => ['api_schema_analysis', 'browser_workflow_design', 'mcp_adapter_design', 'tool_benchmarking', 'replay_debugging'],
                'systems' => ['tool_registry', 'browser_adapter', 'api_schema_registry', 'workflow_engine', 'receipt_ledger'],
                'data_products' => ['automation_opportunity_pack', 'tool_scorecard', 'workflow_runbook', 'mcp_adapter_packet', 'reliability_report'],
                'controls' => ['destructive_action_blocked', 'credential_export_blocked', 'idempotency_required'],
            ],
            'personal_development' => [
                'value_chain' => ['goal_scope', 'baseline_review', 'curriculum_design', 'habit_iteration', 'reflection', 'privacy_review'],
                'skills' => ['coaching_intake', 'curriculum_design', 'habit_system_design', 'reflection_synthesis', 'privacy_filtering'],
                'systems' => ['goal_registry', 'private_memory', 'habit_log', 'learning_resource_registry', 'review_packet'],
                'data_products' => ['life_operating_review', 'learning_curriculum', 'habit_plan', 'reflection_synthesis', 'privacy_review_packet'],
                'controls' => ['private_memory_review_required', 'sensitive_export_blocked', 'operator_controls_all_external_sharing'],
            ],
            default => [
                'value_chain' => ['readiness', 'incident_intake', 'triage', 'runbook_execution', 'capacity_review', 'postmortem_learning'],
                'skills' => ['slo_analysis', 'incident_command', 'log_metric_correlation', 'runbook_authoring', 'postmortem_editing'],
                'systems' => ['metrics_stack', 'logs_stack', 'alerting_system', 'runbook_repository', 'incident_tracker'],
                'data_products' => ['readiness_review', 'incident_packet', 'capacity_review', 'runbook_update', 'postmortem_action_plan'],
                'controls' => ['production_mutation_operator_only', 'incident_receipts_required', 'rollback_plan_required'],
            ],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function premiumAgentTemplates(string $domainId): array
    {
        $templates = match ($domainId) {
            'software' => [
                ['template_id' => 'product_spec_architect', 'purpose' => 'turn_operator_intent_into_spec_architecture_and_acceptance_contract'],
                ['template_id' => 'repo_cartographer', 'purpose' => 'map_code_ownership_dependencies_tests_and_runtime_boundaries'],
                ['template_id' => 'patch_builder', 'purpose' => 'produce_minimal_patch_with_receipts_and_rollback_plan'],
                ['template_id' => 'test_repair_operator', 'purpose' => 'run_tests_triage_failures_and_repair_with_evidence'],
                ['template_id' => 'security_release_reviewer', 'purpose' => 'review_security_release_risk_and_certification_packet'],
                ['template_id' => 'post_release_learning_agent', 'purpose' => 'convert_results_into_playbook_and_fixture_improvements'],
                ['template_id' => 'dependency_modernization_agent', 'purpose' => 'plan_dependency_upgrades_version_pins_and_contract_test_rollout'],
                ['template_id' => 'observability_instrumentation_agent', 'purpose' => 'bind_logs_metrics_traces_and_runtime_slos_to_delivery_flows'],
                ['template_id' => 'incident_repair_commander', 'purpose' => 'triage_runtime_incidents_patch_candidates_and_rollback_decisions'],
                ['template_id' => 'developer_platform_operator', 'purpose' => 'operate_ci_harness_tooling_and_internal_developer_experience_backlog'],
            ],
            'research' => [
                ['template_id' => 'source_scout', 'purpose' => 'discover_primary_sources_and_rank_source_quality'],
                ['template_id' => 'claim_attributor', 'purpose' => 'bind_claims_to_source_refs_and_quote_boundaries'],
                ['template_id' => 'contradiction_judge', 'purpose' => 'surface_conflicts_and_decide_resolution_or_disclosure'],
                ['template_id' => 'citation_graph_builder', 'purpose' => 'build_source_and_citation_lineage'],
                ['template_id' => 'executive_synthesizer', 'purpose' => 'produce_decision_ready_research_briefs'],
                ['template_id' => 'watchlist_monitor', 'purpose' => 'track_source_deltas_and_refresh_staleness'],
                ['template_id' => 'methodology_reviewer', 'purpose' => 'review_study_design_sampling_bias_and_confidence_limits'],
                ['template_id' => 'data_room_librarian', 'purpose' => 'organize_private_context_source_sets_and_access_boundaries'],
                ['template_id' => 'expert_interview_analyst', 'purpose' => 'extract_claims_from_interviews_and_bind_them_to_verbatim_evidence'],
                ['template_id' => 'research_delivery_editor', 'purpose' => 'package_findings_into_operator_ready_briefs_with_disclosures'],
            ],
            'strategy' => [
                ['template_id' => 'venture_thesis_builder', 'purpose' => 'build_company_thesis_assumptions_and_market_logic'],
                ['template_id' => 'market_mapper', 'purpose' => 'map_competitors_segments_tam_sam_som_and_demand_signals'],
                ['template_id' => 'gtm_system_designer', 'purpose' => 'design_channel_offer_pricing_and_sales_motion'],
                ['template_id' => 'experiment_allocator', 'purpose' => 'rank_experiments_by_value_risk_speed_and_learning'],
                ['template_id' => 'competitive_wargamer', 'purpose' => 'simulate_rival_moves_and_defensive_options'],
                ['template_id' => 'board_dossier_writer', 'purpose' => 'package_decisions_for_operator_or_portfolio_review'],
                ['template_id' => 'pricing_packaging_strategist', 'purpose' => 'model_offer_packaging_pricing_power_and_willingness_to_pay_evidence'],
                ['template_id' => 'partnership_scout', 'purpose' => 'identify_distribution_technology_and_capital_partnership_options'],
                ['template_id' => 'portfolio_capital_allocator', 'purpose' => 'rank_company_investment_options_by_risk_capacity_and_expected_learning'],
                ['template_id' => 'moat_and_risk_reviewer', 'purpose' => 'stress_test_strategy_against_competition_regulation_and_execution_risk'],
            ],
            'finance' => [
                ['template_id' => 'pitch_builder', 'purpose' => 'create_target_lists_comparables_and_pitchbook_materials'],
                ['template_id' => 'meeting_preparer', 'purpose' => 'assemble_client_counterparty_and_asset_briefs'],
                ['template_id' => 'earnings_reviewer', 'purpose' => 'read_transcripts_filings_update_models_and_flag_thesis_changes'],
                ['template_id' => 'model_builder', 'purpose' => 'build_and_maintain_financial_models_from_filings_data_feeds_and_inputs'],
                ['template_id' => 'market_researcher', 'purpose' => 'track_sector_issuer_news_filings_research_and_risk_items'],
                ['template_id' => 'valuation_reviewer', 'purpose' => 'check_valuation_against_comparables_methodology_and_review_standards'],
                ['template_id' => 'general_ledger_reconciler', 'purpose' => 'reconcile_accounts_and_nav_style_book_records'],
                ['template_id' => 'month_end_closer', 'purpose' => 'run_close_checklists_prepare_journal_entries_and_close_reports'],
                ['template_id' => 'statement_auditor', 'purpose' => 'review_statements_for_consistency_completeness_and_audit_readiness'],
                ['template_id' => 'kyc_screener', 'purpose' => 'assemble_entity_files_review_documents_and_package_compliance_escalations'],
            ],
            'marketing' => [
                ['template_id' => 'growth_strategist', 'purpose' => 'build_growth_strategy_from_analytics_crm_and_market_research'],
                ['template_id' => 'icp_positioning_builder', 'purpose' => 'define_icp_pain_positioning_claims_and_proof_points'],
                ['template_id' => 'campaign_planner', 'purpose' => 'plan_campaign_objectives_channels_assets_and_approval_gates'],
                ['template_id' => 'creative_director', 'purpose' => 'produce_copy_briefs_creative_variants_and_brand_consistency_reviews'],
                ['template_id' => 'funnel_analyst', 'purpose' => 'diagnose_conversion_dropoffs_and_experiment_opportunities'],
                ['template_id' => 'lifecycle_operator', 'purpose' => 'design_lifecycle_and_retention_campaigns_without_auto_send'],
                ['template_id' => 'seo_content_operator', 'purpose' => 'plan_search_content_clusters_claim_evidence_and_refresh_cadence'],
                ['template_id' => 'paid_media_controller', 'purpose' => 'prepare_budget_pacing_audience_controls_and_spend_approval_packets'],
                ['template_id' => 'brand_claim_reviewer', 'purpose' => 'audit_public_claims_proof_points_compliance_and_tone_consistency'],
                ['template_id' => 'crm_revenue_ops_handoff', 'purpose' => 'convert_growth_signals_into_sales_crm_and_success_handoff_packets'],
            ],
            'cyber' => [
                ['template_id' => 'scope_and_roe_gatekeeper', 'purpose' => 'validate_authorized_scope_rules_of_engagement_and_allowed_actions'],
                ['template_id' => 'appsec_triager', 'purpose' => 'review_findings_reproducibility_severity_and_remediation'],
                ['template_id' => 'security_posture_analyst', 'purpose' => 'map_attack_surface_dependencies_vulnerabilities_and_exposure'],
                ['template_id' => 'grc_mapper', 'purpose' => 'map_controls_obligations_evidence_and_audit_gaps'],
                ['template_id' => 'detection_engineer', 'purpose' => 'draft_detection_rules_and_validation_plans'],
                ['template_id' => 'remediation_program_manager', 'purpose' => 'prioritize_fix_programs_tickets_and_sla_risk_without_unauthorized_mutation'],
                ['template_id' => 'threat_intel_correlator', 'purpose' => 'correlate_cves_advisories_assets_and_exploitability_signals'],
                ['template_id' => 'incident_response_scribe', 'purpose' => 'assemble_timeline_decision_log_evidence_and_post_incident_actions'],
                ['template_id' => 'identity_access_reviewer', 'purpose' => 'review_access_paths_privilege_risk_and_segregation_of_duties'],
                ['template_id' => 'secure_change_reviewer', 'purpose' => 'gate_security_sensitive_changes_with_rollback_and_monitoring_requirements'],
            ],
            'automation' => [
                ['template_id' => 'automation_architect', 'purpose' => 'select_high_roi_safe_automation_opportunities'],
                ['template_id' => 'tool_scout', 'purpose' => 'evaluate_repositories_tools_mcp_servers_and_api_surfaces'],
                ['template_id' => 'browser_workflow_designer', 'purpose' => 'design_browser_runbooks_with_screenshots_and_replay_fixtures'],
                ['template_id' => 'api_workflow_designer', 'purpose' => 'design_openapi_mcp_and_connector_workflows'],
                ['template_id' => 'terminal_workflow_designer', 'purpose' => 'draft_terminal_automation_with_dry_run_and_rollback'],
                ['template_id' => 'tool_reliability_operator', 'purpose' => 'monitor_receipts_replay_failures_and_regressions'],
                ['template_id' => 'integration_test_builder', 'purpose' => 'turn_automation_flows_into_fixtures_assertions_and_regression_suites'],
                ['template_id' => 'approval_policy_designer', 'purpose' => 'map_tool_permissions_approval_steps_and_forbidden_side_effects'],
                ['template_id' => 'workflow_queue_operator', 'purpose' => 'operate_prioritized_runs_retries_dead_letters_and_sla_backlogs'],
                ['template_id' => 'automation_roi_auditor', 'purpose' => 'measure_saved_time_quality_risk_and_maintenance_cost_per_automation'],
            ],
            'personal_development' => [
                ['template_id' => 'life_operating_reviewer', 'purpose' => 'review_goals_energy_focus_and_weekly_commitments'],
                ['template_id' => 'learning_designer', 'purpose' => 'build_curricula_practice_loops_and_skill_gap_maps'],
                ['template_id' => 'habit_system_designer', 'purpose' => 'design_habit_protocols_review_cadence_and_friction_removal'],
                ['template_id' => 'reflection_analyst', 'purpose' => 'synthesize_private_reflections_into_safe_learning_records'],
                ['template_id' => 'focus_planner', 'purpose' => 'plan_deep_work_blocks_priorities_and_review_checkpoints'],
                ['template_id' => 'privacy_guardian', 'purpose' => 'protect_private_memory_and_block_sensitive_externalization'],
                ['template_id' => 'performance_review_coach', 'purpose' => 'convert_weekly_evidence_into_skill_growth_and_behavior_adjustments'],
                ['template_id' => 'project_commitment_operator', 'purpose' => 'manage_personal_projects_next_actions_blockers_and_review_packets'],
                ['template_id' => 'decision_journal_analyst', 'purpose' => 'track_decisions_assumptions_outcomes_and_calibration_learning'],
                ['template_id' => 'wellbeing_boundary_reviewer', 'purpose' => 'surface_overload_risk_recovery_needs_and_private_boundary_controls'],
            ],
            default => [
                ['template_id' => 'readiness_reviewer', 'purpose' => 'review_slo_runbooks_capacity_and_operational_risk'],
                ['template_id' => 'incident_commander', 'purpose' => 'assemble_incident_timeline_diagnostics_and_decision_packet'],
                ['template_id' => 'runbook_engineer', 'purpose' => 'create_and_update_runbooks_from_operational_evidence'],
                ['template_id' => 'capacity_planner', 'purpose' => 'forecast_capacity_slo_risk_and_resource_needs'],
                ['template_id' => 'postmortem_editor', 'purpose' => 'convert_incidents_into_actions_and_learning_records'],
                ['template_id' => 'alert_noise_reducer', 'purpose' => 'diagnose_alert_quality_and_propose_noise_reduction'],
                ['template_id' => 'process_mining_analyst', 'purpose' => 'find_operational_bottlenecks_variance_and_automation_candidates'],
                ['template_id' => 'vendor_sla_controller', 'purpose' => 'track_vendor_service_levels_escalations_and_procurement_handoffs'],
                ['template_id' => 'change_management_operator', 'purpose' => 'prepare_change_windows_risk_reviews_and_communication_packets'],
                ['template_id' => 'continuous_improvement_allocator', 'purpose' => 'rank_process_improvement_backlog_by_value_risk_and_capacity'],
            ],
        };

        return array_values(array_map(
            static fn (array $template): array => [
                ...$template,
                'skills' => ['domain_context_loading', 'connector_reasoning', 'structured_artifact_authoring', 'risk_review', 'receipt_export', 'handoff_packet'],
                'connectors_required' => 'mapped_per_flow_or_workbench',
                'subagent_pattern' => 'specialist_subagent_for_research_methodology_or_critic_review',
                'tool_permissions' => 'least_privilege_read_or_fixture_until_operator_mandate',
                'audit_log_required' => true,
                'human_in_loop_required_before_external_action' => true,
                'template_hash' => hash('sha256', 'premium_agent_template|'.$template['template_id']),
            ],
            $templates,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function premiumReferenceSourceBasis(string $domainId): array
    {
        $sources = [
            [
                'source_id' => 'anthropic_agents_for_financial_services_2026',
                'url' => 'https://www.anthropic.com/news/finance-agents',
                'adopted_pattern' => 'skills_connectors_subagents_managed_agents_per_tool_permissions_credential_vault_and_audit_log',
                'applies_to' => $domainId === 'finance' ? 'primary_finance_template_catalog' : 'enterprise_template_packaging_pattern',
            ],
            [
                'source_id' => 'anthropic_claude_for_financial_services_2025',
                'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'adopted_pattern' => 'financial_analysis_solution_custom_apps_compliance_underwriting_customer_and_back_office_transformation',
                'applies_to' => $domainId === 'finance' ? 'finance_solution_reference' : 'regulated_workflow_reference',
            ],
            [
                'source_id' => 'kpmg_enterprise_ai_agents_strategy_2026',
                'url' => 'https://kpmg.com/us/en/articles/2026/enterprise-ai-agents-strategy.html',
                'adopted_pattern' => 'enterprise_readiness_governance_operating_model_modern_architecture_and_measurement',
                'applies_to' => 'all_companies',
            ],
            [
                'source_id' => 'replayable_financial_agents_dfah_2026',
                'url' => 'https://arxiv.org/abs/2601.15322',
                'adopted_pattern' => 'measure_decision_determinism_and_evidence_faithfulness_independently_for_audit_replay',
                'applies_to' => 'regulated_and_high_risk_flow_replay',
            ],
            [
                'source_id' => 'ai_trust_os_2026',
                'url' => 'https://arxiv.org/abs/2604.04749',
                'adopted_pattern' => 'telemetry_first_continuous_governance_zero_trust_observability_and_trust_artifacts',
                'applies_to' => 'portfolio_control_tower_and_company_observability',
            ],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'review_state' => 'reference_reviewed_for_architecture_not_runtime_ingested',
                'source_hash' => hash('sha256', 'premium_reference_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function agentFrameworkSourceCatalog(string $domainId): array
    {
        $catalog = [
            [
                'source_id' => 'anthropic_claude_for_financial_services',
                'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'adopted_pattern' => 'unified_interface_over_internal_external_data_mcp_connectors_and_domain_expert_support',
                'applies_to' => $domainId === 'finance' ? 'primary_domain_blueprint' : 'regulated_domain_connector_blueprint',
            ],
            [
                'source_id' => 'openai_agents_sdk',
                'url' => 'https://developers.openai.com/api/docs/guides/agents',
                'adopted_pattern' => 'specialist_agents_tools_handoffs_guardrails_state_and_tracing',
                'applies_to' => 'all_company_flows',
            ],
            [
                'source_id' => 'crewai_flows_crews',
                'url' => 'https://docs.crewai.com/en/introduction',
                'adopted_pattern' => 'stateful_flows_with_specialist_crews_for_complex_work',
                'applies_to' => 'multi_agent_work_decomposition',
            ],
            [
                'source_id' => 'microsoft_agent_framework',
                'url' => 'https://github.com/microsoft/agent-framework',
                'adopted_pattern' => 'production_grade_agents_multi_agent_workflows_durability_observability_governance_and_human_in_loop',
                'applies_to' => 'production_agent_runtime_benchmark',
            ],
            [
                'source_id' => 'microsoft_autogen_agent_framework_lineage',
                'url' => 'https://github.com/microsoft/autogen',
                'adopted_pattern' => 'enterprise_grade_multi_agent_orchestration_mcp_and_a2a_migration_path',
                'applies_to' => 'agent_framework_benchmark',
            ],
            [
                'source_id' => 'langgraph_durable_execution',
                'url' => 'https://docs.langchain.com/oss/javascript/langgraph/durable-execution',
                'adopted_pattern' => 'checkpointed_durable_execution_resume_after_interrupt_or_failure',
                'applies_to' => 'long_running_flows_and_recovery',
            ],
            [
                'source_id' => 'model_context_protocol_servers',
                'url' => 'https://github.com/modelcontextprotocol/servers',
                'adopted_pattern' => 'standardized_tool_and_context_server_catalog_for_connector_backplanes',
                'applies_to' => 'connector_tool_surface_design',
            ],
            [
                'source_id' => 'temporal_durable_workflows',
                'url' => 'https://github.com/temporalio/sdk-php',
                'adopted_pattern' => 'durable_workflow_replay_retry_and_long_running_business_process_orchestration',
                'applies_to' => 'durable_company_flow_runtime',
            ],
            [
                'source_id' => 'opentelemetry_collector_tracing',
                'url' => 'https://github.com/open-telemetry/opentelemetry-collector',
                'adopted_pattern' => 'trace_metric_log_collection_for_agent_runtime_observability',
                'applies_to' => 'runtime_observability_and_audit_export',
            ],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'license_review', 'security_review', 'sandbox_probe', 'benchmark_result'],
                'source_hash' => hash('sha256', 'agent_framework_source|'.$source['source_id']),
            ],
            $catalog,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function flowRuntimeImplementationSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'source_verified_financial_services_agents_with_audit_trails'],
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://platform.openai.com/docs/guides/agents-sdk/', 'pattern' => 'tools_handoffs_guardrails_tracing_for_agentic_applications'],
            ['source_id' => 'crewai_flows', 'url' => 'https://docs.crewai.com/en/concepts/flows', 'pattern' => 'stateful_multi_step_flow_orchestration_with_crews'],
            ['source_id' => 'langgraph_durable_execution', 'url' => 'https://langchain-5e9cc07a.mintlify.app/oss/python/langgraph/durable-execution', 'pattern' => 'durable_execution_checkpoints_and_human_interrupts'],
            ['source_id' => 'temporal_durable_execution', 'url' => 'https://docs.temporal.io/evaluate/development-production-features/durable-execution', 'pattern' => 'durable_workflow_replay_and_failure_recovery'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'adoption_boundary' => 'pattern_reference_until_local_contract_tests_and_operator_review',
                'evidence_required_before_adoption' => ['source_review', 'license_or_terms_review', 'security_review', 'local_replay_result', 'operator_acceptance'],
                'source_hash' => hash('sha256', 'flow_runtime_implementation_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function externalResearchSourceBasis(string $domainId): array
    {
        $sources = array_values(array_merge(
            $this->flowRuntimeImplementationSourceCatalog(),
            $this->agentFrameworkSourceCatalog($domainId),
            $this->domainSolutionSourceCatalog($domainId),
            $this->premiumReferenceSourceBasis($domainId),
        ));
        $seen = [];

        return array_values(array_filter(array_map(
            static function (array $source) use (&$seen): ?array {
                $sourceId = (string) ($source['source_id'] ?? 'unknown_source');
                if (isset($seen[$sourceId])) {
                    return null;
                }
                $seen[$sourceId] = true;

                return [
                    'source_id' => $sourceId,
                    'url' => (string) ($source['url'] ?? ''),
                    'capability' => (string) ($source['capability'] ?? $source['pattern'] ?? $source['adopted_pattern'] ?? $source['use'] ?? 'enterprise_agentic_pattern'),
                    'review_state' => 'architecture_reviewed_local_runtime_adoption_requires_tests',
                    'source_links_required' => true,
                    'external_side_effects_default' => false,
                    'adoption_boundary' => 'reference_to_contract_only_until_license_security_fixture_and_operator_review',
                    'source_hash' => hash('sha256', 'external_research_source_basis|'.$sourceId),
                ];
            },
            $sources,
        )));
    }

    /**
     * @return list<string>
     */
    public function domainWorkloadSkills(string $domainId, string $flowId): array
    {
        $base = ['scope_intake', 'source_grounding', 'tool_plan', 'typed_artifact_authoring', 'methodology_check', 'policy_review', 'handoff_packet'];
        $domain = match ($domainId) {
            'finance' => ['financial_model_audit', 'valuation_methodology', 'kyc_or_compliance_screening', 'investment_committee_memo'],
            'marketing' => ['audience_segmentation', 'campaign_strategy', 'creative_briefing', 'attribution_analysis'],
            'cyber' => ['defensive_security_triage', 'control_mapping', 'risk_register_update', 'remediation_plan'],
            'software' => ['repo_context_loading', 'code_patch_planning', 'test_repair_loop', 'release_risk_review'],
            'research' => ['citation_graph_building', 'claim_verification', 'contradiction_register', 'synthesis_briefing'],
            'strategy' => ['market_mapping', 'competitive_intelligence', 'experiment_design', 'board_decision_packet'],
            'automation' => ['tool_selection', 'workflow_replay', 'mcp_adapter_design', 'reliability_review'],
            'personal_development' => ['private_context_minimization', 'learning_plan_design', 'habit_review', 'privacy_guard_review'],
            default => ['incident_triage', 'runbook_execution', 'capacity_review', 'postmortem_action_tracking'],
        };

        return array_values(array_unique(array_merge($base, $domain, [$flowId.'_procedure'])));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function domainWorkloadSubagents(string $domainId, string $flowId): array
    {
        $domainReviewer = match ($domainId) {
            'finance' => 'valuation_or_compliance_reviewer_subagent',
            'marketing' => 'growth_and_brand_reviewer_subagent',
            'cyber' => 'defensive_security_reviewer_subagent',
            'software' => 'code_quality_and_test_reviewer_subagent',
            'research' => 'source_faithfulness_reviewer_subagent',
            'strategy' => 'strategy_assumption_reviewer_subagent',
            'automation' => 'tool_reliability_reviewer_subagent',
            'personal_development' => 'privacy_and_learning_reviewer_subagent',
            default => 'operations_reliability_reviewer_subagent',
        };

        return [
            [
                'subagent_id' => $flowId.'.source_grounding_subagent',
                'purpose' => 'select_and_verify_sources_before_artifact_work',
                'context_scope' => ['flow_objective', 'source_catalog_refs', 'connector_schema_snapshots'],
                'tools' => ['source_registry', 'read_only_connector_probe', 'lineage_builder'],
                'external_effects_allowed' => false,
            ],
            [
                'subagent_id' => $flowId.'.'.$domainReviewer,
                'purpose' => 'check_domain_methodology_risk_and_quality',
                'context_scope' => ['draft_artifact', 'methodology_notes', 'risk_policy_profile'],
                'tools' => ['critic_review', 'policy_gate', 'quality_scorecard'],
                'external_effects_allowed' => false,
            ],
            [
                'subagent_id' => $flowId.'.handoff_and_audit_subagent',
                'purpose' => 'assemble_receipts_handoff_packet_and_operator_review_queue',
                'context_scope' => ['artifact_hash', 'tool_receipts', 'decision_receipt', 'acceptance_criteria'],
                'tools' => ['receipt_verifier', 'handoff_packet_builder', 'operator_review_queue'],
                'external_effects_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainCompanyExecutionSuiteProfile(string $domainId): array
    {
        return match ($domainId) {
            'cyber' => [
                'category' => 'security_operations_appsec_grc_detection_response',
                'roles' => ['security_architect', 'appsec_triage_lead', 'grc_control_owner', 'detection_engineer', 'incident_commander', 'remediation_manager'],
                'workbenches' => ['security_posture_workbench', 'vulnerability_triage_queue', 'control_mapping_lab', 'detection_engineering_lab', 'incident_readiness_room'],
                'cadences' => ['daily_posture_review', 'per_finding_triage', 'weekly_detection_review', 'monthly_grc_attestation'],
                'control_frameworks' => ['nist_csf_2', 'mitre_attack_enterprise', 'owasp_asvs', 'cisa_kev', 'soc2_style_control_evidence'],
                'risk_checks' => ['known_exploited_vulnerability', 'attack_path_exposure', 'auth_boundary_gap', 'data_exfiltration_risk', 'unreviewed_offensive_action', 'missing_remediation_owner'],
                'decision_types' => ['prioritize_remediation', 'approve_detection_rule', 'accept_or_reject_control_gap', 'escalate_incident_readiness'],
            ],
            'strategy' => [
                'category' => 'market_strategy_portfolio_intelligence_and_board_decisions',
                'roles' => ['market_intelligence_lead', 'venture_thesis_owner', 'portfolio_strategy_partner', 'experiment_allocator', 'board_memo_editor', 'competitive_analyst'],
                'workbenches' => ['market_map_room', 'rival_intelligence_table', 'venture_thesis_lab', 'experiment_allocation_board', 'board_memo_factory'],
                'cadences' => ['weekly_opportunity_committee', 'per_experiment_review', 'monthly_strategy_board', 'quarterly_portfolio_reset'],
                'control_frameworks' => ['source_reliability_matrix', 'assumption_register', 'decision_log', 'counterfactual_review', 'portfolio_risk_register'],
                'risk_checks' => ['weak_source_basis', 'unclear_customer_segment', 'unpriced_execution_risk', 'strategy_without_metric', 'overfit_to_single_source', 'capital_allocation_without_review'],
                'decision_types' => ['approve_thesis', 'rank_opportunity', 'allocate_experiment_budget', 'revise_go_to_market_motion'],
            ],
            'finance' => [
                'category' => 'financial_research_treasury_billing_risk_and_investment_committee',
                'roles' => ['financial_research_lead', 'valuation_model_owner', 'treasury_controller', 'billing_ops_owner', 'model_risk_reviewer', 'investment_committee_secretary'],
                'workbenches' => ['financial_research_terminal', 'valuation_model_room', 'treasury_risk_board', 'billing_reconciliation_desk', 'investment_committee_room'],
                'cadences' => ['daily_cash_risk_review', 'weekly_forecast_review', 'monthly_close_review', 'per_investment_committee'],
                'control_frameworks' => ['source_linked_financial_claims', 'model_risk_management', 'cash_movement_segregation', 'close_and_audit_evidence', 'billing_reconciliation'],
                'risk_checks' => ['unlinked_financial_claim', 'model_assumption_drift', 'cash_movement_request', 'billing_exception', 'capital_commitment_without_committee', 'unreviewed_market_data_staleness'],
                'decision_types' => ['approve_forecast', 'escalate_model_risk', 'prepare_investment_packet', 'block_cash_or_trade_action'],
            ],
            'marketing' => [
                'category' => 'growth_marketing_brand_lifecycle_and_attribution',
                'roles' => ['growth_strategy_lead', 'brand_steward', 'creative_director', 'lifecycle_operator', 'attribution_analyst', 'claim_review_owner'],
                'workbenches' => ['growth_intelligence_room', 'campaign_factory', 'claim_evidence_lab', 'attribution_model_room', 'brand_review_queue'],
                'cadences' => ['weekly_growth_review', 'per_campaign_review', 'monthly_brand_claim_audit', 'quarterly_channel_mix_review'],
                'control_frameworks' => ['brand_policy', 'source_backed_claims', 'privacy_review', 'budget_guardrails', 'crm_handoff_quality'],
                'risk_checks' => ['unverified_claim', 'public_publish_request', 'paid_spend_request', 'privacy_sensitive_audience', 'brand_mismatch', 'unsupported_roi_claim'],
                'decision_types' => ['approve_internal_campaign', 'route_brand_revision', 'rank_experiment', 'block_public_claim'],
            ],
            'software' => [
                'category' => 'software_engineering_delivery_release_security_and_repair',
                'roles' => ['principal_architect', 'code_intelligence_owner', 'release_captain', 'security_reviewer', 'test_repair_owner', 'developer_experience_lead'],
                'workbenches' => ['architecture_decision_room', 'patch_repair_queue', 'test_failure_lab', 'release_certification_room', 'security_review_queue'],
                'cadences' => ['per_patch_repair_loop', 'daily_ci_certification', 'weekly_security_review', 'per_release_go_no_go'],
                'control_frameworks' => ['test_matrix', 'code_review_receipts', 'release_slo', 'security_findings', 'rollback_plan'],
                'risk_checks' => ['untested_patch', 'breaking_contract_change', 'security_regression', 'missing_rollback', 'unreviewed_dependency', 'release_without_receipt'],
                'decision_types' => ['approve_patch_plan', 'merge_or_repair', 'promote_release', 'block_security_regression'],
            ],
            'research' => [
                'category' => 'primary_research_source_graph_synthesis_and_evidence_delivery',
                'roles' => ['primary_source_researcher', 'citation_graph_curator', 'contradiction_reviewer', 'methodology_critic', 'executive_brief_editor', 'source_auditor'],
                'workbenches' => ['source_discovery_room', 'citation_graph_lab', 'contradiction_resolution_table', 'methodology_review_queue', 'brief_delivery_factory'],
                'cadences' => ['per_research_question', 'weekly_source_quality_review', 'monthly_methodology_audit', 'per_executive_brief'],
                'control_frameworks' => ['source_quality_rubric', 'citation_lineage', 'contradiction_register', 'methodology_notes', 'confidence_calibration'],
                'risk_checks' => ['unsupported_claim', 'stale_source', 'citation_gap', 'contradictory_evidence', 'low_confidence_without_disclosure', 'private_data_leak'],
                'decision_types' => ['accept_source', 'resolve_contradiction', 'publish_internal_brief', 'route_methodology_review'],
            ],
            default => [
                'category' => 'operations_automation_delivery_assurance_and_control_tower',
                'roles' => ['operations_controller', 'automation_architect', 'process_owner', 'slo_manager', 'incident_coordinator', 'continuous_improvement_lead'],
                'workbenches' => ['process_command_center', 'automation_design_lab', 'runbook_drill_room', 'capacity_slo_board', 'incident_exception_desk'],
                'cadences' => ['daily_operations_review', 'weekly_capacity_review', 'per_incident_postmortem', 'monthly_process_improvement'],
                'control_frameworks' => ['runbook_evidence', 'slo_sli_catalog', 'incident_postmortem', 'change_control', 'automation_risk_register'],
                'risk_checks' => ['runbook_gap', 'capacity_overrun', 'automation_without_fallback', 'incident_route_missing', 'change_without_window', 'external_action_without_mandate'],
                'decision_types' => ['prioritize_run', 'approve_internal_automation', 'escalate_incident', 'schedule_change_window'],
            ],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function domainCompanyExecutionSuiteSources(string $domainId): array
    {
        $common = [
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://openai.github.io/openai-agents-python/', 'use' => 'agents_handoffs_guardrails_sessions_tracing'],
            ['source_id' => 'model_context_protocol', 'url' => 'https://modelcontextprotocol.io/', 'use' => 'tool_and_data_connector_context_protocol'],
        ];

        $domain = match ($domainId) {
            'cyber' => [
                ['source_id' => 'nist_csf_2', 'url' => 'https://www.nist.gov/cyberframework', 'use' => 'cybersecurity_governance_risk_and_control_outcomes'],
                ['source_id' => 'mitre_attack_enterprise', 'url' => 'https://attack.mitre.org/matrices/enterprise/', 'use' => 'threat_modeling_detection_and_response_mapping'],
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'application_security_verification_requirements'],
                ['source_id' => 'cisa_kev', 'url' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog', 'use' => 'exploited_vulnerability_prioritization'],
                ['source_id' => 'semgrep', 'url' => 'https://github.com/semgrep/semgrep', 'use' => 'static_analysis_rule_reference'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_filings_and_market_diligence'],
                ['source_id' => 'world_bank_data', 'url' => 'https://data.worldbank.org/', 'use' => 'macro_market_and_country_data'],
                ['source_id' => 'fred', 'url' => 'https://fred.stlouisfed.org/', 'use' => 'economic_time_series_for_strategy'],
                ['source_id' => 'google_trends', 'url' => 'https://trends.google.com/trends/', 'use' => 'market_interest_signal_reference'],
                ['source_id' => 'crunchbase', 'url' => 'https://www.crunchbase.com/', 'use' => 'company_and_funding_landscape_reference'],
            ],
            'finance' => [
                ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'financial_services_reference_architecture'],
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'financial_filings'],
                ['source_id' => 'stripe_billing', 'url' => 'https://stripe.com/billing/features', 'use' => 'billing_revenue_operations'],
                ['source_id' => 'fred', 'url' => 'https://fred.stlouisfed.org/', 'use' => 'macro_financial_data'],
                ['source_id' => 'openbb', 'url' => 'https://github.com/OpenBB-finance/OpenBB', 'use' => 'financial_research_terminal_reference'],
            ],
            'marketing' => [
                ['source_id' => 'hubspot_marketing_hub', 'url' => 'https://www.hubspot.com/products/marketing', 'use' => 'marketing_campaign_and_lifecycle_reference'],
                ['source_id' => 'salesforce_marketing_cloud', 'url' => 'https://www.salesforce.com/marketing/', 'use' => 'enterprise_marketing_cloud_reference'],
                ['source_id' => 'amplitude_experiment', 'url' => 'https://amplitude.com/experiment', 'use' => 'experimentation_reference'],
                ['source_id' => 'segment_cdp', 'url' => 'https://segment.com/', 'use' => 'customer_data_platform_reference'],
                ['source_id' => 'google_analytics', 'url' => 'https://analytics.google.com/', 'use' => 'attribution_and_analytics_reference'],
            ],
            default => [
                ['source_id' => 'servicenow_ai_agents', 'url' => 'https://www.servicenow.com/products/ai-agents.html', 'use' => 'enterprise_operations_ai_agents_reference'],
                ['source_id' => 'temporal', 'url' => 'https://github.com/temporalio/temporal', 'use' => 'durable_workflow_orchestration_reference'],
                ['source_id' => 'opentelemetry', 'url' => 'https://opentelemetry.io/', 'use' => 'tracing_metrics_logs_observability_reference'],
                ['source_id' => 'grafana', 'url' => 'https://github.com/grafana/grafana', 'use' => 'operations_dashboard_reference'],
                ['source_id' => 'linear', 'url' => 'https://linear.app/', 'use' => 'issue_and_workflow_tracking_reference'],
            ],
        };

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'schema' => 'atlas.ai.company.domain_company_execution_source.v1',
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'terms_or_license_review', 'security_review', 'fixture_eval', 'operator_acceptance'],
                'external_side_effects_enabled' => false,
                'source_hash' => hash('sha256', 'domain_company_execution_source|'.$domainId.'|'.$source['source_id']),
            ],
            array_values(array_merge($domain, $common)),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function blueprint(string $domainId): array
    {
        $map = $this->blueprints();

        return $map[$domainId] ?? $map['operations'];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function blueprints(): array
    {
        return [
            'software' => [
                'agent_roles' => ['principal_architect_agent', 'code_intelligence_agent', 'release_captain_agent', 'security_reviewer_agent'],
                'connectors' => ['git_workspace', 'test_runner', 'code_intelligence_index', 'ci_status_read_adapter', 'evidence_ledger'],
                'reference_patterns' => ['execution_grounded_software_agents', 'repo_state_feedback_loop', 'durable_patch_review_repair'],
                'flow_specs' => [
                    'product_spec_to_patch' => ['principal_architect_agent', ['git_workspace', 'code_intelligence_index'], 'spec_pack_to_patch_plan'],
                    'autonomous_repair_loop' => ['code_intelligence_agent', ['test_runner', 'evidence_ledger'], 'failure_triage_patch_repair'],
                    'release_certification' => ['release_captain_agent', ['ci_status_read_adapter', 'evidence_ledger'], 'release_evidence_packet'],
                    'security_review' => ['security_reviewer_agent', ['git_workspace', 'code_intelligence_index'], 'security_review_report'],
                    'dependency_impact_map' => ['code_intelligence_agent', ['git_workspace', 'code_intelligence_index'], 'dependency_impact_map'],
                    'post_release_learning_loop' => ['release_captain_agent', ['ci_status_read_adapter', 'evidence_ledger'], 'post_release_learning_record'],
                ],
                'work_products' => ['architecture_decision_record', 'patch_plan', 'repair_packet', 'release_certification', 'security_review_report'],
                'metrics' => ['test_pass_rate', 'repair_cycle_time', 'release_certification_rate', 'security_findings_closed'],
                'cadences' => ['per_patch_repair_loop', 'daily_ci_certification', 'weekly_security_review'],
                'dashboards' => ['delivery_flow_board', 'quality_gate_board', 'release_risk_board'],
                'review_queue' => 'engineering_review_queue',
            ],
            'research' => [
                'agent_roles' => ['principal_researcher_agent', 'source_intelligence_agent', 'fact_check_agent', 'synthesis_editor_agent'],
                'connectors' => ['web_search', 'paper_pdf_parser', 'source_registry', 'citation_graph', 'evidence_bridge'],
                'reference_patterns' => ['source_linked_claim_verification', 'citation_graph_review', 'contradiction_adjudication'],
                'flow_specs' => [
                    'deep_research_brief' => ['principal_researcher_agent', ['web_search', 'source_registry'], 'source_linked_research_brief'],
                    'citation_graph_review' => ['source_intelligence_agent', ['citation_graph', 'paper_pdf_parser'], 'citation_health_report'],
                    'contradiction_adjudication' => ['fact_check_agent', ['source_registry', 'evidence_bridge'], 'contradiction_decision_packet'],
                    'executive_synthesis' => ['synthesis_editor_agent', ['evidence_bridge'], 'operator_ready_research_memo'],
                    'source_watchlist_monitor' => ['source_intelligence_agent', ['web_search', 'source_registry'], 'source_watchlist_delta'],
                    'primary_source_gap_review' => ['fact_check_agent', ['source_registry', 'citation_graph'], 'primary_source_gap_report'],
                ],
                'work_products' => ['research_brief', 'source_quality_report', 'citation_graph', 'contradiction_packet', 'executive_synthesis'],
                'metrics' => ['primary_source_ratio', 'citation_health', 'claim_support_rate', 'contradiction_resolution_rate'],
                'cadences' => ['daily_source_watch', 'per_claim_fact_check', 'weekly_research_synthesis'],
                'dashboards' => ['source_quality_board', 'claims_and_contradictions_board', 'research_pipeline_board'],
                'review_queue' => 'research_editorial_queue',
            ],
            'strategy' => [
                'agent_roles' => ['venture_partner_agent', 'market_mapper_agent', 'gtm_operator_agent', 'experiment_allocator_agent'],
                'connectors' => ['research_handoff', 'finance_model_handoff', 'market_dataset_read_adapter', 'competitive_intelligence_read_adapter', 'experiment_registry'],
                'reference_patterns' => ['portfolio_thesis_loop', 'experiment_allocator_committee', 'assumption_ledger'],
                'flow_specs' => [
                    'venture_thesis' => ['venture_partner_agent', ['research_handoff', 'market_dataset_read_adapter'], 'venture_thesis_memo'],
                    'market_map' => ['market_mapper_agent', ['research_handoff', 'competitive_intelligence_read_adapter'], 'tam_sam_som_market_map'],
                    'gtm_system_design' => ['gtm_operator_agent', ['experiment_registry'], 'gtm_operating_plan'],
                    'portfolio_experiment_review' => ['experiment_allocator_agent', ['finance_model_handoff', 'experiment_registry'], 'experiment_allocation_packet'],
                    'competitive_wargame' => ['market_mapper_agent', ['research_handoff', 'market_dataset_read_adapter', 'competitive_intelligence_read_adapter'], 'competitive_wargame_memo'],
                    'board_decision_dossier' => ['venture_partner_agent', ['research_handoff', 'finance_model_handoff'], 'board_decision_dossier'],
                ],
                'work_products' => ['venture_thesis', 'market_map', 'gtm_operating_plan', 'experiment_allocation_packet', 'board_strategy_memo'],
                'metrics' => ['assumption_coverage', 'experiment_velocity', 'opportunity_quality_score', 'capital_efficiency_estimate'],
                'cadences' => ['weekly_opportunity_committee', 'per_experiment_review', 'monthly_strategy_board'],
                'dashboards' => ['opportunity_pipeline_board', 'experiment_portfolio_board', 'strategy_decision_log'],
                'review_queue' => 'strategy_committee_queue',
            ],
            'finance' => [
                'agent_roles' => array_column((array) $this->finance->packet('AAPL')['agent_desk'], 'role'),
                'connectors' => array_column((array) $this->finance->packet('AAPL')['connectors'], 'id'),
                'reference_patterns' => ['unified_financial_data_interface', 'source_linked_claim_verification', 'financial_modeling_audit_trail', 'portfolio_monitoring', 'compliance_automation', 'data_room_due_diligence'],
                'flow_specs' => collect((array) $this->finance->packet('AAPL')['flows'])
                    ->mapWithKeys(static fn (array $flow): array => [
                        (string) $flow['id'] => [
                            (string) $flow['agent_role'],
                            (array) $flow['connectors'],
                            (string) $flow['output'],
                        ],
                    ])
                    ->all(),
                'work_products' => (array) $this->finance->packet('AAPL')['work_products'],
                'metrics' => (array) $this->finance->packet('AAPL')['metrics'],
                'cadences' => (array) $this->finance->packet('AAPL')['operating_cadences'],
                'dashboards' => ['portfolio_monitoring_board', 'model_risk_board', 'investment_committee_board'],
                'review_queue' => 'investment_committee_queue',
            ],
            'marketing' => [
                'agent_roles' => ['growth_lead_agent', 'brand_strategy_agent', 'creative_director_agent', 'analytics_agent', 'lifecycle_agent'],
                'connectors' => ['analytics_read_adapter', 'crm_read_adapter', 'content_repository', 'approval_gate', 'experiment_registry'],
                'reference_patterns' => ['crew_planning_reasoning_tools_memory', 'growth_experiment_registry', 'approval_gated_campaign_ops'],
                'flow_specs' => [
                    'growth_strategy' => ['growth_lead_agent', ['analytics_read_adapter', 'crm_read_adapter'], 'growth_strategy_packet'],
                    'brand_positioning_system' => ['brand_strategy_agent', ['research_handoff', 'content_repository'], 'positioning_system'],
                    'creative_production_brief' => ['creative_director_agent', ['content_repository', 'approval_gate'], 'creative_brief_and_copy_pack'],
                    'funnel_experiment_loop' => ['analytics_agent', ['analytics_read_adapter', 'experiment_registry'], 'funnel_experiment_readout'],
                    'lifecycle_campaign_system' => ['lifecycle_agent', ['crm_read_adapter', 'approval_gate'], 'lifecycle_campaign_plan'],
                    'channel_mix_review' => ['growth_lead_agent', ['analytics_read_adapter', 'experiment_registry'], 'channel_mix_reallocation_proposal'],
                    'voice_of_customer_synthesis' => ['brand_strategy_agent', ['crm_read_adapter', 'content_repository'], 'voc_synthesis_packet'],
                ],
                'work_products' => ['growth_strategy_packet', 'positioning_system', 'creative_brief', 'copy_pack', 'funnel_experiment_readout', 'lifecycle_campaign_plan'],
                'metrics' => ['activation_rate', 'conversion_rate', 'creative_throughput', 'experiment_velocity', 'approval_latency'],
                'cadences' => ['weekly_growth_review', 'per_campaign_approval', 'monthly_positioning_review'],
                'dashboards' => ['growth_dashboard', 'creative_pipeline_board', 'campaign_approval_board'],
                'review_queue' => 'marketing_approval_queue',
            ],
            'cyber' => [
                'agent_roles' => ['security_architect_agent', 'appsec_triage_agent', 'grc_lead_agent', 'detection_engineer_agent', 'remediation_manager_agent'],
                'connectors' => ['sbom_read_adapter', 'repo_read_adapter', 'vulnerability_feed_read_adapter', 'evidence_chain', 'ticketing_proposal_adapter'],
                'reference_patterns' => ['stateful_incident_response_graph', 'human_in_loop_remediation_approval', 'scope_and_roe_gate'],
                'flow_specs' => [
                    'security_posture_review' => ['security_architect_agent', ['sbom_read_adapter', 'vulnerability_feed_read_adapter'], 'security_posture_report'],
                    'appsec_triage' => ['appsec_triage_agent', ['repo_read_adapter', 'evidence_chain'], 'finding_triage_packet'],
                    'grc_obligation_map' => ['grc_lead_agent', ['evidence_chain'], 'grc_control_mapping'],
                    'detection_authoring_review' => ['detection_engineer_agent', ['vulnerability_feed_read_adapter'], 'detection_rule_proposal'],
                    'remediation_program' => ['remediation_manager_agent', ['ticketing_proposal_adapter', 'evidence_chain'], 'remediation_program_plan'],
                    'incident_response_readiness' => ['security_architect_agent', ['evidence_chain', 'vulnerability_feed_read_adapter'], 'incident_response_readiness_packet'],
                    'attack_surface_delta_review' => ['appsec_triage_agent', ['repo_read_adapter', 'sbom_read_adapter'], 'attack_surface_delta_report'],
                ],
                'work_products' => ['security_posture_report', 'finding_triage_packet', 'grc_control_mapping', 'detection_rule_proposal', 'remediation_program_plan'],
                'metrics' => ['critical_finding_count', 'mttr', 'control_coverage', 'remediation_sla_risk', 'scope_block_rate'],
                'cadences' => ['daily_security_posture_scan', 'per_finding_triage', 'monthly_grc_review'],
                'dashboards' => ['security_posture_board', 'remediation_board', 'grc_controls_board'],
                'review_queue' => 'security_review_queue',
            ],
            'automation' => [
                'agent_roles' => ['automation_architect_agent', 'tool_scout_agent', 'browser_workflow_agent', 'api_workflow_agent', 'tool_reliability_agent'],
                'connectors' => ['tool_registry', 'browser_planning_adapter', 'api_schema_read_adapter', 'repo_read_adapter', 'execution_receipt_ledger'],
                'reference_patterns' => ['agent_tool_selection_benchmark', 'mcp_tool_surface', 'durable_automation_receipts'],
                'flow_specs' => [
                    'automation_opportunity_intake' => ['automation_architect_agent', ['tool_registry'], 'automation_opportunity_pack'],
                    'tool_selection_benchmark' => ['tool_scout_agent', ['tool_registry', 'repo_read_adapter'], 'tool_selection_scorecard'],
                    'browser_workflow_design' => ['browser_workflow_agent', ['browser_planning_adapter'], 'browser_workflow_runbook'],
                    'api_workflow_design' => ['api_workflow_agent', ['api_schema_read_adapter'], 'api_workflow_runbook'],
                    'tool_reliability_loop' => ['tool_reliability_agent', ['execution_receipt_ledger'], 'tool_reliability_report'],
                    'mcp_adapter_design' => ['api_workflow_agent', ['api_schema_read_adapter', 'tool_registry'], 'mcp_adapter_design_packet'],
                    'automation_replay_review' => ['tool_reliability_agent', ['execution_receipt_ledger'], 'automation_replay_review'],
                ],
                'work_products' => ['automation_opportunity_pack', 'tool_selection_scorecard', 'browser_workflow_runbook', 'api_workflow_runbook', 'tool_reliability_report'],
                'metrics' => ['automation_roi_score', 'blocked_action_rate', 'tool_success_rate', 'manual_time_saved_estimate', 'reliability_regression_count'],
                'cadences' => ['daily_tool_health_review', 'per_automation_receipt_review', 'weekly_automation_portfolio_review'],
                'dashboards' => ['automation_portfolio_board', 'tool_reliability_board', 'risk_block_board'],
                'review_queue' => 'automation_governance_queue',
            ],
            'personal_development' => [
                'agent_roles' => ['executive_coach_agent', 'learning_designer_agent', 'habit_system_agent', 'reflection_analyst_agent', 'privacy_guardian_agent'],
                'connectors' => ['private_memory_surface', 'goal_registry', 'habit_log_read_adapter', 'learning_resource_registry', 'human_review_packet'],
                'reference_patterns' => ['persistent_learner_model', 'privacy_first_memory_review', 'deliberate_practice_loop'],
                'flow_specs' => [
                    'life_operating_review' => ['executive_coach_agent', ['private_memory_surface', 'goal_registry'], 'life_operating_review'],
                    'learning_curriculum_design' => ['learning_designer_agent', ['learning_resource_registry'], 'learning_curriculum'],
                    'habit_system_iteration' => ['habit_system_agent', ['habit_log_read_adapter'], 'habit_system_plan'],
                    'reflection_synthesis' => ['reflection_analyst_agent', ['private_memory_surface'], 'reflection_synthesis'],
                    'privacy_safe_memory_review' => ['privacy_guardian_agent', ['human_review_packet'], 'privacy_review_packet'],
                    'skill_gap_diagnosis' => ['learning_designer_agent', ['goal_registry', 'learning_resource_registry'], 'skill_gap_diagnosis'],
                    'practice_session_review' => ['habit_system_agent', ['habit_log_read_adapter'], 'practice_session_review'],
                ],
                'work_products' => ['life_operating_review', 'learning_curriculum', 'habit_system_plan', 'reflection_synthesis', 'privacy_review_packet'],
                'metrics' => ['goal_progress_rate', 'habit_adherence', 'learning_session_count', 'privacy_review_pass_rate'],
                'cadences' => ['daily_reflection', 'weekly_goal_review', 'monthly_learning_review'],
                'dashboards' => ['goal_progress_board', 'habit_system_board', 'learning_pipeline_board'],
                'review_queue' => 'personal_review_queue',
            ],
            'operations' => [
                'agent_roles' => ['sre_lead_agent', 'incident_commander_agent', 'runbook_engineer_agent', 'capacity_planner_agent', 'postmortem_editor_agent'],
                'connectors' => ['log_read_adapter', 'metrics_read_adapter', 'alert_read_adapter', 'runbook_repository', 'evidence_ledger'],
                'reference_patterns' => ['stateful_incident_response_graph', 'durable_human_approval_checkpoint', 'slo_runbook_feedback_loop'],
                'flow_specs' => [
                    'operational_readiness_review' => ['sre_lead_agent', ['metrics_read_adapter', 'runbook_repository'], 'readiness_review'],
                    'incident_command_packet' => ['incident_commander_agent', ['alert_read_adapter', 'log_read_adapter'], 'incident_command_packet'],
                    'runbook_system_update' => ['runbook_engineer_agent', ['runbook_repository', 'evidence_ledger'], 'runbook_update_packet'],
                    'capacity_and_slo_review' => ['capacity_planner_agent', ['metrics_read_adapter'], 'capacity_slo_review'],
                    'postmortem_action_loop' => ['postmortem_editor_agent', ['evidence_ledger'], 'postmortem_action_plan'],
                    'alert_noise_reduction' => ['sre_lead_agent', ['alert_read_adapter', 'metrics_read_adapter'], 'alert_noise_reduction_plan'],
                    'change_readiness_review' => ['incident_commander_agent', ['runbook_repository', 'evidence_ledger'], 'change_readiness_review'],
                ],
                'work_products' => ['readiness_review', 'incident_command_packet', 'runbook_update_packet', 'capacity_slo_review', 'postmortem_action_plan'],
                'metrics' => ['slo_breach_count', 'mttr', 'runbook_coverage', 'alert_noise_rate', 'postmortem_action_completion'],
                'cadences' => ['daily_readiness_review', 'per_incident_command', 'weekly_slo_review'],
                'dashboards' => ['ops_readiness_board', 'incident_board', 'slo_capacity_board'],
                'review_queue' => 'operations_review_queue',
            ],
        ];
    }
}
