<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

/**
 * Atlas Agentic Engineering OS — Department Contract Runtime.
 *
 * PHP-side implementation of `atlas-agentic-engineering-os-department-contract.md`.
 * The AAEOS canon defines 11 canonical departments (product, architect,
 * research, dev, debug, review, qa, security, forge, delivery, memory).
 * This service additionally exposes `executive_intake` as an Atlas-side
 * pre-product router stage. Each entry carries the 12 canonical fields
 * required by the schema `atlas.aaeos.department.v1` so the quality gate
 * `schema-fields-12-present` evaluates green.
 */
final class DepartmentContractRuntime
{
    public const SCHEMA_VERSION = 'atlas.aaeos.department.v1';

    /** Pre-product router stage (Atlas extension above canon 11). */
    public const DEPARTMENT_EXECUTIVE_INTAKE = 'executive_intake';

    public const DEPARTMENT_PRODUCT = 'product';

    /** Canon id is `architect`; legacy alias `architecture` preserved for back-compat. */
    public const DEPARTMENT_ARCHITECTURE = 'architecture';

    public const DEPARTMENT_RESEARCH = 'research';

    public const DEPARTMENT_DEV = 'dev';

    public const DEPARTMENT_DEBUG = 'debug';

    public const DEPARTMENT_REVIEW = 'review';

    public const DEPARTMENT_QA = 'qa';

    public const DEPARTMENT_SECURITY = 'security';

    public const DEPARTMENT_FORGE = 'forge';

    public const DEPARTMENT_DELIVERY = 'delivery';

    public const DEPARTMENT_MEMORY = 'memory';

