<?php

namespace App\Services\Ai\DomainProfiles;

class AssuranceProfileBuilder
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function domains(): array
    {
        return [
            'qa' => [
                'id' => 'qa',
                'label' => 'QA',
                'status' => 'active',
                'default_flow' => 'qa.regression_review',
                'orchestrator' => 'AtlasQaOrchestrator',
                'runtime_family' => 'qa',
                'description' => 'Cross-domain quality assurance for regression review, acceptance review, evidence audit, and release readiness without executing tests or overriding domain gates.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->qaContextPolicy(),
                'memory_policy' => $this->qaMemoryPolicy(),
                'gate_policy' => $this->qaDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=qa', 'api:ai/domains', 'app:qa', 'mcp:open_brain'],
                    'surface_policy' => [
                        'cross_domain_review_only' => true,
                        'programming_qa_executes_code_tests_not_this_domain' => true,
                        'domain_gates_cannot_be_overridden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_qa_findings_feed_memory' => true,
                        'evidence_gaps_feed_self_improvement' => true,
                        'repeated_regressions_feed_domain_gates' => true,
                    ],
                ],
            ],
            'security' => [
                'id' => 'security',
                'label' => 'Security',
                'status' => 'active',
                'default_flow' => 'security.threat_review',
                'orchestrator' => 'AtlasSecurityOrchestrator',
                'runtime_family' => 'security',
                'description' => 'Defensive security review domain for threat review, privacy review, compliance review, and incident review without exploit execution, secret access, or network scanning.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->securityContextPolicy(),
                'memory_policy' => $this->securityMemoryPolicy(),
                'gate_policy' => $this->securityDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=security', 'api:ai/domains', 'app:security', 'mcp:open_brain'],
                    'surface_policy' => [
                        'defensive_review_only' => true,
                        'programming_security_executes_code_security_harness_not_this_domain' => true,
                        'secret_access_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_security_findings_feed_memory' => true,
                        'control_gaps_feed_self_improvement' => true,
                        'incidents_feed_postmortem_review' => true,
                    ],
                ],
            ],
            'operations' => [
                'id' => 'operations',
                'label' => 'Operations',
                'status' => 'active',
                'default_flow' => 'operations.diagnostic',
                'orchestrator' => 'AtlasOperationsOrchestrator',
                'runtime_family' => 'operations',
                'description' => 'Operational diagnostics, runbook planning, incident review, and readiness review without deploying, restarting, deleting data, or mutating infrastructure.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->operationsContextPolicy(),
                'memory_policy' => $this->operationsMemoryPolicy(),
                'gate_policy' => $this->operationsDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=operations', 'api:ai/domains', 'app:operations', 'mcp:open_brain'],
                    'surface_policy' => [
                        'diagnostic_only' => true,
                        'operational_action_requires_separate_receipt' => true,
                        'production_mutation_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_operations_findings_feed_memory' => true,
                        'incident_reviews_feed_self_improvement' => true,
                        'repeated_alerts_feed_runbook_improvement' => true,
                    ],
                ],
            ],
            'background' => [
                'id' => 'background',
                'label' => 'Background Safety',
                'status' => 'active',
                'default_flow' => 'background.safe',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime_family' => 'background',
                'autonomy_default' => 'low',
                'background_allowed' => true,
                'description' => 'Background safety review, readiness, schedule and permission governance for cron/heartbeat/daemon work without starting jobs or changing schedules.',
                'context_policy' => $this->backgroundContextPolicy(),
                'memory_policy' => $this->backgroundMemoryPolicy(),
                'gate_policy' => $this->backgroundDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['scheduler', 'cli:atlas automation', 'api:automations', 'app:atlas engineering', 'mcp:open_brain'],
                    'surface_policy' => [
                        'background_review_only' => true,
                        'explicit_operator_approval_required' => true,
                        'stop_conditions_required' => true,
                    ],
                    'learning_policy' => [
                        'accepted_background_reviews_feed_memory' => true,
                        'repeated_background_risks_feed_self_improvement' => true,
                        'unreviewed_background_actions_never_promote' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function flows(): array
    {
        return [
            ...$this->qaStaticFlows(),
            ...$this->securityStaticFlows(),
            ...$this->operationsStaticFlows(),
            ...$this->backgroundStaticFlows(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function qaStaticFlows(): array
    {
        return [
            'qa.regression_review' => $this->qaFlow('qa.regression_review', 'Regression Review', 'QaRegressionRuntime', ['qa_scope', 'acceptance_criteria', 'risk_review']),
            'qa.acceptance_review' => $this->qaFlow('qa.acceptance_review', 'Acceptance Review', 'QaAcceptanceRuntime', ['acceptance_criteria', 'criteria_coverage', 'human_review_required']),
            'qa.evidence_audit' => $this->qaFlow('qa.evidence_audit', 'Evidence Audit', 'QaEvidenceRuntime', ['evidence_refs', 'traceability_map', 'uncertainty_statement']),
            'qa.release_readiness' => $this->qaFlow('qa.release_readiness', 'Release Readiness', 'QaReleaseRuntime', ['release_risk', 'blocking_findings', 'go_no_go_recommendation']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function qaFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'qa',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasQaOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical QA flow for cross-domain review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->qaContextPolicy(),
            'memory_policy' => $this->qaMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['qa_scope', 'acceptance_criteria', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'qa_packet_runtime',
                'review_only' => true,
                'test_execution_allowed' => false,
                'deploy_allowed' => false,
                'domain_gate_override_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaContextPolicy(): array
    {
        return [
            'preset' => 'cross_domain_qa_context',
            'require_context_pack' => true,
            'sources' => [
                'operation_envelope',
                'decision_receipt',
                'acceptance_criteria',
                'evidence_ledger',
                'domain_gate_results',
                'test_artifacts_when_available',
                'risk_register',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaMemoryPolicy(): array
    {
        return [
            'projection' => 'qa',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_qa_finding', 'verified_regression_pattern', 'release_readiness_review'],
                'requires_review_for' => ['new_quality_rule', 'domain_gate_change', 'release_policy_change'],
                'never_auto_promote_unverified_quality_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaDomainGatePolicy(): array
    {
        return [
            'required' => ['qa_scope', 'acceptance_criteria', 'evidence_refs', 'risk_review', 'human_review_required'],
            'release_requires' => ['blocking_findings', 'go_no_go_recommendation', 'operator_approval'],
            'autonomy_ceiling' => 'review_only',
            'test_execution_allowed' => false,
            'deploy_allowed' => false,
            'domain_gate_override_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function securityStaticFlows(): array
    {
        return [
            'security.threat_review' => $this->securityFlow('security.threat_review', 'Threat Review', 'SecurityThreatRuntime', ['security_scope', 'defensive_only', 'risk_register']),
            'security.privacy_review' => $this->securityFlow('security.privacy_review', 'Privacy Review', 'SecurityPrivacyRuntime', ['data_classification', 'privacy_risk', 'redaction_plan']),
            'security.compliance_review' => $this->securityFlow('security.compliance_review', 'Compliance Review', 'SecurityComplianceRuntime', ['control_mapping', 'evidence_refs', 'human_review_required']),
            'security.incident_review' => $this->securityFlow('security.incident_review', 'Incident Review', 'SecurityIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function securityFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'security',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasSecurityOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Security flow for defensive review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->securityContextPolicy(),
            'memory_policy' => $this->securityMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['security_scope', 'defensive_only', 'human_review_required'],
                'autonomy_ceiling' => 'defensive_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'security_packet_runtime',
                'defensive_review_only' => true,
                'exploit_execution_allowed' => false,
                'network_scan_allowed' => false,
                'secret_access_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityContextPolicy(): array
    {
        return [
            'preset' => 'defensive_security_context',
            'require_context_pack' => true,
            'sources' => [
                'asset_inventory',
                'scope_boundary',
                'control_catalog',
                'threat_model',
                'evidence_ledger',
                'privacy_classification',
                'incident_notes',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityMemoryPolicy(): array
    {
        return [
            'projection' => 'security',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_security_finding', 'verified_control_gap', 'reviewed_incident_pattern'],
                'requires_review_for' => ['new_security_rule', 'privacy_policy_change', 'credential_or_secret_context'],
                'never_auto_promote_secrets_or_unredacted_sensitive_data' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function securityDomainGatePolicy(): array
    {
        return [
            'required' => ['security_scope', 'defensive_only', 'human_review_required'],
            'high_risk_requires' => ['operator_approval', 'evidence_refs', 'redaction_review'],
            'autonomy_ceiling' => 'defensive_review_only',
            'exploit_execution_allowed' => false,
            'network_scan_allowed' => false,
            'secret_access_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function operationsStaticFlows(): array
    {
        return [
            'operations.diagnostic' => $this->operationsFlow('operations.diagnostic', 'Diagnostic', 'OperationsDiagnosticRuntime', ['operations_scope', 'diagnostic_only', 'signal_map']),
            'operations.runbook' => $this->operationsFlow('operations.runbook', 'Runbook', 'OperationsRunbookRuntime', ['runbook_steps', 'prechecks', 'human_review_required']),
            'operations.incident_review' => $this->operationsFlow('operations.incident_review', 'Incident Review', 'OperationsIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
            'operations.readiness_review' => $this->operationsFlow('operations.readiness_review', 'Readiness Review', 'OperationsReadinessRuntime', ['readiness_score', 'evidence_refs', 'operator_decision_needed']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function operationsFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'operations',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasOperationsOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Operations flow for diagnostic review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->operationsContextPolicy(),
            'memory_policy' => $this->operationsMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
                'autonomy_ceiling' => 'diagnostic_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'operations_packet_runtime',
                'diagnostic_only' => true,
                'restart_allowed' => false,
                'deploy_allowed' => false,
                'infrastructure_mutation_allowed' => false,
                'data_deletion_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsContextPolicy(): array
    {
        return [
            'preset' => 'operations_diagnostic_context',
            'require_context_pack' => true,
            'sources' => [
                'system_scope',
                'symptoms',
                'signals',
                'logs_when_provided',
                'evidence_ledger',
                'runbooks',
                'risk_register',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 12000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsMemoryPolicy(): array
    {
        return [
            'projection' => 'operations',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_operations_finding', 'reviewed_incident_pattern', 'runbook_improvement'],
                'requires_review_for' => ['production_runbook_change', 'operational_policy_change', 'critical_incident_pattern'],
                'never_auto_promote_unreviewed_operational_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsDomainGatePolicy(): array
    {
        return [
            'required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
            'operational_action_requires' => ['separate_decision_receipt', 'operator_approval', 'rollback_plan'],
            'autonomy_ceiling' => 'diagnostic_only',
            'restart_allowed' => false,
            'deploy_allowed' => false,
            'infrastructure_mutation_allowed' => false,
            'data_deletion_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function backgroundStaticFlows(): array
    {
        return [
            'background.safe' => $this->backgroundFlow('background.safe', 'Safe Background', 'BackgroundSafetyRuntime', ['background_scope', 'review_only', 'stop_conditions']),
            'background.readiness_review' => $this->backgroundFlow('background.readiness_review', 'Readiness Review', 'BackgroundReadinessRuntime', ['readiness_score', 'missing_controls', 'approval_requirements']),
            'background.schedule_review' => $this->backgroundFlow('background.schedule_review', 'Schedule Review', 'BackgroundScheduleRuntime', ['schedule_declared', 'cadence_review', 'stop_conditions']),
            'background.permission_review' => $this->backgroundFlow('background.permission_review', 'Permission Review', 'BackgroundPermissionRuntime', ['permission_review', 'least_privilege', 'operator_approval_needed']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function backgroundFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'background',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'BackgroundSafetyOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Background Safety flow for review-only background governance.",
            'autonomy' => 'low',
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->backgroundContextPolicy(),
            'memory_policy' => $this->backgroundMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'background_safety_packet_runtime',
                'review_only' => true,
                'start_jobs_allowed' => false,
                'schedule_mutation_allowed' => false,
                'permission_escalation_allowed' => false,
                'unbounded_loop_allowed' => false,
                'required_evidence' => ['background_packet', 'permission_review', 'stop_conditions'],
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['scheduler', 'cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundContextPolicy(): array
    {
        return [
            'preset' => 'background_safety_context',
            'require_context_pack' => true,
            'sources' => [
                'job_scope',
                'trigger',
                'schedule',
                'permissions',
                'stop_conditions',
                'evidence_ledger',
                'automation_history',
                'operator_constraints',
            ],
            'budget' => [
                'max_prompt_tokens' => 10000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundMemoryPolicy(): array
    {
        return [
            'projection' => 'background',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_background_review', 'reviewed_schedule_risk', 'permission_boundary_improvement'],
                'requires_review_for' => ['new_background_policy', 'schedule_change_rule', 'permission_policy_change'],
                'never_auto_promote_unreviewed_background_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backgroundDomainGatePolicy(): array
    {
        return [
            'required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
            'background_execution_requires' => ['separate_decision_receipt', 'operator_approval', 'bounded_schedule', 'stop_conditions'],
            'autonomy_ceiling' => 'review_only',
            'start_jobs_allowed' => false,
            'schedule_mutation_allowed' => false,
            'permission_escalation_allowed' => false,
            'unbounded_loop_allowed' => false,
        ];
    }
}
