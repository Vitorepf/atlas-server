<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aaeos\Cores\SpecCompletenessScorer;
use App\Services\Ai\Support\AiValueNormalizer;

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
    public const FIELD_MISSING = 'missing';

    public const FIELD_ACCEPTED = 'accepted';

    public const FIELD_NAME = 'name';

    public const FIELD_SCHEMA = 'schema';

    public const FIELD_HUMAN_NAME = 'human_name';

    public const FIELD_DESCRIPTION = 'description';

    public const FIELD_SCOPE = 'scope';

    public const FIELD_TRIGGERS = 'triggers';

    public const FIELD_INPUTS = 'inputs';

    public const FIELD_OUTPUTS = 'outputs';
    public const FIELD_ACCEPTS_HANDOFF_FROM = 'accepts_handoff_from';
    public const FIELD_REASON = 'reason';
    public const FIELD_STATUS = 'status';
    public const FIELD_OK = 'ok';
    public const FIELD_CONTRACT = 'contract';

    public const FIELD_ALLOWED_ACTIONS = 'allowed_actions';

    public const FIELD_FORBIDDEN_ACTIONS = 'forbidden_actions';

    public const FIELD_ESCALATION_TO = 'escalation_to';

    public const FIELD_EVIDENCE_REQUIRED = 'evidence_required';

    public const FIELD_PERSISTENCE = 'persistence';

    public const FIELD_PRIMARY_TABLE = 'primary_table';

    public const FIELD_LEDGER = 'ledger';

    public const FIELD_OBSERVABILITY_SIGNALS = 'observability_signals';

    public const FIELD_MATURITY_LEVEL = 'maturity_level';

    public const FIELD_GATES = 'gates';

    public const FIELD_EVIDENCE_SCHEMA = 'evidence_schema';

    public const FIELD_EMITS_HANDOFF_TO = 'emits_handoff_to';
    public const FIELD_DEPARTMENT_COUNT = 'department_count';
    public const FIELD_CANON_DEPARTMENT_COUNT = 'canon_department_count';

    public const SCHEMA_VERSION = 'atlas.aaeos.department.v1';

    public const SCHEMA_ACCEPTANCE_CRITERIA = 'atlas.acceptance_criteria.v1';

    public const SCHEMA_AI_MISSION = 'atlas.ai.mission.v1';

    public const SCHEMA_CONTEXT_PACK = 'atlas.context_pack.v1';

    public const SCHEMA_DELIVERY_PACK = 'atlas.delivery_pack.v1';

    public const SCHEMA_DEV_DEBUG_RECEIPT = 'atlas.dev.debug_receipt.v1';

    public const SCHEMA_DEV_MINI_PROGRAMMING_SPEC = 'atlas.dev.mini_programming_spec.v1';

    public const SCHEMA_DEV_PLAN_VISIBLE = 'atlas.dev.plan_visible.v1';

    public const SCHEMA_DEV_REVIEW_RECEIPT = 'atlas.dev.review_receipt.v1';

    public const SCHEMA_DEV_TEST_SELECTION_RECEIPT = 'atlas.dev.test_selection_receipt.v1';

    public const SCHEMA_ENGINEERING_ARCHITECTURE_DECISION = 'atlas.engineering.architecture_decision.v1';

    public const SCHEMA_ENGINEERING_RELEASE_DECISION = 'atlas.engineering.release_decision.v1';

    public const SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED = 'atlas.engineering_goal.disambiguated.v1';

    public const SCHEMA_ENGINEERING_GOAL = 'atlas.engineering_goal.v1';

    public const SCHEMA_EVIDENCE_PACK = 'atlas.evidence_pack.v1';

    public const SCHEMA_EXECUTION_LOG = 'atlas.execution_log.v1';

    public const SCHEMA_FAILURE_REPORT = 'atlas.failure_report.v1';

    public const SCHEMA_INTENT_RAW = 'atlas.intent.raw.v1';

    public const SCHEMA_LEARNING_COMPOUNDING_SIGNAL = 'atlas.learning.compounding_signal.v1';

    public const SCHEMA_LEARNING_CAPSULE = 'atlas.learning_capsule.v1';

    public const SCHEMA_MEMORY_RECORD = 'atlas.memory_record.v1';

    public const SCHEMA_MIGRATION_PLAN = 'atlas.migration_plan.v1';

    public const SCHEMA_OBRA_PACK = 'atlas.obra_pack.v1';

    public const SCHEMA_PATCH_PACK = 'atlas.patch_pack.v1';

    public const SCHEMA_POLICY_DECISION = 'atlas.policy_decision.v1';

    public const SCHEMA_POLICY_REQUEST = 'atlas.policy_request.v1';

    public const SCHEMA_PROGRAMMING_DURABLE_EXECUTION_HANDOFF = 'atlas.programming.durable_execution_handoff.v1';

    public const SCHEMA_RESEARCH_FINDINGS = 'atlas.research.findings.v1';

    public const SCHEMA_RESEARCH_PACK = 'atlas.research_pack.v1';

    public const SCHEMA_RESEARCH_QUESTION = 'atlas.research_question.v1';

    public const SCHEMA_REVIEW_REPORT = 'atlas.review_report.v1';

    public const SCHEMA_ROOT_CAUSE_PACK = 'atlas.root_cause_pack.v1';

    public const SCHEMA_SECURITY_FINDING = 'atlas.security.finding.v1';

    public const SCHEMA_SPEC_PACK = 'atlas.spec_pack.v1';

    public const SCHEMA_TASK_PACK = 'atlas.task_pack.v1';

    public const SCHEMA_TEST_PACK = 'atlas.test_pack.v1';

    public const SCHEMA_TOPOLOGY_PLAN = 'atlas.topology_plan.v1';


    /** Pre-product router stage (Atlas extension above canon 11). */
    public const DEPARTMENT_EXECUTIVE_INTAKE = 'executive_intake';

    public const DEPARTMENT_PRODUCT = 'product';

    /** Canon id is `architect`; legacy alias `architecture` preserved for back-compat. */
    public const DEPARTMENT_ARCHITECTURE = 'architecture';

    public const DEPARTMENT_ARCHITECT = 'architect';

    public const DEPARTMENT_RESEARCH = 'research';

    public const DEPARTMENT_DEV = 'dev';

    public const DEPARTMENT_DEBUG = 'debug';

    public const DEPARTMENT_REVIEW = 'review';

    public const DEPARTMENT_QA = 'qa';

    public const DEPARTMENT_SECURITY = 'security';

    public const DEPARTMENT_FORGE = 'forge';

    public const DEPARTMENT_DELIVERY = 'delivery';

    public const DEPARTMENT_MEMORY = 'memory';

    /** Escalation target id used by department contracts (not a full department row). */
    public const DEPARTMENT_OPERATOR = 'operator';
    public const FIELD_FROM = 'from';
    public const FIELD_DEPARTMENTS = 'departments';

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
            self::FIELD_HUMAN_NAME => 'Executive Intake',
            self::FIELD_DESCRIPTION => 'Receives ambiguous human intent; emits canonical mission envelope.',
            self::FIELD_SCOPE => 'recebe pedido humano ambíguo e produz mission envelope canônica antes de product',
            self::FIELD_TRIGGERS => ['operator_intent_raw_received=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'intent_raw', self::FIELD_SCHEMA => self::SCHEMA_INTENT_RAW],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'mission_envelope', self::FIELD_SCHEMA => self::SCHEMA_AI_MISSION],
            ],
            self::FIELD_GATES => ['intent_clarified', 'mission_authority_declared'],
            self::FIELD_ALLOWED_ACTIONS => ['ask_clarifying_question', 'classify_intent', 'route_to_department'],
            self::FIELD_FORBIDDEN_ACTIONS => ['write_code', 'approve_release', 'modify_security_policy'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['intent_clarification_log', 'mission_envelope_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_executive_intake', self::FIELD_LEDGER => 'aaeos_intake_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['intake_clarity_loop_count', 'intake_classification_latency_p95'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_AI_MISSION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_PRODUCT, self::DEPARTMENT_ARCHITECTURE],
        ],
        self::DEPARTMENT_PRODUCT => [
            self::FIELD_HUMAN_NAME => 'Product Department',
            self::FIELD_DESCRIPTION => 'Turns intent into product spec + acceptance criteria.',
            self::FIELD_SCOPE => 'traduz intenção humana ambígua em engineering_goal disambiguado com critérios de aceitação mensuráveis',
            self::FIELD_TRIGGERS => ['intent_classification.target_department=product'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'engineering_goal_raw', self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'engineering_goal_disambiguated', self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED],
                [self::FIELD_NAME => 'acceptance_criteria', self::FIELD_SCHEMA => self::SCHEMA_ACCEPTANCE_CRITERIA],
            ],
            self::FIELD_GATES => ['intent_clarity_score_min', 'acceptance_criteria_min_3'],
            self::FIELD_ALLOWED_ACTIONS => ['ask_clarifying_question', 'propose_acceptance_criteria', 'split_intent'],
            self::FIELD_FORBIDDEN_ACTIONS => ['write_code', 'approve_release', 'modify_security_policy'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['clarification_log', 'acceptance_criteria_pack'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_engineering_goals', self::FIELD_LEDGER => 'aaeos_clarification_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['product_clarity_score_avg', 'product_loop_count_avg'],
            self::FIELD_MATURITY_LEVEL => 'L3',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_MINI_PROGRAMMING_SPEC,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_EXECUTIVE_INTAKE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_ARCHITECTURE],
        ],
        self::DEPARTMENT_ARCHITECTURE => [
            self::FIELD_HUMAN_NAME => 'Architect Department',
            self::FIELD_DESCRIPTION => 'Decides system design, ADRs, technical boundaries.',
            self::FIELD_SCOPE => 'define spec_pack canônico, breaking_change_matrix e migration_plan antes de qualquer execução',
            self::FIELD_TRIGGERS => ['intent_classification.scope>=R3', 'breaking_change_detected=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'engineering_goal_disambiguated', self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'spec_pack', self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => 'migration_plan', self::FIELD_SCHEMA => self::SCHEMA_MIGRATION_PLAN],
            ],
            self::FIELD_GATES => ['adr_published', 'boundary_validated', 'spec_acceptance_criteria_complete', 'breaking_change_documented', 'rollback_per_slice'],
            self::FIELD_ALLOWED_ACTIONS => ['draft_spec', 'propose_migration_plan', 'request_security_review', 'veto_execution'],
            self::FIELD_FORBIDDEN_ACTIONS => ['write_code', 'execute_migration', 'approve_release'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['spec_pack_hash', 'architect_decision_receipt'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_spec_packs', self::FIELD_LEDGER => 'aaeos_architect_decision_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['architect_spec_completeness_score', 'architect_veto_count'],
            self::FIELD_MATURITY_LEVEL => 'L3',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_ENGINEERING_ARCHITECTURE_DECISION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_PRODUCT],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_RESEARCH => [
            self::FIELD_HUMAN_NAME => 'Research Department',
            self::FIELD_DESCRIPTION => 'Investigates unknowns before commit; never modifies runtime.',
            self::FIELD_SCOPE => 'produz state-of-the-art source-backed para suportar Architect e Self-Construction',
            self::FIELD_TRIGGERS => ['self_construction.gap_detected=true', 'architect.research_needed=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'research_question', self::FIELD_SCHEMA => self::SCHEMA_RESEARCH_QUESTION],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'research_pack', self::FIELD_SCHEMA => self::SCHEMA_RESEARCH_PACK],
            ],
            self::FIELD_GATES => ['research_findings_published', 'review_only_acknowledged', 'sources_min_3', 'source_dates_recent', 'no_hallucinated_links'],
            self::FIELD_ALLOWED_ACTIONS => ['fetch_sources', 'synthesize_findings', 'propose_doc_promotion'],
            self::FIELD_FORBIDDEN_ACTIONS => ['write_code', 'approve_release', 'modify_security_policy'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['sources_list_hash', 'research_pack_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_research_packs', self::FIELD_LEDGER => 'aaeos_source_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['research_source_freshness_avg', 'research_hallucination_count'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_RESEARCH_FINDINGS,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_PRODUCT],
        ],
        self::DEPARTMENT_DEV => [
            self::FIELD_HUMAN_NAME => 'Dev Department',
            self::FIELD_DESCRIPTION => 'Atlas Dev fast-lane; small/medium changes with plan + gates.',
            self::FIELD_SCOPE => 'executa fast-path para intents R1-R3 (1-5 arquivos, baixo-médio risco) com governance leve',
            self::FIELD_TRIGGERS => ['intent_classification.target_department=dev', 'scope<=R3'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'spec_pack', self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => 'task_pack', self::FIELD_SCHEMA => self::SCHEMA_TASK_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'execution_log', self::FIELD_SCHEMA => self::SCHEMA_EXECUTION_LOG],
                [self::FIELD_NAME => 'patch_pack', self::FIELD_SCHEMA => self::SCHEMA_PATCH_PACK],
            ],
            self::FIELD_GATES => ['plan_approved', 'tests_focused', 'review_gate', 'lint_green', 'typecheck_green', 'tests_green', 'scope_guard_ok'],
            self::FIELD_ALLOWED_ACTIONS => ['edit_allowed_files', 'run_tests', 'request_provider_call'],
            self::FIELD_FORBIDDEN_ACTIONS => ['edit_security_policy', 'modify_migrations_without_architect', 'approve_release'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW, self::DEPARTMENT_FORGE],
            self::FIELD_EVIDENCE_REQUIRED => ['patch_hash', 'test_output_hash', 'scope_guard_report'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_dev_runs', self::FIELD_LEDGER => 'aaeos_dev_evidence_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['dev_run_duration_p95', 'dev_repair_loop_count', 'dev_scope_violation_count'],
            self::FIELD_MATURITY_LEVEL => 'L1',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_PLAN_VISIBLE,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_REVIEW, 'qa', self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_DEBUG => [
            self::FIELD_HUMAN_NAME => 'Debug Department',
            self::FIELD_DESCRIPTION => 'Failure investigation, repair orchestration, escalation triggers.',
            self::FIELD_SCOPE => 'investiga falhas runtime, gera hipóteses, reproduz, isola e propõe fix',
            self::FIELD_TRIGGERS => ['incident_detected=true', 'test_red_after_green=true', 'production_alert=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'failure_report', self::FIELD_SCHEMA => self::SCHEMA_FAILURE_REPORT],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'root_cause_pack', self::FIELD_SCHEMA => self::SCHEMA_ROOT_CAUSE_PACK],
            ],
            self::FIELD_GATES => ['failure_capsule_emitted', 'repair_budget_respected', 'reproduction_confirmed', 'root_cause_evidence_present'],
            self::FIELD_ALLOWED_ACTIONS => ['read_logs', 'run_repro', 'request_observability_query'],
            self::FIELD_FORBIDDEN_ACTIONS => ['modify_production_data', 'deploy_fix_without_review'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW, self::DEPARTMENT_SECURITY],
            self::FIELD_EVIDENCE_REQUIRED => ['repro_steps_hash', 'logs_hash', 'root_cause_pack_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_debug_investigations', self::FIELD_LEDGER => 'aaeos_debug_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['debug_mttr_p95', 'debug_repro_success_rate'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_DEBUG_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, 'qa', self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_REVIEW => [
            self::FIELD_HUMAN_NAME => 'Review Department',
            self::FIELD_DESCRIPTION => 'Code/spec review; bottleneck against weak claims.',
            self::FIELD_SCOPE => 'revisa patches/specs/migrations/release_packs com checklist canônico antes de cert',
            self::FIELD_TRIGGERS => ['delivery_pack_assembled=true', 'spec_pack_drafted=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'delivery_pack', self::FIELD_SCHEMA => self::SCHEMA_DELIVERY_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'review_report', self::FIELD_SCHEMA => self::SCHEMA_REVIEW_REPORT],
            ],
            self::FIELD_GATES => ['review_packet_signed', 'risk_acknowledged', 'review_checklist_complete', 'blockers_addressed', 'evidence_traceable'],
            self::FIELD_ALLOWED_ACTIONS => ['request_changes', 'approve_for_cert', 'veto_release'],
            self::FIELD_FORBIDDEN_ACTIONS => ['edit_code', 'deploy_release', 'modify_security_policy'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['review_report_hash', 'checklist_completion_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_review_reports', self::FIELD_LEDGER => 'aaeos_review_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['review_findings_severity_avg', 'review_veto_count'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_REVIEW_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => ['qa', self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_QA => [
            self::FIELD_HUMAN_NAME => 'QA Department',
            self::FIELD_DESCRIPTION => 'Test selection, regression, verification.',
            self::FIELD_SCOPE => 'garante testabilidade, cobertura, regressão, contract tests e fixtures',
            self::FIELD_TRIGGERS => ['task_pack_decomposed=true', 'delivery_pack_assembled=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'spec_pack', self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => 'patch_pack', self::FIELD_SCHEMA => self::SCHEMA_PATCH_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'test_pack', self::FIELD_SCHEMA => self::SCHEMA_TEST_PACK],
            ],
            self::FIELD_GATES => ['regression_green', 'verification_complete', 'coverage_min_threshold', 'regression_tests_added', 'fixtures_versioned'],
            self::FIELD_ALLOWED_ACTIONS => ['write_tests', 'request_test_data', 'block_on_coverage_drop'],
            self::FIELD_FORBIDDEN_ACTIONS => ['modify_production_code_outside_tests', 'approve_release'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW],
            self::FIELD_EVIDENCE_REQUIRED => ['test_pack_hash', 'coverage_report_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_test_packs', self::FIELD_LEDGER => 'aaeos_qa_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['qa_coverage_p50', 'qa_regression_catch_rate'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_TEST_SELECTION_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_SECURITY => [
            self::FIELD_HUMAN_NAME => 'Security Department',
            self::FIELD_DESCRIPTION => 'Security review; OWASP, secrets, dependency CVEs.',
            self::FIELD_SCOPE => 'enforce de policy, threat-modeling, secret scanning, dependency audit, sovereignty boundary',
            self::FIELD_TRIGGERS => ['security_path_touched=true', 'intent_class_in=[sensitive,secret,cyber]', 'release_pack_drafted=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'policy_request', self::FIELD_SCHEMA => self::SCHEMA_POLICY_REQUEST],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'policy_decision', self::FIELD_SCHEMA => self::SCHEMA_POLICY_DECISION],
            ],
            self::FIELD_GATES => ['security_scan_clean', 'cve_acknowledged', 'secret_scan_clean', 'dependency_audit_clean', 'threat_model_present', 'sovereignty_boundary_respected'],
            self::FIELD_ALLOWED_ACTIONS => ['allow', 'deny', 'request_mitigation', 'escalate_to_operator'],
            self::FIELD_FORBIDDEN_ACTIONS => ['bypass_sovereignty', 'approve_unaudited_dep', 'ship_without_evidence'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['policy_decision_hash', 'secret_scan_report_hash', 'dependency_audit_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_policy_decisions', self::FIELD_LEDGER => 'aaeos_security_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['security_deny_count', 'security_secret_finding_count'],
            self::FIELD_MATURITY_LEVEL => 'L3',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_SECURITY_FINDING,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_FORGE => [
            self::FIELD_HUMAN_NAME => 'Forge Department',
            self::FIELD_DESCRIPTION => 'Heavy Obras with provider topology + multi-agent scheduler.',
            self::FIELD_SCOPE => 'executa Obras pesadas multi-módulo R3-R5 com paralelismo, durable reservation, multi-provider',
            self::FIELD_TRIGGERS => ['intent_classification.target_department=forge', 'scope>=R3', 'multi_module_detected=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'spec_pack', self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => 'topology_plan', self::FIELD_SCHEMA => self::SCHEMA_TOPOLOGY_PLAN],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'obra_pack', self::FIELD_SCHEMA => self::SCHEMA_OBRA_PACK],
                [self::FIELD_NAME => 'execution_log', self::FIELD_SCHEMA => self::SCHEMA_EXECUTION_LOG],
            ],
            self::FIELD_GATES => ['obra_intake_validated', 'provider_topology_green', 'all-15-universal-gates', 'long_horizon_state_persisted', 'reservation_ledger_consistent', 'merge_review_promotion_passed'],
            self::FIELD_ALLOWED_ACTIONS => ['spawn_agents', 'claim_reservations', 'request_provider_topology', 'merge_after_review'],
            self::FIELD_FORBIDDEN_ACTIONS => ['bypass_review', 'modify_security_policy', 'ship_without_cert'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW, self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['obra_pack_hash', 'execution_log_hash', 'merge_review_evidence_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_obra_runs', self::FIELD_LEDGER => 'aaeos_forge_evidence_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['forge_obra_duration_p95', 'forge_parallel_agent_count', 'forge_collision_count'],
            self::FIELD_MATURITY_LEVEL => 'L4',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_PROGRAMMING_DURABLE_EXECUTION_HANDOFF,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_DEV],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_REVIEW, 'qa', self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_DELIVERY => [
            self::FIELD_HUMAN_NAME => 'Delivery Department',
            self::FIELD_DESCRIPTION => 'Release, rollback decision, deployment evidence.',
            self::FIELD_SCOPE => 'monta delivery_pack canônico, valida completeness, encaminha para human review e cert',
            self::FIELD_TRIGGERS => ['execution_complete=true', 'evidence_pack_ready=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'evidence_pack', self::FIELD_SCHEMA => self::SCHEMA_EVIDENCE_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'delivery_pack', self::FIELD_SCHEMA => self::SCHEMA_DELIVERY_PACK],
            ],
            self::FIELD_GATES => ['release_authority_declared', 'rollback_plan_present', 'delivery_pack_completeness_min_0_95', 'evidence_traceable'],
            self::FIELD_ALLOWED_ACTIONS => ['assemble_delivery_pack', 'sign_delivery_hash', 'request_human_review'],
            self::FIELD_FORBIDDEN_ACTIONS => ['edit_code', 'approve_release_without_review', 'modify_security_policy'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_REVIEW, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['delivery_pack_hash', 'completeness_report_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_delivery_packs', self::FIELD_LEDGER => 'aaeos_delivery_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['delivery_completeness_avg', 'delivery_review_loop_count'],
            self::FIELD_MATURITY_LEVEL => 'L2',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_ENGINEERING_RELEASE_DECISION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_REVIEW, 'qa', self::DEPARTMENT_SECURITY],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_MEMORY],
        ],
        self::DEPARTMENT_MEMORY => [
            self::FIELD_HUMAN_NAME => 'Memory Department',
            self::FIELD_DESCRIPTION => 'Evidence ledger, learning, compounding signal extraction.',
            self::FIELD_SCOPE => 'persistência governada de learnings, context packs, decisões, falhas, cross-session continuity',
            self::FIELD_TRIGGERS => ['learning_capsule_emitted=true', 'session_handoff_requested=true', 'context_pack_request=true'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => 'learning_capsule', self::FIELD_SCHEMA => self::SCHEMA_LEARNING_CAPSULE],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => 'memory_record', self::FIELD_SCHEMA => self::SCHEMA_MEMORY_RECORD],
                [self::FIELD_NAME => 'context_pack', self::FIELD_SCHEMA => self::SCHEMA_CONTEXT_PACK],
            ],
            self::FIELD_GATES => ['evidence_persisted', 'learning_signal_extracted', 'promotion_gate_passed', 'noise_immunity_check_ok', 'schema_versioned'],
            self::FIELD_ALLOWED_ACTIONS => ['promote_to_memory', 'quarantine_capsule', 'emit_context_pack'],
            self::FIELD_FORBIDDEN_ACTIONS => ['bypass_promotion_gate', 'modify_evidence_ledger', 'expose_secrets'],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => ['promotion_evidence_hash', 'memory_record_hash'],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => 'aaeos_memory_records', self::FIELD_LEDGER => 'aaeos_memory_ledger'],
            self::FIELD_OBSERVABILITY_SIGNALS => ['memory_promotion_rate', 'memory_quarantine_count'],
            self::FIELD_MATURITY_LEVEL => 'L3',
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_LEARNING_COMPOUNDING_SIGNAL,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DELIVERY, self::DEPARTMENT_REVIEW, 'qa', self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [],
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
    public function __construct(
        private readonly SpecCompletenessScorer $specCompleteness = new SpecCompletenessScorer,
    ) {}

    public function catalogue(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENT_COUNT => count(self::CATALOGUE),
            self::FIELD_CANON_DEPARTMENT_COUNT => 11,
            self::FIELD_DEPARTMENTS => self::CATALOGUE,
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
            return [self::FIELD_FROM => $from, 'to' => $to, self::FIELD_ACCEPTED => false, self::FIELD_REASON => "unknown department '{$from}'"];
        }
        if (! isset(self::CATALOGUE[$to])) {
            return [self::FIELD_FROM => $from, 'to' => $to, self::FIELD_ACCEPTED => false, self::FIELD_REASON => "unknown department '{$to}'"];
        }
        $allowedDownstream = AiValueNormalizer::arrayOrEmpty(self::CATALOGUE[$from][self::FIELD_EMITS_HANDOFF_TO] ?? null);
        if (! in_array($to, $allowedDownstream, true)) {
            return [
                self::FIELD_FROM => $from,
                'to' => $to,
                self::FIELD_ACCEPTED => false,
                self::FIELD_REASON => sprintf('department "%s" does not emit handoff to "%s" (allowed: %s)',
                    $from, $to, implode(',', $allowedDownstream) ?: 'none'),
            ];
        }

        return [self::FIELD_FROM => $from, 'to' => $to, self::FIELD_ACCEPTED => true, self::FIELD_REASON => null];
    }

    /** @return list<string> */
    public function gatesFor(string $department): array
    {
        return AiValueNormalizer::arrayOrEmpty(self::CATALOGUE[$department][self::FIELD_GATES] ?? null);
    }

    public function evidenceSchemaFor(string $department): ?string
    {
        return self::CATALOGUE[$department][self::FIELD_EVIDENCE_SCHEMA] ?? null;
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
                $out[] = ['department' => $id, self::FIELD_MISSING => $missing];
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

        // Observe-only: when a compiled-spec map is supplied, stamp SpecCompletenessScorer.
        $spec = AiValueNormalizer::arrayOrEmpty($input['spec'] ?? null);
        if ($spec !== []) {
            $result['observe'] = [
                'spec_completeness' => $this->specCompleteness->score($spec),
            ];
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
            self::FIELD_REASON => null,
        ];
    }

    private function riskScopeIndex(string $scope): int
    {
        static $levels = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];
        $index = array_search(AiValueNormalizer::upperTrimmedString($scope), $levels, true);

        return $index === false ? -1 : (int) $index;
    }
}