    /**
     * The 12 canonical fields every department must declare. Used by the
     * `schema-fields-12-present` quality gate.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = [
        'human_name',
        'scope',
        'triggers',
        'inputs',
        'outputs',
        'gates',
        'allowed_actions',
        'forbidden_actions',
        'escalation_to',
        'evidence_required',
        'persistence',
        'observability_signals',
    ];

    /**
     * Canonical department catalogue.
     *
     * Each entry contains the 12 canon fields plus `maturity_level`,
     * `description` (back-compat with previous schema), `evidence_schema`
     * (top-level handoff schema), `accepts_handoff_from`, `emits_handoff_to`.
     *
     * @var array<string,array<string,mixed>>
     */
    public const CATALOGUE = [
        self::DEPARTMENT_EXECUTIVE_INTAKE => [
            'human_name' => 'Executive Intake',
            'description' => 'Receives ambiguous human intent; emits canonical mission envelope.',
            'scope' => 'recebe pedido humano ambíguo e produz mission envelope canônica antes de product',
            'triggers' => ['operator_intent_raw_received=true'],
            'inputs' => [
                ['name' => 'intent_raw', 'schema' => 'atlas.intent.raw.v1'],
            ],
            'outputs' => [
                ['name' => 'mission_envelope', 'schema' => 'atlas.ai.mission.v1'],
            ],
            'gates' => ['intent_clarified', 'mission_authority_declared'],
            'allowed_actions' => ['ask_clarifying_question', 'classify_intent', 'route_to_department'],
            'forbidden_actions' => ['write_code', 'approve_release', 'modify_security_policy'],
            'escalation_to' => ['operator'],
            'evidence_required' => ['intent_clarification_log', 'mission_envelope_hash'],
            'persistence' => ['primary_table' => 'aaeos_executive_intake', 'ledger' => 'aaeos_intake_ledger'],
            'observability_signals' => ['intake_clarity_loop_count', 'intake_classification_latency_p95'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.ai.mission.v1',
            'accepts_handoff_from' => [],
            'emits_handoff_to' => ['product', 'architecture'],
        ],
        self::DEPARTMENT_PRODUCT => [
            'human_name' => 'Product Department',
            'description' => 'Turns intent into product spec + acceptance criteria.',
            'scope' => 'traduz intenção humana ambígua em engineering_goal disambiguado com critérios de aceitação mensuráveis',
            'triggers' => ['intent_classification.target_department=product'],
            'inputs' => [
                ['name' => 'engineering_goal_raw', 'schema' => 'atlas.engineering_goal.v1'],
            ],
            'outputs' => [
                ['name' => 'engineering_goal_disambiguated', 'schema' => 'atlas.engineering_goal.disambiguated.v1'],
                ['name' => 'acceptance_criteria', 'schema' => 'atlas.acceptance_criteria.v1'],
            ],
            'gates' => ['intent_clarity_score_min', 'acceptance_criteria_min_3'],
            'allowed_actions' => ['ask_clarifying_question', 'propose_acceptance_criteria', 'split_intent'],
            'forbidden_actions' => ['write_code', 'approve_release', 'modify_security_policy'],
            'escalation_to' => ['architect', 'operator'],
            'evidence_required' => ['clarification_log', 'acceptance_criteria_pack'],
            'persistence' => ['primary_table' => 'aaeos_engineering_goals', 'ledger' => 'aaeos_clarification_ledger'],
            'observability_signals' => ['product_clarity_score_avg', 'product_loop_count_avg'],
            'maturity_level' => 'L3',
            'evidence_schema' => 'atlas.dev.mini_programming_spec.v1',
            'accepts_handoff_from' => ['executive_intake'],
            'emits_handoff_to' => ['architecture'],
        ],
        self::DEPARTMENT_ARCHITECTURE => [
            'human_name' => 'Architect Department',
            'description' => 'Decides system design, ADRs, technical boundaries.',
            'scope' => 'define spec_pack canônico, breaking_change_matrix e migration_plan antes de qualquer execução',
            'triggers' => ['intent_classification.scope>=R3', 'breaking_change_detected=true'],
            'inputs' => [
                ['name' => 'engineering_goal_disambiguated', 'schema' => 'atlas.engineering_goal.disambiguated.v1'],
            ],
            'outputs' => [
                ['name' => 'spec_pack', 'schema' => 'atlas.spec_pack.v1'],
                ['name' => 'migration_plan', 'schema' => 'atlas.migration_plan.v1'],
            ],
            'gates' => ['adr_published', 'boundary_validated', 'spec_acceptance_criteria_complete', 'breaking_change_documented', 'rollback_per_slice'],
            'allowed_actions' => ['draft_spec', 'propose_migration_plan', 'request_security_review', 'veto_execution'],
            'forbidden_actions' => ['write_code', 'execute_migration', 'approve_release'],
            'escalation_to' => ['security', 'operator'],
            'evidence_required' => ['spec_pack_hash', 'architect_decision_receipt'],
            'persistence' => ['primary_table' => 'aaeos_spec_packs', 'ledger' => 'aaeos_architect_decision_ledger'],
            'observability_signals' => ['architect_spec_completeness_score', 'architect_veto_count'],
            'maturity_level' => 'L3',
            'evidence_schema' => 'atlas.engineering.architecture_decision.v1',
            'accepts_handoff_from' => ['product'],
            'emits_handoff_to' => ['dev', 'forge'],
        ],
        self::DEPARTMENT_RESEARCH => [
            'human_name' => 'Research Department',
            'description' => 'Investigates unknowns before commit; never modifies runtime.',
            'scope' => 'produz state-of-the-art source-backed para suportar Architect e Self-Construction',
            'triggers' => ['self_construction.gap_detected=true', 'architect.research_needed=true'],
            'inputs' => [
                ['name' => 'research_question', 'schema' => 'atlas.research_question.v1'],
            ],
            'outputs' => [
                ['name' => 'research_pack', 'schema' => 'atlas.research_pack.v1'],
            ],
            'gates' => ['research_findings_published', 'review_only_acknowledged', 'sources_min_3', 'source_dates_recent', 'no_hallucinated_links'],
            'allowed_actions' => ['fetch_sources', 'synthesize_findings', 'propose_doc_promotion'],
            'forbidden_actions' => ['write_code', 'approve_release', 'modify_security_policy'],
            'escalation_to' => ['architect', 'operator'],
            'evidence_required' => ['sources_list_hash', 'research_pack_hash'],
            'persistence' => ['primary_table' => 'aaeos_research_packs', 'ledger' => 'aaeos_source_ledger'],
            'observability_signals' => ['research_source_freshness_avg', 'research_hallucination_count'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.research.findings.v1',
            'accepts_handoff_from' => ['architecture', 'dev', 'forge'],
            'emits_handoff_to' => ['architecture', 'product'],
        ],
        self::DEPARTMENT_DEV => [
            'human_name' => 'Dev Department',
            'description' => 'Atlas Dev fast-lane; small/medium changes with plan + gates.',
            'scope' => 'executa fast-path para intents R1-R3 (1-5 arquivos, baixo-médio risco) com governance leve',
            'triggers' => ['intent_classification.target_department=dev', 'scope<=R3'],
            'inputs' => [
                ['name' => 'spec_pack', 'schema' => 'atlas.spec_pack.v1'],
                ['name' => 'task_pack', 'schema' => 'atlas.task_pack.v1'],
            ],
            'outputs' => [
                ['name' => 'execution_log', 'schema' => 'atlas.execution_log.v1'],
                ['name' => 'patch_pack', 'schema' => 'atlas.patch_pack.v1'],
            ],
            'gates' => ['plan_approved', 'tests_focused', 'review_gate', 'lint_green', 'typecheck_green', 'tests_green', 'scope_guard_ok'],
            'allowed_actions' => ['edit_allowed_files', 'run_tests', 'request_provider_call'],
            'forbidden_actions' => ['edit_security_policy', 'modify_migrations_without_architect', 'approve_release'],
            'escalation_to' => ['architect', 'review', 'forge'],
            'evidence_required' => ['patch_hash', 'test_output_hash', 'scope_guard_report'],
            'persistence' => ['primary_table' => 'aaeos_dev_runs', 'ledger' => 'aaeos_dev_evidence_ledger'],
            'observability_signals' => ['dev_run_duration_p95', 'dev_repair_loop_count', 'dev_scope_violation_count'],
            'maturity_level' => 'L1',
            'evidence_schema' => 'atlas.dev.plan_visible.v1',
            'accepts_handoff_from' => ['architecture'],
            'emits_handoff_to' => ['review', 'qa', 'forge'],
        ],
        self::DEPARTMENT_DEBUG => [
            'human_name' => 'Debug Department',
            'description' => 'Failure investigation, repair orchestration, escalation triggers.',
            'scope' => 'investiga falhas runtime, gera hipóteses, reproduz, isola e propõe fix',
            'triggers' => ['incident_detected=true', 'test_red_after_green=true', 'production_alert=true'],
            'inputs' => [
                ['name' => 'failure_report', 'schema' => 'atlas.failure_report.v1'],
            ],
            'outputs' => [
                ['name' => 'root_cause_pack', 'schema' => 'atlas.root_cause_pack.v1'],
            ],
            'gates' => ['failure_capsule_emitted', 'repair_budget_respected', 'reproduction_confirmed', 'root_cause_evidence_present'],
            'allowed_actions' => ['read_logs', 'run_repro', 'request_observability_query'],
            'forbidden_actions' => ['modify_production_data', 'deploy_fix_without_review'],
            'escalation_to' => ['dev', 'review', 'security'],
            'evidence_required' => ['repro_steps_hash', 'logs_hash', 'root_cause_pack_hash'],
            'persistence' => ['primary_table' => 'aaeos_debug_investigations', 'ledger' => 'aaeos_debug_ledger'],
            'observability_signals' => ['debug_mttr_p95', 'debug_repro_success_rate'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.dev.debug_receipt.v1',
            'accepts_handoff_from' => ['dev', 'qa', 'forge'],
            'emits_handoff_to' => ['dev', 'forge'],
        ],
        self::DEPARTMENT_REVIEW => [
            'human_name' => 'Review Department',
            'description' => 'Code/spec review; bottleneck against weak claims.',
            'scope' => 'revisa patches/specs/migrations/release_packs com checklist canônico antes de cert',
            'triggers' => ['delivery_pack_assembled=true', 'spec_pack_drafted=true'],
            'inputs' => [
                ['name' => 'delivery_pack', 'schema' => 'atlas.delivery_pack.v1'],
            ],
            'outputs' => [
                ['name' => 'review_report', 'schema' => 'atlas.review_report.v1'],
            ],
            'gates' => ['review_packet_signed', 'risk_acknowledged', 'review_checklist_complete', 'blockers_addressed', 'evidence_traceable'],
            'allowed_actions' => ['request_changes', 'approve_for_cert', 'veto_release'],
            'forbidden_actions' => ['edit_code', 'deploy_release', 'modify_security_policy'],
            'escalation_to' => ['architect', 'security', 'operator'],
            'evidence_required' => ['review_report_hash', 'checklist_completion_hash'],
            'persistence' => ['primary_table' => 'aaeos_review_reports', 'ledger' => 'aaeos_review_ledger'],
            'observability_signals' => ['review_findings_severity_avg', 'review_veto_count'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.dev.review_receipt.v1',
            'accepts_handoff_from' => ['dev', 'forge'],
            'emits_handoff_to' => ['qa', 'delivery'],
        ],
        self::DEPARTMENT_QA => [
            'human_name' => 'QA Department',
            'description' => 'Test selection, regression, verification.',
            'scope' => 'garante testabilidade, cobertura, regressão, contract tests e fixtures',
            'triggers' => ['task_pack_decomposed=true', 'delivery_pack_assembled=true'],
            'inputs' => [
                ['name' => 'spec_pack', 'schema' => 'atlas.spec_pack.v1'],
                ['name' => 'patch_pack', 'schema' => 'atlas.patch_pack.v1'],
            ],
            'outputs' => [
                ['name' => 'test_pack', 'schema' => 'atlas.test_pack.v1'],
            ],
            'gates' => ['regression_green', 'verification_complete', 'coverage_min_threshold', 'regression_tests_added', 'fixtures_versioned'],
            'allowed_actions' => ['write_tests', 'request_test_data', 'block_on_coverage_drop'],
            'forbidden_actions' => ['modify_production_code_outside_tests', 'approve_release'],
            'escalation_to' => ['dev', 'architect', 'review'],
            'evidence_required' => ['test_pack_hash', 'coverage_report_hash'],
            'persistence' => ['primary_table' => 'aaeos_test_packs', 'ledger' => 'aaeos_qa_ledger'],
            'observability_signals' => ['qa_coverage_p50', 'qa_regression_catch_rate'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.dev.test_selection_receipt.v1',
            'accepts_handoff_from' => ['dev', 'review'],
            'emits_handoff_to' => ['delivery'],
        ],
        self::DEPARTMENT_SECURITY => [
            'human_name' => 'Security Department',
            'description' => 'Security review; OWASP, secrets, dependency CVEs.',
            'scope' => 'enforce de policy, threat-modeling, secret scanning, dependency audit, sovereignty boundary',
            'triggers' => ['security_path_touched=true', 'intent_class_in=[sensitive,secret,cyber]', 'release_pack_drafted=true'],
            'inputs' => [
                ['name' => 'policy_request', 'schema' => 'atlas.policy_request.v1'],
            ],
            'outputs' => [
                ['name' => 'policy_decision', 'schema' => 'atlas.policy_decision.v1'],
            ],
            'gates' => ['security_scan_clean', 'cve_acknowledged', 'secret_scan_clean', 'dependency_audit_clean', 'threat_model_present', 'sovereignty_boundary_respected'],
            'allowed_actions' => ['allow', 'deny', 'request_mitigation', 'escalate_to_operator'],
            'forbidden_actions' => ['bypass_sovereignty', 'approve_unaudited_dep', 'ship_without_evidence'],
            'escalation_to' => ['operator'],
            'evidence_required' => ['policy_decision_hash', 'secret_scan_report_hash', 'dependency_audit_hash'],
            'persistence' => ['primary_table' => 'aaeos_policy_decisions', 'ledger' => 'aaeos_security_ledger'],
            'observability_signals' => ['security_deny_count', 'security_secret_finding_count'],
            'maturity_level' => 'L3',
            'evidence_schema' => 'atlas.security.finding.v1',
            'accepts_handoff_from' => ['dev', 'review', 'forge'],
            'emits_handoff_to' => ['delivery'],
        ],
        self::DEPARTMENT_FORGE => [
            'human_name' => 'Forge Department',
            'description' => 'Heavy Obras with provider topology + multi-agent scheduler.',
            'scope' => 'executa Obras pesadas multi-módulo R3-R5 com paralelismo, durable reservation, multi-provider',
            'triggers' => ['intent_classification.target_department=forge', 'scope>=R3', 'multi_module_detected=true'],
            'inputs' => [
                ['name' => 'spec_pack', 'schema' => 'atlas.spec_pack.v1'],
                ['name' => 'topology_plan', 'schema' => 'atlas.topology_plan.v1'],
            ],
            'outputs' => [
                ['name' => 'obra_pack', 'schema' => 'atlas.obra_pack.v1'],
                ['name' => 'execution_log', 'schema' => 'atlas.execution_log.v1'],
            ],
            'gates' => ['obra_intake_validated', 'provider_topology_green', 'all-15-universal-gates', 'long_horizon_state_persisted', 'reservation_ledger_consistent', 'merge_review_promotion_passed'],
            'allowed_actions' => ['spawn_agents', 'claim_reservations', 'request_provider_topology', 'merge_after_review'],
            'forbidden_actions' => ['bypass_review', 'modify_security_policy', 'ship_without_cert'],
            'escalation_to' => ['architect', 'review', 'security', 'operator'],
            'evidence_required' => ['obra_pack_hash', 'execution_log_hash', 'merge_review_evidence_hash'],
            'persistence' => ['primary_table' => 'aaeos_obra_runs', 'ledger' => 'aaeos_forge_evidence_ledger'],
            'observability_signals' => ['forge_obra_duration_p95', 'forge_parallel_agent_count', 'forge_collision_count'],
            'maturity_level' => 'L4',
            'evidence_schema' => 'atlas.programming.durable_execution_handoff.v1',
            'accepts_handoff_from' => ['architecture', 'dev'],
            'emits_handoff_to' => ['review', 'qa', 'delivery'],
        ],
        self::DEPARTMENT_DELIVERY => [
            'human_name' => 'Delivery Department',
            'description' => 'Release, rollback decision, deployment evidence.',
            'scope' => 'monta delivery_pack canônico, valida completeness, encaminha para human review e cert',
            'triggers' => ['execution_complete=true', 'evidence_pack_ready=true'],
            'inputs' => [
                ['name' => 'evidence_pack', 'schema' => 'atlas.evidence_pack.v1'],
            ],
            'outputs' => [
                ['name' => 'delivery_pack', 'schema' => 'atlas.delivery_pack.v1'],
            ],
            'gates' => ['release_authority_declared', 'rollback_plan_present', 'delivery_pack_completeness_min_0_95', 'evidence_traceable'],
            'allowed_actions' => ['assemble_delivery_pack', 'sign_delivery_hash', 'request_human_review'],
            'forbidden_actions' => ['edit_code', 'approve_release_without_review', 'modify_security_policy'],
            'escalation_to' => ['review', 'operator'],
            'evidence_required' => ['delivery_pack_hash', 'completeness_report_hash'],
            'persistence' => ['primary_table' => 'aaeos_delivery_packs', 'ledger' => 'aaeos_delivery_ledger'],
            'observability_signals' => ['delivery_completeness_avg', 'delivery_review_loop_count'],
            'maturity_level' => 'L2',
            'evidence_schema' => 'atlas.engineering.release_decision.v1',
            'accepts_handoff_from' => ['review', 'qa', 'security'],
            'emits_handoff_to' => ['memory'],
        ],
        self::DEPARTMENT_MEMORY => [
            'human_name' => 'Memory Department',
            'description' => 'Evidence ledger, learning, compounding signal extraction.',
            'scope' => 'persistência governada de learnings, context packs, decisões, falhas, cross-session continuity',
            'triggers' => ['learning_capsule_emitted=true', 'session_handoff_requested=true', 'context_pack_request=true'],
            'inputs' => [
                ['name' => 'learning_capsule', 'schema' => 'atlas.learning_capsule.v1'],
            ],
            'outputs' => [
                ['name' => 'memory_record', 'schema' => 'atlas.memory_record.v1'],
                ['name' => 'context_pack', 'schema' => 'atlas.context_pack.v1'],
            ],
            'gates' => ['evidence_persisted', 'learning_signal_extracted', 'promotion_gate_passed', 'noise_immunity_check_ok', 'schema_versioned'],
            'allowed_actions' => ['promote_to_memory', 'quarantine_capsule', 'emit_context_pack'],
            'forbidden_actions' => ['bypass_promotion_gate', 'modify_evidence_ledger', 'expose_secrets'],
            'escalation_to' => ['security', 'operator'],
            'evidence_required' => ['promotion_evidence_hash', 'memory_record_hash'],
            'persistence' => ['primary_table' => 'aaeos_memory_records', 'ledger' => 'aaeos_memory_ledger'],
            'observability_signals' => ['memory_promotion_rate', 'memory_quarantine_count'],
            'maturity_level' => 'L3',
            'evidence_schema' => 'atlas.learning.compounding_signal.v1',
            'accepts_handoff_from' => ['delivery', 'review', 'qa', 'forge'],
            'emits_handoff_to' => [],
        ],
    ];

    /**
     * Returns the canonical catalogue envelope.
     *
     * @return array{
     *   schema_version: string,
     *   department_count: int,
     *   canon_department_count: int,
     *   departments: array<string,array<string,mixed>>,
     *   handoff_invariants: list<string>,
     *   evidence_count: int,
     *   schema_fields_12_present: bool
     * }
     */
    public function catalogue(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'department_count' => count(self::CATALOGUE),
            'canon_department_count' => 11,
            'departments' => self::CATALOGUE,
            'handoff_invariants' => [
                'executive_intake_has_no_upstream',
                'memory_has_no_downstream',
                'every_department_declares_gates',
                'every_department_declares_evidence_schema',
                'every_department_declares_12_canon_fields',
            ],
            'evidence_count' => count(array_unique(array_column(self::CATALOGUE, 'evidence_schema'))),
            'schema_fields_12_present' => $this->schemaFields12Present(),
        ];
    }

    /**
     * Validate a handoff between departments.
     *
     * @return array{from: string, to: string, accepted: bool, reason: ?string}
     */
    public function validateHandoff(string $from, string $to): array
    {
        if (! isset(self::CATALOGUE[$from])) {
            return ['from' => $from, 'to' => $to, 'accepted' => false, 'reason' => "unknown department '{$from}'"];
        }
        if (! isset(self::CATALOGUE[$to])) {
            return ['from' => $from, 'to' => $to, 'accepted' => false, 'reason' => "unknown department '{$to}'"];
        }
        $allowedDownstream = (array) (self::CATALOGUE[$from]['emits_handoff_to'] ?? []);
        if (! in_array($to, $allowedDownstream, true)) {
            return [
                'from' => $from,
                'to' => $to,
                'accepted' => false,
                'reason' => sprintf('department "%s" does not emit handoff to "%s" (allowed: %s)',
                    $from, $to, implode(',', $allowedDownstream) ?: 'none'),
            ];
        }

        return ['from' => $from, 'to' => $to, 'accepted' => true, 'reason' => null];
    }

    /** @return list<string> */
    public function gatesFor(string $department): array
    {
        return (array) (self::CATALOGUE[$department]['gates'] ?? []);
    }

    public function evidenceSchemaFor(string $department): ?string
    {
        return self::CATALOGUE[$department]['evidence_schema'] ?? null;
    }

    /**
     * Return the full canon contract for a department, or null if unknown.
     *
     * @return array<string,mixed>|null
     */
    public function contractFor(string $department): ?array
    {
        return self::CATALOGUE[$department] ?? null;
    }

    /**
     * Check whether every department declares all 12 canonical fields.
     * Used by the `schema-fields-12-present` quality gate.
     */
    public function schemaFields12Present(): bool
    {
        foreach (self::CATALOGUE as $dept) {
            foreach (self::CANONICAL_FIELDS as $field) {
                if (! array_key_exists($field, $dept)) {
                    return false;
                }
                if ($dept[$field] === null || $dept[$field] === '' || $dept[$field] === []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Return list of departments missing any canon field — provider-safe diagnostic.
     *
     * @return list<array{department: string, missing: list<string>}>
     */
    public function missingFieldsByDepartment(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $id => $dept) {
            $missing = [];
            foreach (self::CANONICAL_FIELDS as $field) {
                if (! array_key_exists($field, $dept) || $dept[$field] === null || $dept[$field] === '' || $dept[$field] === []) {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $out[] = ['department' => $id, 'missing' => $missing];
            }
        }

        return $out;
    }

    /**
     * Step 3 entry: Architect-agent spec pack gate before R4 autonomous work.
     * Rule 1 only — risk_scope below MIN_AUTONOMOUS_RISK_SCOPE bypasses the gate.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function architectAgentSpecPackGate(array $input = []): array
    {
        if ($input === []) {
            return ArchitectAgentSpecPackGateContract::defaults()->toArray();
        }

        $allowedKeys = [
            'risk_scope',
            'spec_pack_hash',
            'acceptance_criteria_present',
            'rollback_plan_present',
            'breaking_change_matrix_present',
            'operator_signature_present',
        ];
        $normalized = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $input)) {
                $normalized[$key] = $input[$key];
            }
        }

        $contract = ArchitectAgentSpecPackGateContract::fromArray($normalized);
        $result = $contract->toArray();
        $evaluation = $this->evaluateArchitectSpecPackGateRuleRiskScopeBelowMin($contract);
        if ($evaluation !== null) {
            $result['evaluation'] = $evaluation;
        }

        return $result;
    }

    /**
     * Rule 1: work below R4 does not require architect-agent spec pack before autonomous execution.
     *
     * @return array{rule_id: string, gate_required: bool, passed: bool, reason: ?string}|null
     */
    private function evaluateArchitectSpecPackGateRuleRiskScopeBelowMin(ArchitectAgentSpecPackGateContract $contract): ?array
    {
        $scopeIndex = $this->riskScopeIndex($contract->riskScope);
        $minIndex = $this->riskScopeIndex(ArchitectAgentSpecPackGateContract::MIN_AUTONOMOUS_RISK_SCOPE);
        if ($scopeIndex < 0 || $minIndex < 0 || $scopeIndex >= $minIndex) {
            return null;
        }

        return [
            'rule_id' => 'risk_scope_below_min_autonomous',
            'gate_required' => false,
            'passed' => true,
            'reason' => null,
        ];
    }

    private function riskScopeIndex(string $scope): int
    {
        static $levels = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];
        $index = array_search($scope, $levels, true);

        return $index === false ? -1 : (int) $index;
    }
}
