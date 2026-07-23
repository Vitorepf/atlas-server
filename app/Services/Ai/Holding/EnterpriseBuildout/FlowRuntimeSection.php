<?php

namespace App\Services\Ai\Holding\EnterpriseBuildout;

/**
 * Method-family section extracted verbatim from AutonomousHoldingEnterpriseBuildoutService (GOD-DEBULK 2026-07-22).
 */
class FlowRuntimeSection
{
    public function __construct(
        private readonly EnterpriseBuildoutSupport $support,
    ) {}

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function flows(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'id' => $id,
                'domain_id' => $domainId,
                'agent_role' => (string) $spec[0],
                'connectors' => (array) $spec[1],
                'output' => (string) $spec[2],
                'execution_model' => 'durable_graph_with_handoff_and_review_checkpoint',
                'playbook_step_count' => count($this->playbookSteps($domainId, $id, $spec)),
                'required_gates' => [
                    'source_links_required',
                    'evidence_attached',
                    'policy_checked',
                    'durable_state_checkpoint',
                    'operator_review_for_external_action',
                ],
                'external_side_effects' => false,
                'flow_hash' => hash('sha256', $domainId.'|'.$id.'|'.(string) $spec[0]),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function flowPlaybooks(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'flow_id' => $id,
                'domain_id' => $domainId,
                'owner_agent' => (string) $spec[0],
                'state_model' => 'checkpointed_graph_run',
                'steps' => $this->playbookSteps($domainId, $id, $spec),
                'human_review_checkpoint' => [
                    'required_before_external_action' => true,
                    'approval_packet' => $domainId.'.'.$id.'.operator_review_packet',
                    'resume_mode' => 'resume_from_signed_checkpoint',
                ],
                'rollback' => [
                    'mode' => 'proposal_or_internal_state_revert',
                    'evidence_required' => ['before_state_hash', 'after_state_hash', 'review_receipt_hash'],
                ],
                'playbook_hash' => hash('sha256', $domainId.'|'.$id.'|playbook|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function flowExecutionContracts(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'schema' => 'atlas.ai.company.flow_execution_contract.v1',
                'flow_id' => $id,
                'domain_id' => $domainId,
                'owner_agent' => (string) $spec[0],
                'input_contract' => [
                    'required' => ['objective', 'scope', 'evidence_refs', 'policy_profile'],
                    'optional' => ['context_refs', 'constraints', 'operator_preferences'],
                    'reject_when_missing' => true,
                ],
                'output_contract' => [
                    'primary_artifact' => (string) $spec[2],
                    'required_sections' => ['summary', 'evidence', 'assumptions', 'risks', 'decision_options', 'next_actions'],
                    'receipt_required' => true,
                    'source_links_required' => true,
                ],
                'budget_envelope' => [
                    'max_runtime_seconds' => 120,
                    'max_internal_tool_calls' => 12,
                    'external_spend_allowed' => false,
                    'external_side_effects_allowed' => false,
                ],
                'evaluation_rubric' => [
                    'evidence_quality' => 0.25,
                    'domain_specificity' => 0.20,
                    'risk_coverage' => 0.20,
                    'actionability' => 0.20,
                    'policy_compliance' => 0.15,
                ],
                'benchmark_hook' => [
                    'suite' => $domainId.'.'.$id.'.enterprise_flow_benchmark',
                    'mode' => 'internal_fixture_and_rivals_ready',
                    'minimum_score' => 0.86,
                    'benchmark_hash' => hash('sha256', $domainId.'|'.$id.'|benchmark_hook'),
                ],
                'promotion_gate' => [
                    'requires_green_evaluation' => true,
                    'requires_no_policy_findings' => true,
                    'requires_operator_review_for_external_action' => true,
                    'promotion_hash' => hash('sha256', $domainId.'|'.$id.'|promotion_gate'),
                ],
                'contract_hash' => hash('sha256', $domainId.'|'.$id.'|flow_execution_contract|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    public function flowRuntimeBlueprints(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'schema' => 'atlas.ai.company.flow_runtime_blueprint.v1',
                'company_id' => $domainId,
                'flow_id' => $id,
                'owner_agent' => (string) $spec[0],
                'runtime_model' => [
                    'engine' => 'durable_checkpointed_graph',
                    'state_store' => 'company_scoped_flow_state',
                    'queue' => $domainId.'.'.$id.'.runtime_queue',
                    'dead_letter_queue' => $domainId.'.'.$id.'.dlq',
                    'idempotency_key_required' => true,
                    'resume_token_required' => true,
                ],
                'state_machine' => [
                    'states' => [
                        'received',
                        'validated',
                        'context_loaded',
                        'planned',
                        'tooling_selected',
                        'executing',
                        'critic_review',
                        'operator_checkpoint',
                        'published_internal',
                        'handoff_or_done',
                        'blocked',
                        'failed',
                    ],
                    'terminal_states' => ['handoff_or_done', 'blocked', 'failed'],
                    'failure_state' => 'failed',
                    'pause_state' => 'operator_checkpoint',
                ],
                'tool_permission_matrix' => array_values(array_map(
                    static fn (string $connector): array => [
                        'connector_id' => $connector,
                        'allowed_modes' => ['read', 'analyze', 'propose'],
                        'blocked_modes' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete'],
                        'requires_receipt' => true,
                        'requires_operator_mandate_for_blocked_mode' => true,
                        'permission_hash' => hash('sha256', 'flow_tool_permission|'.$connector),
                    ],
                    (array) $spec[1],
                )),
                'retry_and_recovery' => [
                    'max_attempts' => 2,
                    'retryable_failures' => ['provider_timeout', 'read_adapter_unavailable', 'transient_parse_error'],
                    'non_retryable_failures' => ['policy_violation', 'missing_required_evidence', 'external_action_without_mandate'],
                    'recovery_path' => 'resume_from_last_green_checkpoint_or_emit_blocked_packet',
                    'manual_fallback' => 'operator_review_packet',
                ],
                'runtime_observability' => [
                    'required_events' => [
                        'flow_received',
                        'state_transition',
                        'tool_call_receipt',
                        'handoff_attempt',
                        'critic_score',
                        'policy_gate_result',
                        'operator_checkpoint_result',
                    ],
                    'required_dimensions' => ['company_id', 'flow_id', 'agent_role', 'connector_id', 'cost_uusd', 'latency_ms', 'quality_score'],
                    'trace_required' => true,
                    'receipt_hash_required' => true,
                ],
                'schema_contracts' => [
                    'input_schema' => $domainId.'.'.$id.'.input.v1',
                    'output_schema' => $domainId.'.'.$id.'.output.v1',
                    'event_schema' => $domainId.'.'.$id.'.runtime_event.v1',
                    'handoff_schema' => $domainId.'.'.$id.'.handoff.v1',
                ],
                'promotion_controls' => [
                    'shadow_mode_requires' => ['green_eval_suite', 'green_read_only_probe', 'observability_complete'],
                    'supervised_production_requires' => ['operator_mandate', 'rollback_plan', 'incident_runbook', 'cost_budget'],
                    'external_side_effects_default' => false,
                ],
                'runtime_hash' => hash('sha256', $domainId.'|flow_runtime_blueprint|'.$id.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<int,mixed> $spec
     * @return list<array<string,mixed>>
     */
    public function playbookSteps(string $domainId, string $flowId, array $spec): array
    {
        $connectors = array_values((array) ($spec[1] ?? []));
        $agent = (string) ($spec[0] ?? 'company_operator');
        $output = (string) ($spec[2] ?? 'review_packet');

        return [
            $this->playbookStep($domainId, $flowId, 1, 'intake_and_scope', $agent, [], 'scope_packet'),
            $this->playbookStep($domainId, $flowId, 2, 'retrieve_context', $agent, $connectors, 'context_bundle'),
            $this->playbookStep($domainId, $flowId, 3, 'plan_and_assign', $agent, ['agent_collaboration_model'], 'task_graph'),
            $this->playbookStep($domainId, $flowId, 4, 'execute_internal_analysis', $agent, $connectors, $output),
            $this->playbookStep($domainId, $flowId, 5, 'critic_review', 'independent_reviewer_agent', ['evidence_ledger'], 'review_findings'),
            $this->playbookStep($domainId, $flowId, 6, 'operator_checkpoint', 'operator_approval_gate', ['decision_receipt'], 'signed_review_packet'),
            $this->playbookStep($domainId, $flowId, 7, 'publish_internal_packet', $agent, ['evidence_ledger'], $output),
        ];
    }

    /**
     * @param list<string> $connectors
     * @return array<string,mixed>
     */
    public function playbookStep(
        string $domainId,
        string $flowId,
        int $sequence,
        string $step,
        string $agent,
        array $connectors,
        string $output,
    ): array {
        return [
            'sequence' => $sequence,
            'step' => $step,
            'agent_role' => $agent,
            'connectors' => $connectors,
            'output' => $output,
            'required_evidence' => ['state_hash', 'source_or_input_refs', 'step_receipt_hash'],
            'external_side_effects' => false,
            'step_hash' => hash('sha256', $domainId.'|'.$flowId.'|'.$sequence.'|'.$step.'|'.$agent),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowOrchestrationRunbookStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $referencePatterns = array_values((array) $blueprint['reference_patterns']);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_orchestration_runbook_stack.v1',
            'company_id' => $domainId,
            'orchestration_policy' => [
                'mode' => 'supervised_enterprise_agentic_flow_runtime',
                'pattern_basis' => [
                    'source_linked_financial_services_connector_model',
                    'specialist_agents_tools_handoffs_guardrails_tracing',
                    'durable_checkpointed_graph_with_human_interrupt',
                    'crew_or_flow_decomposition_for_complex_domain_work',
                ],
                'external_side_effects_default' => false,
                'operator_checkpoint_required_for_external_action' => true,
                'run_without_decision_receipt_allowed' => false,
                'claim_autonomous_operation_without_observed_runs_allowed' => false,
            ],
            'flow_runbooks' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_orchestration_runbook.v1',
                    'company_id' => $domainId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'reference_patterns' => $referencePatterns,
                    'intake_packet' => [
                        'required_fields' => ['objective', 'scope', 'stakeholder', 'policy_profile', 'evidence_refs', 'acceptance_criteria'],
                        'normalization_steps' => ['deduplicate_context', 'classify_risk', 'bind_company_memory_scope', 'compute_input_hash'],
                        'reject_when' => ['missing_policy_profile', 'missing_evidence_refs', 'ambiguous_acceptance_criteria'],
                    ],
                    'agent_graph' => [
                        'planner' => (string) $spec[0],
                        'specialists' => array_values(array_diff((array) $blueprint['agent_roles'], [(string) $spec[0]])),
                        'critic' => 'independent_reviewer_agent',
                        'operator_gate' => 'operator_approval_gate',
                        'handoff_packet_required' => true,
                    ],
                    'tool_plan' => array_values(array_map(
                        static fn (string $connector): array => [
                            'connector_id' => $connector,
                            'mode' => 'read_analyze_propose',
                            'receipt_required' => true,
                            'credential_scope' => 'least_privilege_vault_reference',
                            'blocked_without_operator_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete'],
                            'tool_hash' => hash('sha256', 'enterprise_flow_tool_plan|'.$connector),
                        ],
                        (array) $spec[1],
                    )),
                    'checkpoint_lattice' => [
                        'checkpoints' => ['intake', 'context_loaded', 'plan_accepted', 'tool_receipts_captured', 'critic_reviewed', 'operator_checkpointed', 'handoff_or_done'],
                        'resume_strategy' => 'resume_from_last_green_checkpoint_with_state_hash',
                        'interrupt_strategy' => 'pause_emit_operator_packet_and_preserve_run_state',
                        'rollback_strategy' => 'revert_internal_state_or_emit_compensation_plan',
                    ],
                    'evaluation_and_acceptance' => [
                        'quality_floor' => 0.86,
                        'policy_findings_allowed' => 0,
                        'required_scores' => ['evidence_quality', 'domain_specificity', 'risk_coverage', 'actionability', 'handoff_quality'],
                        'acceptance_artifacts' => ['output_artifact', 'critic_review', 'policy_gate_result', 'tool_receipts', 'receipt_hash'],
                    ],
                    'handoff_and_delivery' => [
                        'output_artifact' => (string) $spec[2],
                        'handoff_schema' => $domainId.'.'.$flowId.'.enterprise_handoff.v1',
                        'delivery_modes' => ['internal_packet', 'cross_company_handoff', 'operator_review_queue'],
                        'external_delivery_requires_operator_approval' => true,
                    ],
                    'observability_contract' => [
                        'trace_spans' => ['intake', 'plan', 'retrieve', 'tool_call', 'analysis', 'critic', 'operator_checkpoint', 'delivery'],
                        'metrics' => ['run_success_rate', 'quality_score', 'policy_finding_count', 'tool_receipt_coverage', 'checkpoint_resume_rate', 'handoff_acceptance_rate'],
                        'event_schema' => $domainId.'.'.$flowId.'.enterprise_orchestration_event.v1',
                    ],
                    'runbook_hash' => hash('sha256', $domainId.'|enterprise_flow_orchestration_runbook|'.$flowId.'|'.json_encode($spec)),
                ],
                array_keys($flowSpecs),
                $flowSpecs,
            )),
            'shared_connector_backplane' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'backplane_role' => 'shared_company_connector_with_receipts_and_rate_limits',
                    'health_probe_required_before_run' => true,
                    'receipt_export_required' => true,
                    'rate_limit_policy' => 'queue_or_degrade_to_manual_import_before_failure',
                    'backplane_hash' => hash('sha256', 'enterprise_flow_connector_backplane|'.$connector),
                ],
                $connectors,
            )),
            'runbook_observability' => [
                'required_metrics' => ['runbook_coverage', 'tool_receipt_coverage', 'checkpoint_resume_rate', 'operator_interrupt_rate', 'handoff_acceptance_rate', 'policy_findings'],
                'dashboard' => $domainId.'_enterprise_flow_orchestration_board',
                'alert_on' => ['missing_receipt', 'checkpoint_resume_failed', 'policy_finding', 'handoff_rejected'],
            ],
            'orchestration_runbook_hash' => hash('sha256', $domainId.'|enterprise_flow_orchestration_runbook_stack|'.implode('|', array_keys($flowSpecs)).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowRuntimeImplementationStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $agents = array_values((array) $blueprint['agent_roles']);
        $sourceCatalog = $this->support->flowRuntimeImplementationSourceCatalog();

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_runtime_implementation_stack.v1',
            'company_id' => $domainId,
            'implementation_policy' => [
                'mode' => 'contract_to_shadow_to_supervised_runtime',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_autonomy_claim' => true,
                'ungoverned_external_side_effects_allowed' => false,
                'operator_checkpoint_required_before_write_publish_spend_trade_deploy_delete' => true,
                'runtime_basis' => [
                    'agent_tools_handoffs_guardrails_tracing',
                    'durable_execution_checkpoint_resume',
                    'stateful_flow_orchestration_with_specialist_crews',
                    'financial_services_source_verification_audit_trail_pattern',
                ],
            ],
            'source_catalog' => $sourceCatalog,
            'executable_flow_packets' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.executable_flow_packet.v1',
                    'company_id' => $domainId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'runtime_adapter' => $domainId.'.'.$flowId.'.runtime_adapter',
                    'input_contract' => [
                        'schema' => $domainId.'.'.$flowId.'.input.v1',
                        'required_fields' => ['objective', 'scope', 'risk_profile', 'context_refs', 'acceptance_criteria', 'idempotency_key'],
                        'normalizers' => ['context_pack_binding', 'policy_profile_resolution', 'connector_scope_resolution', 'input_hash'],
                        'reject_when' => ['missing_acceptance_criteria', 'missing_risk_profile', 'connector_scope_uncertified'],
                    ],
                    'execution_graph' => [
                        'nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                        'durability' => 'checkpoint_after_every_node_and_tool_receipt',
                        'resume_token_required' => true,
                        'idempotency_required' => true,
                        'human_interrupt_points' => ['policy_gate', 'operator_checkpoint', 'external_action_request'],
                    ],
                    'output_contract' => [
                        'schema' => $domainId.'.'.$flowId.'.output.v1',
                        'artifact_type' => (string) $spec[2],
                        'required_sections' => ['executive_summary', 'source_lineage', 'analysis', 'risks', 'recommendation', 'next_actions', 'receipt_hash'],
                        'quality_floor' => 0.88,
                        'policy_findings_allowed' => 0,
                    ],
                    'runtime_state_contract' => [
                        'state_objects' => ['intent_packet', 'context_bundle', 'tool_receipts', 'intermediate_artifacts', 'critic_review', 'policy_gate_result', 'delivery_packet'],
                        'state_hash_required' => true,
                        'replayable_without_external_mutation' => true,
                    ],
                    'packet_hash' => hash('sha256', $domainId.'|executable_flow_packet|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'agent_tool_routing_matrix' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'backup_agents' => array_values(array_diff($agents, [(string) $spec[0]])),
                    'connectors' => array_values((array) $spec[1]),
                    'routing_rules' => ['least_privilege_connector_scope', 'specialist_handoff_before_domain_specific_claim', 'critic_reviews_before_delivery'],
                    'blocked_routes' => ['direct_external_write', 'direct_paid_action', 'direct_customer_visible_commitment', 'direct_live_trade_or_offensive_security'],
                    'routing_hash' => hash('sha256', $domainId.'|agent_tool_routing|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_artifact_io_contracts' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'input_schema' => $flowId.'.request.v1',
                    'output_schema' => $flowId.'.'.(string) $spec[2].'.v1',
                    'receipt_schema' => $flowId.'.receipt.v1',
                    'required_lineage' => ['input_hash', 'source_refs', 'connector_receipts', 'transform_hash', 'critic_hash', 'output_hash'],
                    'redaction_before_provider_payload' => true,
                    'contract_hash' => hash('sha256', 'flow_artifact_io_contract|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'supervision_and_shadow_runtime_gates' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'contract_stage_requires' => ['executable_flow_packet_present', 'artifact_io_contract_present', 'connector_certification_present'],
                    'shadow_stage_requires' => ['offline_replay_green', 'sandbox_probe_green', 'policy_scan_green', 'operator_review_queue_bound'],
                    'supervised_stage_requires' => ['operator_signed_mandate', 'rollback_or_manual_fallback', 'observability_dashboard_green', 'incident_route_defined'],
                    'blocked_until_production_acceptance' => ['autonomy_claim', 'ungoverned_external_side_effects', 'self_promotion_to_production'],
                    'gate_hash' => hash('sha256', 'supervision_shadow_runtime_gate|'.$flowId),
                ],
                $flowIds,
            )),
            'connector_runtime_adapters' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_state' => 'contract_defined_probe_required_before_shadow',
                    'supported_operations' => ['read', 'analyze', 'propose', 'export_receipt'],
                    'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                    'adapter_requirements' => ['openapi_or_mcp_schema', 'vault_reference', 'rate_limit_budget', 'mock_fixture', 'receipt_export'],
                    'adapter_hash' => hash('sha256', 'connector_runtime_adapter|'.$connector),
                ],
                $connectors,
            )),
            'runtime_event_and_outbox_contract' => [
                'event_schema' => $domainId.'.enterprise_flow_runtime_event.v1',
                'outbox_required_for' => ['flow_started', 'checkpoint_committed', 'tool_receipt_captured', 'critic_completed', 'policy_gate_completed', 'operator_checkpoint_requested', 'flow_completed'],
                'dead_letter_queue' => $domainId.'_enterprise_flow_runtime_dlq',
                'replay_key_fields' => ['company_id', 'flow_id', 'idempotency_key', 'state_hash', 'receipt_hash'],
                'event_hash' => hash('sha256', $domainId.'|runtime_event_outbox|'.implode('|', $flowIds)),
            ],
            'implementation_observability' => [
                'required_metrics' => ['flow_packet_coverage', 'runtime_adapter_coverage', 'checkpoint_commit_rate', 'tool_receipt_capture_rate', 'critic_review_pass_rate', 'policy_gate_pass_rate', 'operator_checkpoint_latency', 'shadow_replay_pass_rate'],
                'dashboard' => $domainId.'_enterprise_runtime_implementation_board',
                'alert_on' => ['missing_checkpoint', 'missing_receipt', 'policy_gate_failure', 'connector_scope_uncertified', 'shadow_replay_failure'],
            ],
            'implementation_hash' => hash('sha256', $domainId.'|enterprise_flow_runtime_implementation|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowFixtureSimulationStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $commandBase = $this->support->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_fixture_simulation_stack.v1',
            'company_id' => $domainId,
            'simulation_policy' => [
                'mode' => 'offline_contract_fixture_and_shadow_simulation_before_runtime_use',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_external_autonomy_claim' => true,
                'fixtures_required_before_shadow_mode' => true,
                'external_side_effects_allowed_in_simulation' => false,
                'operator_approval_required_to_promote_fixture_to_shadow' => true,
            ],
            'canonical_flow_fixtures' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.canonical_flow_fixture.v1',
                    'fixture_id' => $domainId.'.'.$flowId.'.fixture.'.($index + 1),
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'input_packet' => [
                        'objective' => 'produce_'.$domainId.'_'.$flowId.'_enterprise_artifact',
                        'scope' => 'offline_fixture_no_external_mutation',
                        'risk_profile' => 'r2_internal_enterprise_review',
                        'context_refs' => ['company_packet', 'domain_source_catalog', 'connector_contracts'],
                        'acceptance_criteria' => ['source_lineage_present', 'tool_receipts_present', 'policy_findings_zero', 'quality_score_at_or_above_floor'],
                    ],
                    'expected_output' => [
                        'artifact_type' => (string) $spec[2],
                        'required_sections' => ['executive_summary', 'evidence', 'analysis', 'risk_review', 'recommendation', 'next_actions'],
                        'minimum_quality_score' => 0.88,
                    ],
                    'fixture_hash' => hash('sha256', $domainId.'|canonical_flow_fixture|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_stub_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'stub_id' => $connector.'.offline_stub.v1',
                    'stub_modes' => ['golden_response', 'empty_response', 'permission_denied', 'rate_limited', 'schema_drift'],
                    'must_emit' => ['connector_receipt_hash', 'latency_ms', 'source_ref_or_stub_ref', 'no_external_mutation_attestation'],
                    'external_side_effects' => false,
                    'stub_hash' => hash('sha256', 'connector_offline_stub|'.$connector),
                ],
                $connectors,
            )),
            'expected_trace_trajectories' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'expected_nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                    'expected_tools' => array_values((array) $spec[1]),
                    'required_trace_fields' => ['flow_id', 'agent_role', 'input_hash', 'tool_call_receipts', 'critic_score', 'policy_findings', 'output_hash'],
                    'must_not_include' => ['external_write_call', 'paid_action_call', 'live_trade_call', 'offensive_security_call'],
                    'trajectory_hash' => hash('sha256', 'expected_trace_trajectory|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'quality_assertion_suites' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'assertion_suite' => $flowId.'.quality_assertions.v1',
                    'assertions' => ['output_schema_valid', 'source_lineage_present', 'tool_receipt_coverage_full', 'critic_review_present', 'policy_findings_zero', 'handoff_packet_valid'],
                    'failure_action' => 'block_shadow_mode_open_flow_fixture_review',
                    'assertion_hash' => hash('sha256', 'flow_quality_assertion_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'failure_injection_cases' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'case_suite' => $flowId.'.failure_injection.v1',
                    'cases' => ['missing_context_ref', 'connector_permission_denied', 'connector_schema_drift', 'conflicting_evidence', 'operator_rejects_checkpoint'],
                    'expected_behavior' => 'fail_closed_preserve_checkpoint_and_emit_review_packet',
                    'must_not_do' => ['invent_source', 'skip_policy_gate', 'mutate_external_system', 'self_approve'],
                    'case_hash' => hash('sha256', 'flow_failure_injection|'.$flowId),
                ],
                $flowIds,
            )),
            'dry_run_command_plan' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'command' => 'php artisan '.$commandBase.' --action=smoke --json',
                    'future_flow_specific_command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                    'expected_exit_code' => 0,
                    'required_output_keys' => ['ok', 'schema', 'status'],
                    'dry_run_hash' => hash('sha256', $domainId.'|flow_dry_run_command|'.$flowId),
                ],
                $flowIds,
            )),
            'simulation_promotion_gates' => [
                'contract_ready_requires' => ['canonical_fixture_present', 'connector_stubs_present', 'expected_trace_present'],
                'shadow_ready_requires' => ['quality_assertions_green', 'failure_injection_green', 'dry_run_green', 'operator_review_accepted'],
                'supervised_ready_requires' => ['connector_certification_green', 'runtime_observability_green', 'rollback_or_manual_fallback_present'],
                'autonomy_claim_requires_current_operational_evidence' => true,
            ],
            'simulation_observability' => [
                'required_metrics' => ['fixture_coverage', 'connector_stub_coverage', 'trace_match_rate', 'quality_assertion_pass_rate', 'failure_injection_pass_rate', 'dry_run_pass_rate'],
                'dashboard' => $domainId.'_enterprise_flow_fixture_simulation_board',
                'alert_on' => ['fixture_missing', 'trace_mismatch', 'quality_assertion_failure', 'failure_injection_regression', 'dry_run_failure'],
            ],
            'simulation_stack_hash' => hash('sha256', $domainId.'|enterprise_flow_fixture_simulation|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowActionRuntimeStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $commandBase = $this->support->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_action_runtime_stack.v1',
            'company_id' => $domainId,
            'action_runtime_policy' => [
                'mode' => 'registered_fixture_to_shadow_action_runtime',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_external_autonomy_claim' => true,
                'flow_specific_actions_required_before_supervised_runtime' => true,
                'unregistered_action_execution_allowed' => false,
                'external_side_effects_allowed_by_default' => false,
                'operator_checkpoint_required_for_external_mutation' => true,
            ],
            'runtime_action_catalog' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.runtime_action_contract.v1',
                    'flow_id' => $flowId,
                    'action' => $flowId,
                    'command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                    'fallback_command' => 'php artisan '.$commandBase.' --action=smoke --json',
                    'handler_contract' => [
                        'entrypoint' => $commandBase,
                        'input_schema' => $domainId.'.'.$flowId.'.input.v1',
                        'output_schema' => $domainId.'.'.$flowId.'.output.v1',
                        'receipt_schema' => $domainId.'.'.$flowId.'.receipt.v1',
                        'idempotency_key_required' => true,
                        'resume_token_required' => true,
                    ],
                    'runtime_phases' => ['validate_input', 'load_context', 'bind_fixture_or_shadow_connector', 'execute_domain_action', 'critic_review', 'policy_gate', 'operator_checkpoint', 'emit_delivery_packet'],
                    'external_side_effects' => false,
                    'action_hash' => hash('sha256', $domainId.'|runtime_action_contract|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'command_adapter_matrix' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'command_base' => $commandBase,
                    'action_argument' => $flowId,
                    'fixture_mode_argument' => '--fixture',
                    'connector_bindings' => array_values((array) $spec[1]),
                    'required_before_handler_invocation' => ['input_schema_valid', 'connector_scope_certified', 'fixture_or_shadow_mode_declared', 'policy_profile_bound'],
                    'blocked_without_operator' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
                    'adapter_hash' => hash('sha256', $domainId.'|command_adapter_matrix|'.$flowId.'|'.implode('|', (array) $spec[1])),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'handler_state_schemas' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'state_schema' => $flowId.'.runtime_state.v1',
                    'required_state_keys' => ['intent_packet', 'context_refs', 'connector_receipts', 'artifact_draft', 'critic_review', 'policy_gate_result', 'operator_checkpoint', 'delivery_packet'],
                    'output_artifact' => (string) $spec[2],
                    'checkpoint_after' => ['validate_input', 'load_context', 'tool_call', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint'],
                    'state_hash_required' => true,
                    'schema_hash' => hash('sha256', 'handler_state_schema|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'runtime_event_emission_plan' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'event_schema' => $flowId.'.runtime_action_event.v1',
                    'required_events' => ['action_requested', 'input_validated', 'context_loaded', 'connector_bound', 'artifact_produced', 'critic_reviewed', 'policy_checked', 'operator_checkpointed', 'action_completed_or_blocked'],
                    'outbox_topic' => $flowId.'.runtime_action_outbox',
                    'dlq_topic' => $flowId.'.runtime_action_dlq',
                    'event_hash' => hash('sha256', 'runtime_event_emission_plan|'.$flowId),
                ],
                $flowIds,
            )),
            'operator_checkpoint_contracts' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'checkpoint_schema' => $flowId.'.operator_checkpoint.v1',
                    'checkpoint_required_for' => ['external_action_request', 'policy_exception', 'low_confidence_critic_score', 'connector_permission_expansion'],
                    'operator_packet_fields' => ['request_summary', 'risk_profile', 'evidence_refs', 'proposed_action', 'rollback_plan', 'receipt_hash'],
                    'auto_approval_allowed' => false,
                    'checkpoint_hash' => hash('sha256', 'operator_checkpoint_contract|'.$flowId),
                ],
                $flowIds,
            )),
            'action_runtime_promotion_gates' => [
                'contract_ready_requires' => ['runtime_action_catalog_complete', 'handler_state_schema_present', 'command_adapter_bound'],
                'fixture_ready_requires' => ['canonical_fixture_green', 'event_emission_plan_green', 'operator_checkpoint_contract_present'],
                'shadow_ready_requires' => ['dry_run_command_green', 'connector_stub_contract_green', 'quality_assertions_green'],
                'supervised_ready_requires' => ['operator_signed_mandate', 'observability_dashboard_green', 'rollback_plan_present'],
                'autonomy_claim_requires_current_operational_evidence' => true,
            ],
            'action_runtime_observability' => [
                'required_metrics' => ['runtime_action_coverage', 'command_adapter_coverage', 'handler_state_schema_coverage', 'runtime_event_emission_rate', 'checkpoint_packet_coverage', 'dry_run_action_pass_rate', 'operator_checkpoint_latency'],
                'dashboard' => $domainId.'_enterprise_flow_action_runtime_board',
                'alert_on' => ['unregistered_action_request', 'missing_state_hash', 'event_outbox_failure', 'operator_checkpoint_missing', 'dry_run_action_failure'],
            ],
            'action_runtime_hash' => hash('sha256', $domainId.'|enterprise_flow_action_runtime|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowBenchmarkReplayStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $metrics = array_values((array) $blueprint['metrics']);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1',
            'company_id' => $domainId,
            'benchmark_policy' => [
                'mode' => 'offline_eval_before_shadow_online_eval_after_observed_runs',
                'basis' => [
                    'trace_grading_for_tool_handoff_guardrail_and_routing_regressions',
                    'curated_datasets_for_repeatable_offline_evals',
                    'production_trace_monitoring_for_online_quality_without_reference_outputs',
                    'isolated_benchmark_runs_with_logs_telemetry_and_custom_metrics',
                    'stateful_task_assertions_for_procedural_enterprise_work',
                ],
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
            ],
            'source_catalog' => $this->benchmarkSourceCatalog(),
            'offline_dataset_contracts' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'dataset_id' => $domainId.'.'.$flowId.'.golden_dataset.v1',
                    'minimum_examples' => 10,
                    'example_types' => ['happy_path', 'edge_case', 'ambiguous_request', 'missing_evidence', 'policy_boundary'],
                    'required_fields' => ['input', 'reference_output_or_assertions', 'metadata', 'risk_profile', 'expected_tool_trajectory'],
                    'reference_output_required' => true,
                    'owner_agent' => (string) $spec[0],
                    'dataset_hash' => hash('sha256', $domainId.'|offline_dataset|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'trace_grading_rubrics' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'rubric_id' => $flowId.'.trace_grading_rubric.v1',
                    'graded_trace_components' => ['tool_selection', 'tool_arguments', 'handoff_timing', 'guardrail_result', 'critic_review', 'operator_checkpoint'],
                    'score_keys' => ['task_success', 'trajectory_correctness', 'evidence_quality', 'policy_compliance', 'handoff_quality', 'cost_latency'],
                    'failure_modes' => ['wrong_tool', 'missing_handoff', 'policy_violation', 'unsupported_claim', 'stale_context', 'budget_overrun'],
                    'minimum_score' => 0.86,
                    'rubric_hash' => hash('sha256', 'trace_grading_rubric|'.$flowId),
                ],
                $flowIds,
            )),
            'adversarial_regression_cases' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'case_suite' => $flowId.'.adversarial_regression.v1',
                    'case_types' => ['prompt_injection', 'missing_source', 'conflicting_evidence', 'forbidden_external_action', 'connector_outage', 'handoff_rejection'],
                    'expected_behavior' => 'fail_closed_emit_review_packet_and_preserve_checkpoint',
                    'must_not_do' => ['invent_source', 'skip_policy_gate', 'perform_external_side_effect', 'approve_own_work'],
                    'case_hash' => hash('sha256', 'adversarial_regression|'.$flowId),
                ],
                $flowIds,
            )),
            'deterministic_state_assertions' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'assertion_suite' => $flowId.'.state_assertions.v1',
                    'state_objects' => ['flow_run', 'work_product', 'tool_receipt', 'handoff_packet', 'metric_observation'],
                    'assertions' => [
                        'no_terminal_success_without_receipt_hash',
                        'no_delivery_without_quality_score',
                        'no_cross_company_handoff_without_acceptance_state',
                        'no_external_action_without_operator_mandate',
                    ],
                    'primary_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                    'primary_work_product' => (string) ($workProducts[$index % max(1, count($workProducts))] ?? (string) $spec[2]),
                    'assertion_hash' => hash('sha256', $domainId.'|state_assertions|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'replay_and_comparison_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'replay_modes' => ['baseline_contract_fixture', 'latest_runbook', 'latest_tooling_pattern', 'memory_enabled_variant'],
                    'comparison_dimensions' => ['task_success', 'quality_score', 'policy_findings', 'tool_receipt_coverage', 'latency_ms', 'cost_uusd', 'handoff_acceptance'],
                    'required_artifacts' => ['input_fixture', 'agent_trace', 'tool_receipts', 'grader_feedback', 'diff_report', 'replay_receipt_hash'],
                    'regression_action' => 'block_promotion_open_flow_quality_review_and_attach_replay_diff',
                    'replay_hash' => hash('sha256', 'benchmark_replay|'.$flowId),
                ],
                $flowIds,
            )),
            'promotion_quality_gates' => [
                'minimum_offline_eval_score' => 0.86,
                'minimum_trace_grade_score' => 0.86,
                'policy_findings_allowed' => 0,
                'required_green_replays' => ['baseline_contract_fixture', 'adversarial_regression', 'state_assertions'],
                'operator_review_required_before_shadow_mode' => true,
                'observed_online_runs_required_before_autonomy_claim' => true,
            ],
            'benchmark_observability' => [
                'required_metrics' => ['offline_eval_score', 'trace_grade_score', 'adversarial_pass_rate', 'state_assertion_pass_rate', 'replay_regression_count', 'online_quality_drift'],
                'dashboard' => $domainId.'_enterprise_flow_benchmark_replay_board',
                'alert_on' => ['score_below_floor', 'policy_finding', 'state_assertion_failed', 'replay_regression'],
            ],
            'benchmark_replay_hash' => hash('sha256', $domainId.'|enterprise_flow_benchmark_replay|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function benchmarkSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'openai_agent_evals', 'url' => 'https://developers.openai.com/api/docs/guides/agent-evals', 'pattern' => 'trace_grading_datasets_eval_runs_for_agent_workflows'],
            ['source_id' => 'langsmith_evaluation_concepts', 'url' => 'https://docs.langchain.com/langsmith/evaluation-concepts', 'pattern' => 'offline_online_evaluation_datasets_runs_evaluators_and_feedback'],
            ['source_id' => 'microsoft_autogenbench', 'url' => 'https://microsoft.github.io/autogen/0.2/blog/2024/01/25/AutoGenBench/', 'pattern' => 'isolated_benchmark_runs_logs_telemetry_and_custom_metrics'],
            ['source_id' => 'microsoft_state_bench', 'url' => 'https://opensource.microsoft.com/blog/2026/05/19/introducing-state-bench-a-benchmark-for-ai-agent-memory/', 'pattern' => 'stateful_procedural_enterprise_tasks_with_deterministic_assertions'],
            ['source_id' => 'anthropic_financial_services_benchmark_pattern', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'domain_specific_agent_benchmarks_with_verified_data_connectors_and_audit_trails'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'dataset_contract', 'trace_sample', 'benchmark_run_receipt', 'operator_review'],
                'source_hash' => hash('sha256', 'enterprise_flow_benchmark_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    public function enterpriseFlowOperatingPackages(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $templates = $this->support->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $metrics = array_values((array) $blueprint['metrics']);
        $cadences = array_values((array) $blueprint['cadences']);
        $commandBase = $this->support->commandBase($domainId);
        $sourceBasis = array_values(array_merge(
            $this->support->flowRuntimeImplementationSourceCatalog(),
            $this->support->agentFrameworkSourceCatalog($domainId),
            $this->support->premiumReferenceSourceBasis($domainId),
        ));

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_operating_package_stack.v1',
            'company_id' => $domainId,
            'operating_policy' => [
                'target' => 'target_9_enterprise_company_flow_operability',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'green_contract_fixture_replay_connector_probe_runbook_drill_and_current_operating_packet',
                'package_required_for_every_flow' => true,
                'external_execution_allowed_by_package' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_basis' => array_values(array_map(
                static fn (array $source): array => [
                    'source_id' => (string) ($source['source_id'] ?? 'unknown_source'),
                    'url' => (string) ($source['url'] ?? ''),
                    'pattern' => (string) ($source['pattern'] ?? $source['adopted_pattern'] ?? 'enterprise_agentic_operating_pattern'),
                ],
                $sourceBasis,
            )),
            'flow_packages' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => $this->enterpriseFlowOperatingPackage(
                    $domainId,
                    $flowId,
                    $spec,
                    $index,
                    $templateIds,
                    $connectors,
                    $metrics,
                    $cadences,
                    $commandBase,
                ),
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'portfolio_operations_layer' => [
                'cross_flow_queue' => $domainId.'_enterprise_flow_operations_queue',
                'priority_method' => 'risk_adjusted_value_sla_and_evidence_gap',
                'daily_dispatch_required' => true,
                'weekly_operating_board_required' => true,
                'operator_exception_queue' => (string) $blueprint['review_queue'],
                'blocked_work_policy' => 'blocked_items_keep_receipt_gap_and_next_evidence_action',
            ],
            'package_observability' => [
                'required_metrics' => [
                    'flow_package_coverage',
                    'source_basis_coverage',
                    'workbench_binding_coverage',
                    'replay_case_coverage',
                    'runbook_drill_coverage',
                    'operator_exception_latency',
                    'external_action_block_rate',
                ],
                'dashboard' => $domainId.'_enterprise_flow_operating_package_board',
                'alert_on' => ['missing_package', 'stale_source_basis', 'missing_workbench', 'replay_below_floor', 'runbook_drill_missing'],
            ],
            'package_stack_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_packages|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<int,mixed> $spec
     * @param list<string> $templateIds
     * @param list<string> $connectors
     * @param list<string> $metrics
     * @param list<string> $cadences
     * @return array<string,mixed>
     */
    public function enterpriseFlowOperatingPackage(
        string $domainId,
        string $flowId,
        array $spec,
        int $index,
        array $templateIds,
        array $connectors,
        array $metrics,
        array $cadences,
        string $commandBase,
    ): array {
        $flowConnectors = array_values((array) ($spec[1] ?? []));
        $ownerAgent = (string) ($spec[0] ?? 'company_manager_agent');
        $artifact = (string) ($spec[2] ?? 'enterprise_packet');
        $templateId = (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator');

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_operating_package.v1',
            'package_id' => $domainId.'.'.$flowId.'.enterprise_operating_package.v1',
            'company_id' => $domainId,
            'flow_id' => $flowId,
            'owner_agent' => $ownerAgent,
            'premium_template_id' => $templateId,
            'operating_cell' => [
                'primary_agent' => $ownerAgent,
                'support_agents' => ['independent_reviewer_agent', 'policy_gate_agent', 'operations_coordinator_agent'],
                'cadence' => (string) ($cadences[$index % max(1, count($cadences))] ?? 'weekly_operating_board'),
                'review_queue' => $domainId.'_operator_review_queue',
                'decision_rights' => [
                    'plan_and_analyze' => $ownerAgent,
                    'quality_acceptance' => 'independent_reviewer_agent',
                    'external_action' => 'operator_signed_mandate_only',
                ],
            ],
            'tool_and_data_cell' => [
                'required_connectors' => $flowConnectors,
                'connector_workbenches' => array_values(array_map(
                    static fn (string $connector): array => [
                        'connector_id' => $connector,
                        'workbench_id' => $connector.'.premium_workbench.v1',
                        'access_mode' => 'read_only_or_internal_fixture_until_operator_mandate',
                        'must_emit' => ['schema_snapshot', 'sample_fixture', 'permission_scope_report', 'tool_receipt_hash'],
                        'blocked_capabilities' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_operation'],
                    ],
                    $flowConnectors,
                )),
                'shared_backplane_connectors' => $connectors,
                'data_boundary' => 'company_scoped_redacted_context_with_source_lineage',
            ],
            'runtime_cell' => [
                'command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                'fallback_command' => 'php artisan '.$commandBase.' --action=smoke --json',
                'runtime_nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                'checkpoint_after_every_node' => true,
                'idempotency_key_required' => true,
                'state_hash_required' => true,
                'dead_letter_queue' => $domainId.'.'.$flowId.'.dlq',
                'resume_strategy' => 'resume_from_last_green_checkpoint_with_tool_receipts',
            ],
            'quality_replay_cell' => [
                'dataset_id' => $domainId.'.'.$flowId.'.enterprise_replay_dataset.v1',
                'minimum_cases_before_shadow' => 25,
                'case_mix' => ['happy_path', 'edge_case', 'ambiguous_request', 'missing_evidence', 'conflicting_evidence', 'forbidden_external_action', 'connector_outage'],
                'trace_rubric' => ['task_success', 'trajectory_correctness', 'source_faithfulness', 'policy_compliance', 'handoff_quality', 'cost_latency'],
                'minimum_scores' => [
                    'offline_eval' => 0.9,
                    'trace_grade' => 0.88,
                    'source_faithfulness' => 0.94,
                    'policy_compliance' => 1.0,
                ],
                'replay_modes' => ['contract_fixture', 'adversarial_regression', 'state_assertions', 'latest_runbook', 'memory_enabled_variant'],
                'promotion_without_green_replay_allowed' => false,
            ],
            'delivery_cell' => [
                'artifact_type' => $artifact,
                'required_sections' => ['executive_summary', 'source_lineage', 'analysis', 'risks', 'recommendation', 'next_actions', 'receipt_hash'],
                'quality_gate' => 'critic_score_policy_gate_and_schema_validation_green',
                'external_delivery_requires_operator_mandate' => true,
            ],
            'operations_cell' => [
                'sla_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                'response_sla' => $index % 3 === 0 ? 'same_business_day' : 'next_business_day',
                'rollback_or_compensation_plan_required' => true,
                'incident_route' => ['company_manager_agent', 'independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'runbook_drill_required_before_supervised_mode' => true,
                'post_run_reconciliation_required' => true,
            ],
            'promotion_gates' => [
                'contract_ready' => ['package_present', 'input_output_schema_bound', 'workbench_scope_bound'],
                'fixture_ready' => ['fixture_dataset_25_cases', 'tool_receipts_green', 'policy_boundary_green'],
                'shadow_ready' => ['connector_probe_green', 'replay_score_green', 'runbook_drill_green'],
                'supervised_ready' => ['operator_mandate_present', 'rollback_plan_present', 'observability_dashboard_green'],
                'autonomy_claim_ready' => ['current_operating_packet_green', 'observed_runs_green', 'zero_policy_findings'],
            ],
            'external_execution_allowed' => false,
            'buildout_wait_days_required' => 0,
            'package_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_package|'.$flowId.'|'.$templateId.'|'.implode('|', $flowConnectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    public function enterpriseFlowWorkProductDeliveryStack(string $domainId, array $blueprint, array $workProducts, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $profile = $this->support->domainCompanyExecutionSuiteProfile($domainId);

        $catalog = array_values(array_map(
            static fn (string $workProduct, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_work_product_catalog_item.v1',
                'work_product_id' => $workProduct,
                'artifact_schema_id' => $workProduct.'.enterprise_artifact.v1',
                'versioning_policy' => 'semantic_artifact_version_with_receipt_hash_and_source_lineage',
                'required_sections' => ['executive_summary', 'inputs_and_scope', 'source_lineage', 'analysis_or_execution_trace', 'risk_and_controls', 'recommended_next_action', 'handoff_and_rollback'],
                'acceptance_floor' => [
                    'source_faithfulness' => 0.95,
                    'domain_correctness' => 0.9,
                    'operator_readability' => 0.9,
                    'policy_findings_allowed' => 0,
                ],
                'catalog_order' => $index + 1,
                'external_delivery_allowed' => false,
                'catalog_hash' => hash('sha256', 'enterprise_work_product_catalog|'.$domainId.'|'.$workProduct),
            ],
            $workProducts,
            array_keys($workProducts),
        ));

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1',
            'company_id' => $domainId,
            'delivery_policy' => [
                'mode' => 'source_linked_operator_ready_business_artifact_delivery',
                'calendar_wait_blocker_enabled' => false,
                'external_delivery_allowed' => false,
                'customer_visible_claim_allowed_without_source_and_operator_review' => false,
                'real_customer_send_allowed' => false,
                'operator_acceptance_required_before_external_handoff' => true,
                'artifact_receipt_hash_required' => true,
                'external_side_effects_enabled' => false,
            ],
            'work_product_catalog' => $catalog,
            'flow_delivery_blueprints' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_delivery_blueprint.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'work_product_id' => (string) $spec[2],
                    'artifact_schema_id' => (string) $spec[2].'.enterprise_artifact.v1',
                    'delivery_lane' => (string) (((array) $profile['workbenches'])[$index % max(1, count((array) $profile['workbenches']))] ?? 'enterprise_delivery_lane'),
                    'input_contract' => [
                        'required_inputs' => ['operator_intent', 'company_context', 'source_refs', 'connector_scope', 'policy_profile', 'prior_receipts'],
                        'connector_scope' => array_values((array) $spec[1]),
                        'missing_input_behavior' => 'block_delivery_and_emit_review_packet',
                    ],
                    'artifact_sections' => ['executive_summary', 'decision_or_action_context', 'source_lineage_table', 'domain_analysis', 'risk_controls', 'quality_eval', 'operator_handoff', 'rollback_or_followup'],
                    'production_grade_requirements' => ['typed_schema', 'source_refs', 'receipt_hashes', 'redaction_review', 'domain_reviewer', 'operator_acceptance', 'replay_case_link'],
                    'external_delivery_allowed' => false,
                    'blueprint_hash' => hash('sha256', 'flow_work_product_delivery_blueprint|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_acceptance_contracts' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_acceptance_contract.v1',
                    'flow_id' => $flowId,
                    'acceptance_tests' => ['schema_valid', 'required_sections_present', 'source_lineage_complete', 'domain_reviewer_passed', 'policy_findings_zero', 'replay_reference_passed', 'operator_packet_ready'],
                    'quality_floor' => ['source_faithfulness' => 0.95, 'domain_correctness' => 0.9, 'handoff_clarity' => 0.9, 'risk_control_completeness' => 0.95],
                    'required_reviewers' => ['domain_reviewer', 'risk_or_policy_reviewer', 'operator_acceptance'],
                    'failure_modes' => ['missing_source', 'stale_data', 'connector_unavailable', 'policy_sensitive_output', 'unsupported_claim', 'handoff_rejected'],
                    'auto_accept_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_work_product_acceptance_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_handoff_packets' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_handoff_packet.v1',
                    'flow_id' => $flowId,
                    'work_product_id' => (string) $spec[2],
                    'required_evidence' => ['artifact_hash', 'source_lineage_hash', 'tool_receipt_hashes', 'quality_gate_hash', 'risk_review_hash', 'operator_acceptance_hash', 'rollback_or_followup_plan'],
                    'handoff_targets' => ['company_command_center', 'operator_review_queue', 'portfolio_governance_if_cross_company_dependency'],
                    'external_handoff_mode' => 'manual_operator_supplied_channel_only',
                    'atlas_external_send_allowed' => false,
                    'packet_hash' => hash('sha256', 'flow_work_product_handoff_packet|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_replay_artifact_checks' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_replay_artifact_check.v1',
                    'flow_id' => $flowId,
                    'minimum_replay_cases' => 25,
                    'artifact_regression_checks' => ['section_stability', 'source_link_integrity', 'claim_support_consistency', 'risk_language_consistency', 'operator_handoff_completeness', 'redaction_boundary'],
                    'adversarial_cases' => ['unsupported_claim_request', 'conflicting_source_request', 'external_action_request', 'private_data_request', 'stale_market_or_policy_data'],
                    'promotion_requires_green_replay' => true,
                    'check_hash' => hash('sha256', 'flow_work_product_replay_artifact_check|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'delivery_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['artifact_schema_pass_rate', 'required_section_coverage', 'source_lineage_coverage', 'operator_acceptance_rate', 'handoff_rejection_rate', 'external_delivery_block_rate']
                ))),
                'dashboard' => $domainId.'_flow_work_product_delivery_board',
                'alert_on' => ['missing_required_section', 'source_lineage_gap', 'quality_floor_failed', 'operator_rejection', 'external_delivery_requested'],
            ],
            'delivery_stack_hash' => hash('sha256', $domainId.'|flow_work_product_delivery|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    public function enterpriseFlowLiveReadConnectorProbeStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1',
            'company_id' => $domainId,
            'probe_policy' => [
                'mode' => 'flow_scoped_live_read_or_sandbox_probe_before_external_effect',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'signed_scope_schema_snapshot_sample_payload_permission_report_lineage_and_probe_receipt',
                'live_read_allowed' => true,
                'write_tools_enabled' => false,
                'external_mutation_allowed' => false,
                'credential_material_in_packet_allowed' => false,
                'operator_scope_required_before_live_connector_probe' => true,
                'operator_mandate_required_for_any_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'connector_probe_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.live_read_connector_probe_profile.v1',
                    'connector_id' => $connector,
                    'profile_id' => $connector.'.live_read_probe_profile.v1',
                    'allowed_probe_modes' => ['fixture_probe', 'sandbox_read', 'live_read_only'],
                    'required_scope_artifacts' => ['vault_scope_reference', 'permission_report', 'schema_snapshot', 'sample_payload_hash', 'rate_limit_budget', 'disable_plan'],
                    'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'credential_material_in_packet_allowed' => false,
                    'external_mutation_allowed' => false,
                    'profile_hash' => hash('sha256', 'live_read_connector_probe_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_live_read_probe_contracts' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_live_read_probe_contract.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'work_product_id' => (string) $spec[2],
                    'required_pre_probe_evidence' => ['operator_scope', 'connector_permission_profile', 'schema_snapshot_plan', 'privacy_boundary', 'fallback_fixture', 'rollback_or_disable_plan'],
                    'required_probe_outputs' => ['probe_receipt_hash', 'schema_snapshot_hash', 'sample_payload_hash', 'source_lineage_hash', 'permission_scope_hash', 'redaction_review_hash', 'latency_and_error_budget'],
                    'failure_handling' => ['block_flow_promotion', 'open_review_item', 'fallback_to_fixture', 'record_connector_exception', 'notify_operator_queue'],
                    'minimum_probe_cases' => 12,
                    'external_mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_live_read_probe_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_probe_evidence_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_live_read_probe_evidence_matrix.v1',
                    'flow_id' => $flowId,
                    'required_green_evidence' => ['scope_bound', 'permission_profile_bound', 'schema_snapshot_bound', 'sample_payload_bound', 'lineage_bound', 'redaction_review_bound', 'fallback_fixture_bound', 'probe_receipt_bound'],
                    'promotion_stage_unlocked' => 'shadow_readiness_not_external_write_authority',
                    'promotion_requires_operator_acceptance' => true,
                    'external_execution_authority_granted' => false,
                    'matrix_hash' => hash('sha256', 'flow_live_read_probe_evidence_matrix|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'probe_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['live_read_probe_coverage', 'schema_snapshot_coverage', 'sample_payload_hash_coverage', 'permission_scope_match_rate', 'probe_failure_rate', 'fallback_fixture_use_rate', 'external_mutation_block_rate']
                ))),
                'dashboard' => $domainId.'_flow_live_read_connector_probe_board',
                'alert_on' => ['missing_operator_scope', 'permission_scope_mismatch', 'schema_snapshot_missing', 'probe_failed', 'external_mutation_requested', 'credential_material_detected'],
            ],
            'probe_stack_hash' => hash('sha256', $domainId.'|flow_live_read_connector_probe|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }
}
