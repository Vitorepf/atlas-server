<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\Scoring\SpecCompletenessScorer;
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
    public const FIELD_TO = 'to';
    public const FIELD_DEPARTMENT = 'department';
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
    public const FIELD_EVALUATION = 'evaluation';
    public const FIELD_EVIDENCE_COUNT = 'evidence_count';
    public const FIELD_GATE_REQUIRED = 'gate_required';
    public const FIELD_HANDOFF_INVARIANTS = 'handoff_invariants';
    public const FIELD_OBSERVE = 'observe';
    public const FIELD_PASSED = 'passed';
    public const FIELD_RULE_ID = 'rule_id';
    public const FIELD_SCHEMA_FIELDS_12_PRESENT = 'schema_fields_12_present';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SPEC = 'spec';
    public const FIELD_SPEC_COMPLETENESS = 'spec_completeness';
    public const FIELD_SPEC_PACK = 'spec_pack';
    public const FIELD_DELIVERY_PACK = 'delivery_pack';
    public const FIELD_ENGINEERING_GOAL_DISAMBIGUATED = 'engineering_goal_disambiguated';
    public const FIELD_EXECUTION_LOG = 'execution_log';
    public const FIELD_PATCH_PACK = 'patch_pack';
    public const FIELD_AAEOS_ARCHITECT_DECISION_LEDGER = 'aaeos_architect_decision_ledger';
    public const FIELD_AAEOS_CLARIFICATION_LEDGER = 'aaeos_clarification_ledger';
    public const FIELD_AAEOS_DEBUG_INVESTIGATIONS = 'aaeos_debug_investigations';
    public const FIELD_AAEOS_DEBUG_LEDGER = 'aaeos_debug_ledger';
    public const FIELD_AAEOS_DELIVERY_LEDGER = 'aaeos_delivery_ledger';
    public const FIELD_AAEOS_DELIVERY_PACKS = 'aaeos_delivery_packs';
    public const FIELD_AAEOS_DEV_EVIDENCE_LEDGER = 'aaeos_dev_evidence_ledger';
    public const FIELD_AAEOS_DEV_RUNS = 'aaeos_dev_runs';
    public const FIELD_AAEOS_ENGINEERING_GOALS = 'aaeos_engineering_goals';
    public const FIELD_AAEOS_EXECUTIVE_INTAKE = 'aaeos_executive_intake';
    public const FIELD_AAEOS_FORGE_EVIDENCE_LEDGER = 'aaeos_forge_evidence_ledger';
    public const FIELD_AAEOS_INTAKE_LEDGER = 'aaeos_intake_ledger';
    public const FIELD_AAEOS_MEMORY_LEDGER = 'aaeos_memory_ledger';
    public const FIELD_AAEOS_MEMORY_RECORDS = 'aaeos_memory_records';
    public const FIELD_AAEOS_OBRA_RUNS = 'aaeos_obra_runs';
    public const FIELD_AAEOS_POLICY_DECISIONS = 'aaeos_policy_decisions';
    public const FIELD_AAEOS_QA_LEDGER = 'aaeos_qa_ledger';
    public const FIELD_AAEOS_RESEARCH_PACKS = 'aaeos_research_packs';
    public const FIELD_AAEOS_REVIEW_LEDGER = 'aaeos_review_ledger';
    public const FIELD_AAEOS_REVIEW_REPORTS = 'aaeos_review_reports';
    public const FIELD_AAEOS_SECURITY_LEDGER = 'aaeos_security_ledger';
    public const FIELD_AAEOS_SOURCE_LEDGER = 'aaeos_source_ledger';
    public const FIELD_AAEOS_SPEC_PACKS = 'aaeos_spec_packs';
    public const FIELD_AAEOS_TEST_PACKS = 'aaeos_test_packs';
    public const FIELD_ACCEPTANCE_CRITERIA = 'acceptance_criteria';
    public const FIELD_CONTEXT_PACK = 'context_pack';
    public const FIELD_ENGINEERING_GOAL_RAW = 'engineering_goal_raw';
    public const FIELD_EVIDENCE_PACK = 'evidence_pack';
    public const FIELD_FAILURE_REPORT = 'failure_report';
    public const FIELD_INTENT_RAW = 'intent_raw';
    public const FIELD_LEARNING_CAPSULE = 'learning_capsule';
    public const FIELD_MEMORY_RECORD = 'memory_record';
    public const FIELD_MIGRATION_PLAN = 'migration_plan';
    public const FIELD_MISSION_ENVELOPE = 'mission_envelope';
    public const FIELD_OBRA_PACK = 'obra_pack';
    public const FIELD_POLICY_DECISION = 'policy_decision';
    public const FIELD_POLICY_REQUEST = 'policy_request';
    public const FIELD_RESEARCH_PACK = 'research_pack';
    public const FIELD_RESEARCH_QUESTION = 'research_question';
    public const FIELD_REVIEW_REPORT = 'review_report';
    public const FIELD_RISK_SCOPE_BELOW_MIN_AUTONOMOUS = 'risk_scope_below_min_autonomous';
    public const FIELD_ROOT_CAUSE_PACK = 'root_cause_pack';
    public const FIELD_TASK_PACK = 'task_pack';
    public const FIELD_TEST_PACK = 'test_pack';
    public const FIELD_TOPOLOGY_PLAN = 'topology_plan';
    public const FIELD_APPROVE_RELEASE = 'approve_release';
    public const FIELD_ROLLBACK_PLAN_PRESENT = 'rollback_plan_present';
    public const FIELD_APPROVE_FOR_CERT = 'approve_for_cert';
    public const FIELD_APPROVE_RELEASE_WITHOUT_REVIEW = 'approve_release_without_review';
    public const FIELD_APPROVE_UNAUDITED_DEP = 'approve_unaudited_dep';
    public const FIELD_BLOCKERS_ADDRESSED = 'blockers_addressed';
    public const FIELD_BOUNDARY_VALIDATED = 'boundary_validated';
    public const FIELD_BREAKING_CHANGE_DOCUMENTED = 'breaking_change_documented';
    public const FIELD_BREAKING_CHANGE_MATRIX_PRESENT = 'breaking_change_matrix_present';
    public const FIELD_CLAIM_RESERVATIONS = 'claim_reservations';
    public const FIELD_CLASSIFY_INTENT = 'classify_intent';
    public const FIELD_CVE_ACKNOWLEDGED = 'cve_acknowledged';
    public const FIELD_DELIVERY_PACK_COMPLETENESS_MIN_0_95 = 'delivery_pack_completeness_min_0_95';
    public const FIELD_DENY = 'deny';
    public const FIELD_DEPLOY_RELEASE = 'deploy_release';
    public const FIELD_DEV_REPAIR_LOOP_COUNT = 'dev_repair_loop_count';
    public const FIELD_EVERY_DEPARTMENT_DECLARES_EVIDENCE_SCHEMA = 'every_department_declares_evidence_schema';
    public const FIELD_EXECUTE_MIGRATION = 'execute_migration';
    public const FIELD_EVERY_DEPARTMENT_DECLARES_12_CANON_FIELDS = 'every_department_declares_12_canon_fields';
    public const FIELD_EXECUTION_LOG_HASH = 'execution_log_hash';
    public const FIELD_FORGE_PARALLEL_AGENT_COUNT = 'forge_parallel_agent_count';
    public const FIELD_LEARNING_SIGNAL_EXTRACTED = 'learning_signal_extracted';
    public const FIELD_LINT_GREEN = 'lint_green';
    public const FIELD_LOGS_HASH = 'logs_hash';
    public const FIELD_LONG_HORIZON_STATE_PERSISTED = 'long_horizon_state_persisted';
    public const FIELD_MEMORY_HAS_NO_DOWNSTREAM = 'memory_has_no_downstream';
    public const FIELD_EVERY_DEPARTMENT_DECLARES_GATES = 'every_department_declares_gates';
    public const FIELD_MODIFY_EVIDENCE_LEDGER = 'modify_evidence_ledger';
    public const FIELD_MODIFY_MIGRATIONS_WITHOUT_ARCHITECT = 'modify_migrations_without_architect';
    public const FIELD_MODIFY_SECURITY_POLICY = 'modify_security_policy';
    public const FIELD_OPERATOR_SIGNATURE_PRESENT = 'operator_signature_present';
    public const FIELD_PROMOTION_GATE_PASSED = 'promotion_gate_passed';
    public const FIELD_NOISE_IMMUNITY_CHECK_OK = 'noise_immunity_check_ok';
    public const FIELD_PROPOSE_ACCEPTANCE_CRITERIA = 'propose_acceptance_criteria';
    public const FIELD_PROPOSE_MIGRATION_PLAN = 'propose_migration_plan';
    public const FIELD_PROVIDER_TOPOLOGY_GREEN = 'provider_topology_green';
    public const FIELD_QUARANTINE_CAPSULE = 'quarantine_capsule';
    public const FIELD_REGRESSION_TESTS_ADDED = 'regression_tests_added';
    public const FIELD_REPAIR_BUDGET_RESPECTED = 'repair_budget_respected';
    public const FIELD_REQUEST_MITIGATION = 'request_mitigation';
    public const FIELD_REPRODUCTION_CONFIRMED = 'reproduction_confirmed';
    public const FIELD_REQUEST_PROVIDER_TOPOLOGY = 'request_provider_topology';
    public const FIELD_REQUEST_SECURITY_REVIEW = 'request_security_review';
    public const FIELD_REQUEST_TEST_DATA = 'request_test_data';
    public const FIELD_RESERVATION_LEDGER_CONSISTENT = 'reservation_ledger_consistent';
    public const FIELD_REVIEW_ONLY_ACKNOWLEDGED = 'review_only_acknowledged';
    public const FIELD_RISK_ACKNOWLEDGED = 'risk_acknowledged';
    public const FIELD_RUN_REPRO = 'run_repro';
    public const FIELD_RUN_TESTS = 'run_tests';
    public const FIELD_REVIEW_CHECKLIST_COMPLETE = 'review_checklist_complete';
    public const FIELD_SECRET_SCAN_CLEAN = 'secret_scan_clean';
    public const FIELD_SECRET_SCAN_REPORT_HASH = 'secret_scan_report_hash';
    public const FIELD_SIGN_DELIVERY_HASH = 'sign_delivery_hash';
    public const FIELD_SOURCES_MIN_3 = 'sources_min_3';
    public const FIELD_DEPENDENCY_AUDIT_CLEAN = 'dependency_audit_clean';
    public const FIELD_SOURCE_DATES_RECENT = 'source_dates_recent';
    public const FIELD_SPEC_ACCEPTANCE_CRITERIA_COMPLETE = 'spec_acceptance_criteria_complete';
    public const FIELD_SPEC_PACK_HASH = 'spec_pack_hash';
    public const FIELD_SYNTHESIZE_FINDINGS = 'synthesize_findings';
    public const FIELD_ACCEPTANCE_CRITERIA_PRESENT = 'acceptance_criteria_present';
    public const FIELD_TEST_OUTPUT_HASH = 'test_output_hash';
    public const FIELD_TESTS_FOCUSED = 'tests_focused';
    public const FIELD_THREAT_MODEL_PRESENT = 'threat_model_present';
    public const FIELD_TYPECHECK_GREEN = 'typecheck_green';
    public const FIELD_REVIEW_GATE = 'review_gate';
    public const FIELD_TESTS_GREEN = 'tests_green';
    public const FIELD_VERIFICATION_COMPLETE = 'verification_complete';
    public const FIELD_COVERAGE_MIN_THRESHOLD = 'coverage_min_threshold';
    public const FIELD_QA = 'qa';
    public const FIELD_WRITE_CODE = 'write_code';
    public const FIELD_ASK_CLARIFYING_QUESTION = 'ask_clarifying_question';
    public const FIELD_EDIT_CODE = 'edit_code';
    public const FIELD_EVIDENCE_TRACEABLE = 'evidence_traceable';
    public const FIELD_ACCEPTANCE_CRITERIA_MIN_3 = 'acceptance_criteria_min_3';
    public const FIELD_ACCEPTANCE_CRITERIA_PACK = 'acceptance_criteria_pack';
    public const FIELD_ADR_PUBLISHED = 'adr_published';
    public const FIELD_ALLOW = 'allow';
    public const FIELD_ARCHITECT_DECISION_RECEIPT = 'architect_decision_receipt';
    public const FIELD_ARCHITECT_SPEC_COMPLETENESS_SCORE = 'architect_spec_completeness_score';
    public const FIELD_ARCHITECT_VETO_COUNT = 'architect_veto_count';
    public const FIELD_ASSEMBLE_DELIVERY_PACK = 'assemble_delivery_pack';
    public const FIELD_BLOCK_ON_COVERAGE_DROP = 'block_on_coverage_drop';
    public const FIELD_BYPASS_PROMOTION_GATE = 'bypass_promotion_gate';
    public const FIELD_BYPASS_REVIEW = 'bypass_review';
    public const FIELD_BYPASS_SOVEREIGNTY = 'bypass_sovereignty';
    public const FIELD_CHECKLIST_COMPLETION_HASH = 'checklist_completion_hash';
    public const FIELD_CLARIFICATION_LOG = 'clarification_log';
    public const FIELD_COMPLETENESS_REPORT_HASH = 'completeness_report_hash';
    public const FIELD_COVERAGE_REPORT_HASH = 'coverage_report_hash';
    public const FIELD_DEBUG_MTTR_P95 = 'debug_mttr_p95';
    public const FIELD_DEBUG_REPRO_SUCCESS_RATE = 'debug_repro_success_rate';
    public const FIELD_DELIVERY_COMPLETENESS_AVG = 'delivery_completeness_avg';
    public const FIELD_DELIVERY_PACK_HASH = 'delivery_pack_hash';
    public const FIELD_DELIVERY_REVIEW_LOOP_COUNT = 'delivery_review_loop_count';
    public const FIELD_DEPENDENCY_AUDIT_HASH = 'dependency_audit_hash';
    public const FIELD_DEPLOY_FIX_WITHOUT_REVIEW = 'deploy_fix_without_review';
    public const FIELD_DEV_RUN_DURATION_P95 = 'dev_run_duration_p95';
    public const FIELD_DEV_SCOPE_VIOLATION_COUNT = 'dev_scope_violation_count';
    public const FIELD_DRAFT_SPEC = 'draft_spec';
    public const FIELD_EDIT_ALLOWED_FILES = 'edit_allowed_files';
    public const FIELD_EDIT_SECURITY_POLICY = 'edit_security_policy';
    public const FIELD_EMIT_CONTEXT_PACK = 'emit_context_pack';
    public const FIELD_ESCALATE_TO_OPERATOR = 'escalate_to_operator';
    public const FIELD_EVIDENCE_PERSISTED = 'evidence_persisted';
    public const FIELD_EXECUTIVE_INTAKE_HAS_NO_UPSTREAM = 'executive_intake_has_no_upstream';
    public const FIELD_EXPOSE_SECRETS = 'expose_secrets';
    public const FIELD_FAILURE_CAPSULE_EMITTED = 'failure_capsule_emitted';
    public const FIELD_FETCH_SOURCES = 'fetch_sources';
    public const FIELD_FIXTURES_VERSIONED = 'fixtures_versioned';
    public const FIELD_FORGE_COLLISION_COUNT = 'forge_collision_count';
    public const FIELD_FORGE_OBRA_DURATION_P95 = 'forge_obra_duration_p95';
    public const FIELD_INTAKE_CLARITY_LOOP_COUNT = 'intake_clarity_loop_count';
    public const FIELD_INTAKE_CLASSIFICATION_LATENCY_P95 = 'intake_classification_latency_p95';
    public const FIELD_INTENT_CLARIFICATION_LOG = 'intent_clarification_log';
    public const FIELD_INTENT_CLARIFIED = 'intent_clarified';
    public const FIELD_INTENT_CLARITY_SCORE_MIN = 'intent_clarity_score_min';
    public const FIELD_MEMORY_PROMOTION_RATE = 'memory_promotion_rate';
    public const FIELD_MEMORY_QUARANTINE_COUNT = 'memory_quarantine_count';
    public const FIELD_MEMORY_RECORD_HASH = 'memory_record_hash';
    public const FIELD_MERGE_AFTER_REVIEW = 'merge_after_review';
    public const FIELD_MERGE_REVIEW_EVIDENCE_HASH = 'merge_review_evidence_hash';
    public const FIELD_MERGE_REVIEW_PROMOTION_PASSED = 'merge_review_promotion_passed';
    public const FIELD_MISSION_AUTHORITY_DECLARED = 'mission_authority_declared';
    public const FIELD_MISSION_ENVELOPE_HASH = 'mission_envelope_hash';
    public const FIELD_MODIFY_PRODUCTION_CODE_OUTSIDE_TESTS = 'modify_production_code_outside_tests';
    public const FIELD_MODIFY_PRODUCTION_DATA = 'modify_production_data';
    public const FIELD_NO_HALLUCINATED_LINKS = 'no_hallucinated_links';
    public const FIELD_NONE = 'none';
    public const FIELD_OBRA_INTAKE_VALIDATED = 'obra_intake_validated';
    public const FIELD_OBRA_PACK_HASH = 'obra_pack_hash';
    public const FIELD_PATCH_HASH = 'patch_hash';
    public const FIELD_PLAN_APPROVED = 'plan_approved';
    public const FIELD_POLICY_DECISION_HASH = 'policy_decision_hash';
    public const FIELD_PRODUCT_CLARITY_SCORE_AVG = 'product_clarity_score_avg';
    public const FIELD_PRODUCT_LOOP_COUNT_AVG = 'product_loop_count_avg';
    public const FIELD_PROMOTE_TO_MEMORY = 'promote_to_memory';
    public const FIELD_PROMOTION_EVIDENCE_HASH = 'promotion_evidence_hash';
    public const FIELD_PROPOSE_DOC_PROMOTION = 'propose_doc_promotion';
    public const FIELD_QA_COVERAGE_P50 = 'qa_coverage_p50';
    public const FIELD_QA_REGRESSION_CATCH_RATE = 'qa_regression_catch_rate';
    public const FIELD_READ_LOGS = 'read_logs';
    public const FIELD_REGRESSION_GREEN = 'regression_green';
    public const FIELD_RELEASE_AUTHORITY_DECLARED = 'release_authority_declared';
    public const FIELD_REPRO_STEPS_HASH = 'repro_steps_hash';
    public const FIELD_REQUEST_CHANGES = 'request_changes';
    public const FIELD_REQUEST_HUMAN_REVIEW = 'request_human_review';
    public const FIELD_REQUEST_OBSERVABILITY_QUERY = 'request_observability_query';
    public const FIELD_REQUEST_PROVIDER_CALL = 'request_provider_call';
    public const FIELD_RESEARCH_FINDINGS_PUBLISHED = 'research_findings_published';
    public const FIELD_RESEARCH_HALLUCINATION_COUNT = 'research_hallucination_count';
    public const FIELD_RESEARCH_PACK_HASH = 'research_pack_hash';
    public const FIELD_RESEARCH_SOURCE_FRESHNESS_AVG = 'research_source_freshness_avg';
    public const FIELD_REVIEW_FINDINGS_SEVERITY_AVG = 'review_findings_severity_avg';
    public const FIELD_REVIEW_PACKET_SIGNED = 'review_packet_signed';
    public const FIELD_REVIEW_REPORT_HASH = 'review_report_hash';
    public const FIELD_REVIEW_VETO_COUNT = 'review_veto_count';
    public const FIELD_RISK_SCOPE = 'risk_scope';
    public const FIELD_ROLLBACK_PER_SLICE = 'rollback_per_slice';
    public const FIELD_ROOT_CAUSE_EVIDENCE_PRESENT = 'root_cause_evidence_present';
    public const FIELD_ROOT_CAUSE_PACK_HASH = 'root_cause_pack_hash';
    public const FIELD_ROUTE_TO_DEPARTMENT = 'route_to_department';
    public const FIELD_SCHEMA_VERSIONED = 'schema_versioned';
    public const FIELD_SCOPE_GUARD_OK = 'scope_guard_ok';
    public const FIELD_SCOPE_GUARD_REPORT = 'scope_guard_report';
    public const FIELD_SECURITY_DENY_COUNT = 'security_deny_count';
    public const FIELD_SECURITY_SCAN_CLEAN = 'security_scan_clean';
    public const FIELD_SECURITY_SECRET_FINDING_COUNT = 'security_secret_finding_count';
    public const FIELD_SHIP_WITHOUT_CERT = 'ship_without_cert';
    public const FIELD_SHIP_WITHOUT_EVIDENCE = 'ship_without_evidence';
    public const FIELD_SOURCES_LIST_HASH = 'sources_list_hash';
    public const FIELD_SOVEREIGNTY_BOUNDARY_RESPECTED = 'sovereignty_boundary_respected';
    public const FIELD_SPAWN_AGENTS = 'spawn_agents';
    public const FIELD_SPLIT_INTENT = 'split_intent';
    public const FIELD_TEST_PACK_HASH = 'test_pack_hash';
    public const FIELD_VETO_EXECUTION = 'veto_execution';
    public const FIELD_VETO_RELEASE = 'veto_release';
    public const FIELD_WRITE_TESTS = 'write_tests';
    public const FIELD_ALL_15_UNIVERSAL_GATES = 'all-15-universal-gates';
    public const FIELD_L2 = 'L2';
    public const FIELD_L3 = 'L3';
    public const FIELD_ARCHITECT_DEPARTMENT = 'Architect Department';
    public const FIELD_DEBUG_DEPARTMENT = 'Debug Department';
    public const FIELD_DELIVERY_DEPARTMENT = 'Delivery Department';
    public const FIELD_DEV_DEPARTMENT = 'Dev Department';
    public const FIELD_EXECUTIVE_INTAKE = 'Executive Intake';
    public const FIELD_FORGE_DEPARTMENT = 'Forge Department';
    public const FIELD_L1 = 'L1';
    public const FIELD_L4 = 'L4';
    public const FIELD_MEMORY_DEPARTMENT = 'Memory Department';
    public const FIELD_PRODUCT_DEPARTMENT = 'Product Department';
    public const FIELD_QA_DEPARTMENT = 'QA Department';
    public const FIELD_R0 = 'R0';
    public const FIELD_R1 = 'R1';
    public const FIELD_R2 = 'R2';
    public const FIELD_R3 = 'R3';
    public const FIELD_R4 = 'R4';
    public const FIELD_R5 = 'R5';
    public const FIELD_RESEARCH_DEPARTMENT = 'Research Department';
    public const FIELD_REVIEW_DEPARTMENT = 'Review Department';
    public const FIELD_SECURITY_DEPARTMENT = 'Security Department';
    public const FIELD_DELIVERY_PACK_ASSEMBLED_TRUE = 'delivery_pack_assembled=true';
    public const FIELD_ARCHITECT_RESEARCH_NEEDED_TRUE = 'architect.research_needed=true';
    public const FIELD_BREAKING_CHANGE_DETECTED_TRUE = 'breaking_change_detected=true';
    public const FIELD_CONTEXT_PACK_REQUEST_TRUE = 'context_pack_request=true';
    public const FIELD_EVIDENCE_PACK_READY_TRUE = 'evidence_pack_ready=true';
    public const FIELD_EXECUTION_COMPLETE_TRUE = 'execution_complete=true';
    public const FIELD_INCIDENT_DETECTED_TRUE = 'incident_detected=true';
    public const FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_DEV = 'intent_classification.target_department=dev';
    public const FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_FORGE = 'intent_classification.target_department=forge';
    public const FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_PRODUCT = 'intent_classification.target_department=product';
    public const FIELD_LEARNING_CAPSULE_EMITTED_TRUE = 'learning_capsule_emitted=true';
    public const FIELD_MULTI_MODULE_DETECTED_TRUE = 'multi_module_detected=true';
    public const FIELD_OPERATOR_INTENT_RAW_RECEIVED_TRUE = 'operator_intent_raw_received=true';
    public const FIELD_PRODUCTION_ALERT_TRUE = 'production_alert=true';
    public const FIELD_RELEASE_PACK_DRAFTED_TRUE = 'release_pack_drafted=true';
    public const FIELD_SECURITY_PATH_TOUCHED_TRUE = 'security_path_touched=true';
    public const FIELD_SELF_CONSTRUCTION_GAP_DETECTED_TRUE = 'self_construction.gap_detected=true';
    public const FIELD_SESSION_HANDOFF_REQUESTED_TRUE = 'session_handoff_requested=true';
    public const FIELD_SPEC_PACK_DRAFTED_TRUE = 'spec_pack_drafted=true';
    public const FIELD_TASK_PACK_DECOMPOSED_TRUE = 'task_pack_decomposed=true';
    public const FIELD_TEST_RED_AFTER_GREEN_TRUE = 'test_red_after_green=true';
    public const FIELD_ATLAS_DEV_FAST_LANE__SMALL_MEDIUM_CHANGES_WITH_PLAN___GATES_ = 'Atlas Dev fast-lane; small/medium changes with plan + gates.';
    public const FIELD_CODE_SPEC_REVIEW__BOTTLENECK_AGAINST_WEAK_CLAIMS_ = 'Code/spec review; bottleneck against weak claims.';
    public const FIELD_DECIDES_SYSTEM_DESIGN__ADRS__TECHNICAL_BOUNDARIES_ = 'Decides system design, ADRs, technical boundaries.';
    public const FIELD_EVIDENCE_LEDGER__LEARNING__COMPOUNDING_SIGNAL_EXTRACTION_ = 'Evidence ledger, learning, compounding signal extraction.';
    public const FIELD_FAILURE_INVESTIGATION__REPAIR_ORCHESTRATION__ESCALATION_TRIGGERS_ = 'Failure investigation, repair orchestration, escalation triggers.';
    public const FIELD_HEAVY_OBRAS_WITH_PROVIDER_TOPOLOGY___MULTI_AGENT_SCHEDULER_ = 'Heavy Obras with provider topology + multi-agent scheduler.';
    public const FIELD_INVESTIGATES_UNKNOWNS_BEFORE_COMMIT__NEVER_MODIFIES_RUNTIME_ = 'Investigates unknowns before commit; never modifies runtime.';
    public const FIELD_RECEIVES_AMBIGUOUS_HUMAN_INTENT__EMITS_CANONICAL_MISSION_ENVELOPE_ = 'Receives ambiguous human intent; emits canonical mission envelope.';
    public const FIELD_RELEASE__ROLLBACK_DECISION__DEPLOYMENT_EVIDENCE_ = 'Release, rollback decision, deployment evidence.';
    public const FIELD_SECURITY_REVIEW__OWASP__SECRETS__DEPENDENCY_CVES_ = 'Security review; OWASP, secrets, dependency CVEs.';
    public const FIELD_TEST_SELECTION__REGRESSION__VERIFICATION_ = 'Test selection, regression, verification.';
    public const FIELD_TURNS_INTENT_INTO_PRODUCT_SPEC___ACCEPTANCE_CRITERIA_ = 'Turns intent into product spec + acceptance criteria.';
    public const FIELD_EXECUTA_FAST_PATH_PARA_INTENTS_R1_R3__1_5_ARQUIVOS__BAIXO_M_DIO_RISCO__COM_GOVERNANCE_LEVE = 'executa fast-path para intents R1-R3 (1-5 arquivos, baixo-médio risco) com governance leve';
    public const FIELD_PRODUZ_STATE_OF_THE_ART_SOURCE_BACKED_PARA_SUPORTAR_ARCHITECT_E_SELF_CONSTRUCTION = 'produz state-of-the-art source-backed para suportar Architect e Self-Construction';
    public const FIELD_DEPARTMENT___S__DOES_NOT_EMIT_HANDOFF_TO___S___ALLOWED___S_ = 'department "%s" does not emit handoff to "%s" (allowed: %s)';
    public const FIELD_GARANTE_TESTABILIDADE__COBERTURA__REGRESS_O__CONTRACT_TESTS_E_FIXTURES = 'garante testabilidade, cobertura, regressão, contract tests e fixtures';
    public const FIELD_INVESTIGA_FALHAS_RUNTIME__GERA_HIP_TESES__REPRODUZ__ISOLA_E_PROP_E_FIX = 'investiga falhas runtime, gera hipóteses, reproduz, isola e propõe fix';
    public const FIELD_MONTA_DELIVERY_PACK_CAN_NICO__VALIDA_COMPLETENESS__ENCAMINHA_PARA_HUMAN_REVIEW_E_CERT = 'monta delivery_pack canônico, valida completeness, encaminha para human review e cert';
    public const FIELD_RECEBE_PEDIDO_HUMANO_AMB_GUO_E_PRODUZ_MISSION_ENVELOPE_CAN_NICA_ANTES_DE_PRODUCT = 'recebe pedido humano ambíguo e produz mission envelope canônica antes de product';
    public const FIELD_REVISA_PATCHES_SPECS_MIGRATIONS_RELEASE_PACKS_COM_CHECKLIST_CAN_NICO_ANTES_DE_CERT = 'revisa patches/specs/migrations/release_packs com checklist canônico antes de cert';
    public const FIELD_EXECUTA_OBRAS_PESADAS_MULTI_M_DULO_R3_R5_COM_PARALELISMO__DURABLE_RESERVATION__MULTI_PROVIDER = 'executa Obras pesadas multi-módulo R3-R5 com paralelismo, durable reservation, multi-provider';
    public const FIELD_DEFINE_SPEC_PACK_CAN_NICO__BREAKING_CHANGE_MATRIX_E_MIGRATION_PLAN_ANTES_DE_QUALQUER_EXECU__O = 'define spec_pack canônico, breaking_change_matrix e migration_plan antes de qualquer execução';
    public const FIELD_ENFORCE_DE_POLICY__THREAT_MODELING__SECRET_SCANNING__DEPENDENCY_AUDIT__SOVEREIGNTY_BOUNDARY = 'enforce de policy, threat-modeling, secret scanning, dependency audit, sovereignty boundary';
    public const FIELD_PERSIST_NCIA_GOVERNADA_DE_LEARNINGS__CONTEXT_PACKS__DECIS_ES__FALHAS__CROSS_SESSION_CONTINUITY = 'persistência governada de learnings, context packs, decisões, falhas, cross-session continuity';
    public const FIELD_TRADUZ_INTEN__O_HUMANA_AMB_GUA_EM_ENGINEERING_GOAL_DISAMBIGUADO_COM_CRIT_RIOS_DE_ACEITA__O_MENSUR_VEIS = 'traduz intenção humana ambígua em engineering_goal disambiguado com critérios de aceitação mensuráveis';
    public const INT_11 = 11;

    /**
     * The 12 canonical fields every department must declare. Used by the
     * `schema-fields-12-present` quality gate.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = [
        self::FIELD_HUMAN_NAME,
        self::FIELD_SCOPE,
        self::FIELD_TRIGGERS,
        self::FIELD_INPUTS,
        self::FIELD_OUTPUTS,
        self::FIELD_GATES,
        self::FIELD_ALLOWED_ACTIONS,
        self::FIELD_FORBIDDEN_ACTIONS,
        self::FIELD_ESCALATION_TO,
        self::FIELD_EVIDENCE_REQUIRED,
        self::FIELD_PERSISTENCE,
        self::FIELD_OBSERVABILITY_SIGNALS,
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
            self::FIELD_HUMAN_NAME => self::FIELD_EXECUTIVE_INTAKE,
            self::FIELD_DESCRIPTION => self::FIELD_RECEIVES_AMBIGUOUS_HUMAN_INTENT__EMITS_CANONICAL_MISSION_ENVELOPE_,
            self::FIELD_SCOPE => self::FIELD_RECEBE_PEDIDO_HUMANO_AMB_GUO_E_PRODUZ_MISSION_ENVELOPE_CAN_NICA_ANTES_DE_PRODUCT,
            self::FIELD_TRIGGERS => [self::FIELD_OPERATOR_INTENT_RAW_RECEIVED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_INTENT_RAW, self::FIELD_SCHEMA => self::SCHEMA_INTENT_RAW],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_MISSION_ENVELOPE, self::FIELD_SCHEMA => self::SCHEMA_AI_MISSION],
            ],
            self::FIELD_GATES => [self::FIELD_INTENT_CLARIFIED, self::FIELD_MISSION_AUTHORITY_DECLARED],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_ASK_CLARIFYING_QUESTION, self::FIELD_CLASSIFY_INTENT, self::FIELD_ROUTE_TO_DEPARTMENT],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_WRITE_CODE, self::FIELD_APPROVE_RELEASE, self::FIELD_MODIFY_SECURITY_POLICY],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_INTENT_CLARIFICATION_LOG, self::FIELD_MISSION_ENVELOPE_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_EXECUTIVE_INTAKE, self::FIELD_LEDGER => self::FIELD_AAEOS_INTAKE_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_INTAKE_CLARITY_LOOP_COUNT, self::FIELD_INTAKE_CLASSIFICATION_LATENCY_P95],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_AI_MISSION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_PRODUCT, self::DEPARTMENT_ARCHITECTURE],
        ],
        self::DEPARTMENT_PRODUCT => [
            self::FIELD_HUMAN_NAME => self::FIELD_PRODUCT_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_TURNS_INTENT_INTO_PRODUCT_SPEC___ACCEPTANCE_CRITERIA_,
            self::FIELD_SCOPE => self::FIELD_TRADUZ_INTEN__O_HUMANA_AMB_GUA_EM_ENGINEERING_GOAL_DISAMBIGUADO_COM_CRIT_RIOS_DE_ACEITA__O_MENSUR_VEIS,
            self::FIELD_TRIGGERS => [self::FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_PRODUCT],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_ENGINEERING_GOAL_RAW, self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_ENGINEERING_GOAL_DISAMBIGUATED, self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED],
                [self::FIELD_NAME => self::FIELD_ACCEPTANCE_CRITERIA, self::FIELD_SCHEMA => self::SCHEMA_ACCEPTANCE_CRITERIA],
            ],
            self::FIELD_GATES => [self::FIELD_INTENT_CLARITY_SCORE_MIN, self::FIELD_ACCEPTANCE_CRITERIA_MIN_3],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_ASK_CLARIFYING_QUESTION, self::FIELD_PROPOSE_ACCEPTANCE_CRITERIA, self::FIELD_SPLIT_INTENT],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_WRITE_CODE, self::FIELD_APPROVE_RELEASE, self::FIELD_MODIFY_SECURITY_POLICY],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_CLARIFICATION_LOG, self::FIELD_ACCEPTANCE_CRITERIA_PACK],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_ENGINEERING_GOALS, self::FIELD_LEDGER => self::FIELD_AAEOS_CLARIFICATION_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_PRODUCT_CLARITY_SCORE_AVG, self::FIELD_PRODUCT_LOOP_COUNT_AVG],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_MINI_PROGRAMMING_SPEC,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_EXECUTIVE_INTAKE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_ARCHITECTURE],
        ],
        self::DEPARTMENT_ARCHITECTURE => [
            self::FIELD_HUMAN_NAME => self::FIELD_ARCHITECT_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_DECIDES_SYSTEM_DESIGN__ADRS__TECHNICAL_BOUNDARIES_,
            self::FIELD_SCOPE => self::FIELD_DEFINE_SPEC_PACK_CAN_NICO__BREAKING_CHANGE_MATRIX_E_MIGRATION_PLAN_ANTES_DE_QUALQUER_EXECU__O,
            self::FIELD_TRIGGERS => ['intent_classification.scope>=R3', self::FIELD_BREAKING_CHANGE_DETECTED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_ENGINEERING_GOAL_DISAMBIGUATED, self::FIELD_SCHEMA => self::SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_SPEC_PACK, self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => self::FIELD_MIGRATION_PLAN, self::FIELD_SCHEMA => self::SCHEMA_MIGRATION_PLAN],
            ],
            self::FIELD_GATES => [self::FIELD_ADR_PUBLISHED, self::FIELD_BOUNDARY_VALIDATED, self::FIELD_SPEC_ACCEPTANCE_CRITERIA_COMPLETE, self::FIELD_BREAKING_CHANGE_DOCUMENTED, self::FIELD_ROLLBACK_PER_SLICE],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_DRAFT_SPEC, self::FIELD_PROPOSE_MIGRATION_PLAN, self::FIELD_REQUEST_SECURITY_REVIEW, self::FIELD_VETO_EXECUTION],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_WRITE_CODE, self::FIELD_EXECUTE_MIGRATION, self::FIELD_APPROVE_RELEASE],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_SPEC_PACK_HASH, self::FIELD_ARCHITECT_DECISION_RECEIPT],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_SPEC_PACKS, self::FIELD_LEDGER => self::FIELD_AAEOS_ARCHITECT_DECISION_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_ARCHITECT_SPEC_COMPLETENESS_SCORE, self::FIELD_ARCHITECT_VETO_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_ENGINEERING_ARCHITECTURE_DECISION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_PRODUCT],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_RESEARCH => [
            self::FIELD_HUMAN_NAME => self::FIELD_RESEARCH_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_INVESTIGATES_UNKNOWNS_BEFORE_COMMIT__NEVER_MODIFIES_RUNTIME_,
            self::FIELD_SCOPE => self::FIELD_PRODUZ_STATE_OF_THE_ART_SOURCE_BACKED_PARA_SUPORTAR_ARCHITECT_E_SELF_CONSTRUCTION,
            self::FIELD_TRIGGERS => [self::FIELD_SELF_CONSTRUCTION_GAP_DETECTED_TRUE, self::FIELD_ARCHITECT_RESEARCH_NEEDED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_RESEARCH_QUESTION, self::FIELD_SCHEMA => self::SCHEMA_RESEARCH_QUESTION],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_RESEARCH_PACK, self::FIELD_SCHEMA => self::SCHEMA_RESEARCH_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_RESEARCH_FINDINGS_PUBLISHED, self::FIELD_REVIEW_ONLY_ACKNOWLEDGED, self::FIELD_SOURCES_MIN_3, self::FIELD_SOURCE_DATES_RECENT, self::FIELD_NO_HALLUCINATED_LINKS],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_FETCH_SOURCES, self::FIELD_SYNTHESIZE_FINDINGS, self::FIELD_PROPOSE_DOC_PROMOTION],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_WRITE_CODE, self::FIELD_APPROVE_RELEASE, self::FIELD_MODIFY_SECURITY_POLICY],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_SOURCES_LIST_HASH, self::FIELD_RESEARCH_PACK_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_RESEARCH_PACKS, self::FIELD_LEDGER => self::FIELD_AAEOS_SOURCE_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_RESEARCH_SOURCE_FRESHNESS_AVG, self::FIELD_RESEARCH_HALLUCINATION_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_RESEARCH_FINDINGS,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_PRODUCT],
        ],
        self::DEPARTMENT_DEV => [
            self::FIELD_HUMAN_NAME => self::FIELD_DEV_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_ATLAS_DEV_FAST_LANE__SMALL_MEDIUM_CHANGES_WITH_PLAN___GATES_,
            self::FIELD_SCOPE => self::FIELD_EXECUTA_FAST_PATH_PARA_INTENTS_R1_R3__1_5_ARQUIVOS__BAIXO_M_DIO_RISCO__COM_GOVERNANCE_LEVE,
            self::FIELD_TRIGGERS => [self::FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_DEV, 'scope<=R3'],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_SPEC_PACK, self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => self::FIELD_TASK_PACK, self::FIELD_SCHEMA => self::SCHEMA_TASK_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_EXECUTION_LOG, self::FIELD_SCHEMA => self::SCHEMA_EXECUTION_LOG],
                [self::FIELD_NAME => self::FIELD_PATCH_PACK, self::FIELD_SCHEMA => self::SCHEMA_PATCH_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_PLAN_APPROVED, self::FIELD_TESTS_FOCUSED, self::FIELD_REVIEW_GATE, self::FIELD_LINT_GREEN, self::FIELD_TYPECHECK_GREEN, self::FIELD_TESTS_GREEN, self::FIELD_SCOPE_GUARD_OK],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_EDIT_ALLOWED_FILES, self::FIELD_RUN_TESTS, self::FIELD_REQUEST_PROVIDER_CALL],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_EDIT_SECURITY_POLICY, self::FIELD_MODIFY_MIGRATIONS_WITHOUT_ARCHITECT, self::FIELD_APPROVE_RELEASE],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW, self::DEPARTMENT_FORGE],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_PATCH_HASH, self::FIELD_TEST_OUTPUT_HASH, self::FIELD_SCOPE_GUARD_REPORT],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_DEV_RUNS, self::FIELD_LEDGER => self::FIELD_AAEOS_DEV_EVIDENCE_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_DEV_RUN_DURATION_P95, self::FIELD_DEV_REPAIR_LOOP_COUNT, self::FIELD_DEV_SCOPE_VIOLATION_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L1,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_PLAN_VISIBLE,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_REVIEW, self::FIELD_QA, self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_DEBUG => [
            self::FIELD_HUMAN_NAME => self::FIELD_DEBUG_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_FAILURE_INVESTIGATION__REPAIR_ORCHESTRATION__ESCALATION_TRIGGERS_,
            self::FIELD_SCOPE => self::FIELD_INVESTIGA_FALHAS_RUNTIME__GERA_HIP_TESES__REPRODUZ__ISOLA_E_PROP_E_FIX,
            self::FIELD_TRIGGERS => [self::FIELD_INCIDENT_DETECTED_TRUE, self::FIELD_TEST_RED_AFTER_GREEN_TRUE, self::FIELD_PRODUCTION_ALERT_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_FAILURE_REPORT, self::FIELD_SCHEMA => self::SCHEMA_FAILURE_REPORT],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_ROOT_CAUSE_PACK, self::FIELD_SCHEMA => self::SCHEMA_ROOT_CAUSE_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_FAILURE_CAPSULE_EMITTED, self::FIELD_REPAIR_BUDGET_RESPECTED, self::FIELD_REPRODUCTION_CONFIRMED, self::FIELD_ROOT_CAUSE_EVIDENCE_PRESENT],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_READ_LOGS, self::FIELD_RUN_REPRO, self::FIELD_REQUEST_OBSERVABILITY_QUERY],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_MODIFY_PRODUCTION_DATA, self::FIELD_DEPLOY_FIX_WITHOUT_REVIEW],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW, self::DEPARTMENT_SECURITY],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_REPRO_STEPS_HASH, self::FIELD_LOGS_HASH, self::FIELD_ROOT_CAUSE_PACK_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_DEBUG_INVESTIGATIONS, self::FIELD_LEDGER => self::FIELD_AAEOS_DEBUG_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_DEBUG_MTTR_P95, self::FIELD_DEBUG_REPRO_SUCCESS_RATE],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_DEBUG_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::FIELD_QA, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
        ],
        self::DEPARTMENT_REVIEW => [
            self::FIELD_HUMAN_NAME => self::FIELD_REVIEW_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_CODE_SPEC_REVIEW__BOTTLENECK_AGAINST_WEAK_CLAIMS_,
            self::FIELD_SCOPE => self::FIELD_REVISA_PATCHES_SPECS_MIGRATIONS_RELEASE_PACKS_COM_CHECKLIST_CAN_NICO_ANTES_DE_CERT,
            self::FIELD_TRIGGERS => [self::FIELD_DELIVERY_PACK_ASSEMBLED_TRUE, self::FIELD_SPEC_PACK_DRAFTED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_DELIVERY_PACK, self::FIELD_SCHEMA => self::SCHEMA_DELIVERY_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_REVIEW_REPORT, self::FIELD_SCHEMA => self::SCHEMA_REVIEW_REPORT],
            ],
            self::FIELD_GATES => [self::FIELD_REVIEW_PACKET_SIGNED, self::FIELD_RISK_ACKNOWLEDGED, self::FIELD_REVIEW_CHECKLIST_COMPLETE, self::FIELD_BLOCKERS_ADDRESSED, self::FIELD_EVIDENCE_TRACEABLE],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_REQUEST_CHANGES, self::FIELD_APPROVE_FOR_CERT, self::FIELD_VETO_RELEASE],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_EDIT_CODE, self::FIELD_DEPLOY_RELEASE, self::FIELD_MODIFY_SECURITY_POLICY],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_REVIEW_REPORT_HASH, self::FIELD_CHECKLIST_COMPLETION_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_REVIEW_REPORTS, self::FIELD_LEDGER => self::FIELD_AAEOS_REVIEW_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_REVIEW_FINDINGS_SEVERITY_AVG, self::FIELD_REVIEW_VETO_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_REVIEW_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::FIELD_QA, self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_QA => [
            self::FIELD_HUMAN_NAME => self::FIELD_QA_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_TEST_SELECTION__REGRESSION__VERIFICATION_,
            self::FIELD_SCOPE => self::FIELD_GARANTE_TESTABILIDADE__COBERTURA__REGRESS_O__CONTRACT_TESTS_E_FIXTURES,
            self::FIELD_TRIGGERS => [self::FIELD_TASK_PACK_DECOMPOSED_TRUE, self::FIELD_DELIVERY_PACK_ASSEMBLED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_SPEC_PACK, self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => self::FIELD_PATCH_PACK, self::FIELD_SCHEMA => self::SCHEMA_PATCH_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_TEST_PACK, self::FIELD_SCHEMA => self::SCHEMA_TEST_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_REGRESSION_GREEN, self::FIELD_VERIFICATION_COMPLETE, self::FIELD_COVERAGE_MIN_THRESHOLD, self::FIELD_REGRESSION_TESTS_ADDED, self::FIELD_FIXTURES_VERSIONED],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_WRITE_TESTS, self::FIELD_REQUEST_TEST_DATA, self::FIELD_BLOCK_ON_COVERAGE_DROP],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_MODIFY_PRODUCTION_CODE_OUTSIDE_TESTS, self::FIELD_APPROVE_RELEASE],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_DEV, self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_TEST_PACK_HASH, self::FIELD_COVERAGE_REPORT_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_TEST_PACKS, self::FIELD_LEDGER => self::FIELD_AAEOS_QA_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_QA_COVERAGE_P50, self::FIELD_QA_REGRESSION_CATCH_RATE],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_DEV_TEST_SELECTION_RECEIPT,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_SECURITY => [
            self::FIELD_HUMAN_NAME => self::FIELD_SECURITY_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_SECURITY_REVIEW__OWASP__SECRETS__DEPENDENCY_CVES_,
            self::FIELD_SCOPE => self::FIELD_ENFORCE_DE_POLICY__THREAT_MODELING__SECRET_SCANNING__DEPENDENCY_AUDIT__SOVEREIGNTY_BOUNDARY,
            self::FIELD_TRIGGERS => [self::FIELD_SECURITY_PATH_TOUCHED_TRUE, 'intent_class_in=[sensitive,secret,cyber]', self::FIELD_RELEASE_PACK_DRAFTED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_POLICY_REQUEST, self::FIELD_SCHEMA => self::SCHEMA_POLICY_REQUEST],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_POLICY_DECISION, self::FIELD_SCHEMA => self::SCHEMA_POLICY_DECISION],
            ],
            self::FIELD_GATES => [self::FIELD_SECURITY_SCAN_CLEAN, self::FIELD_CVE_ACKNOWLEDGED, self::FIELD_SECRET_SCAN_CLEAN, self::FIELD_DEPENDENCY_AUDIT_CLEAN, self::FIELD_THREAT_MODEL_PRESENT, self::FIELD_SOVEREIGNTY_BOUNDARY_RESPECTED],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_ALLOW, self::FIELD_DENY, self::FIELD_REQUEST_MITIGATION, self::FIELD_ESCALATE_TO_OPERATOR],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_BYPASS_SOVEREIGNTY, self::FIELD_APPROVE_UNAUDITED_DEP, self::FIELD_SHIP_WITHOUT_EVIDENCE],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_POLICY_DECISION_HASH, self::FIELD_SECRET_SCAN_REPORT_HASH, self::FIELD_DEPENDENCY_AUDIT_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_POLICY_DECISIONS, self::FIELD_LEDGER => self::FIELD_AAEOS_SECURITY_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_SECURITY_DENY_COUNT, self::FIELD_SECURITY_SECRET_FINDING_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_SECURITY_FINDING,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DEV, self::DEPARTMENT_REVIEW, self::DEPARTMENT_FORGE],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_FORGE => [
            self::FIELD_HUMAN_NAME => self::FIELD_FORGE_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_HEAVY_OBRAS_WITH_PROVIDER_TOPOLOGY___MULTI_AGENT_SCHEDULER_,
            self::FIELD_SCOPE => self::FIELD_EXECUTA_OBRAS_PESADAS_MULTI_M_DULO_R3_R5_COM_PARALELISMO__DURABLE_RESERVATION__MULTI_PROVIDER,
            self::FIELD_TRIGGERS => [self::FIELD_INTENT_CLASSIFICATION_TARGET_DEPARTMENT_FORGE, 'scope>=R3', self::FIELD_MULTI_MODULE_DETECTED_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_SPEC_PACK, self::FIELD_SCHEMA => self::SCHEMA_SPEC_PACK],
                [self::FIELD_NAME => self::FIELD_TOPOLOGY_PLAN, self::FIELD_SCHEMA => self::SCHEMA_TOPOLOGY_PLAN],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_OBRA_PACK, self::FIELD_SCHEMA => self::SCHEMA_OBRA_PACK],
                [self::FIELD_NAME => self::FIELD_EXECUTION_LOG, self::FIELD_SCHEMA => self::SCHEMA_EXECUTION_LOG],
            ],
            self::FIELD_GATES => [self::FIELD_OBRA_INTAKE_VALIDATED, self::FIELD_PROVIDER_TOPOLOGY_GREEN, self::FIELD_ALL_15_UNIVERSAL_GATES, self::FIELD_LONG_HORIZON_STATE_PERSISTED, self::FIELD_RESERVATION_LEDGER_CONSISTENT, self::FIELD_MERGE_REVIEW_PROMOTION_PASSED],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_SPAWN_AGENTS, self::FIELD_CLAIM_RESERVATIONS, self::FIELD_REQUEST_PROVIDER_TOPOLOGY, self::FIELD_MERGE_AFTER_REVIEW],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_BYPASS_REVIEW, self::FIELD_MODIFY_SECURITY_POLICY, self::FIELD_SHIP_WITHOUT_CERT],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_ARCHITECT, self::DEPARTMENT_REVIEW, self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_OBRA_PACK_HASH, self::FIELD_EXECUTION_LOG_HASH, self::FIELD_MERGE_REVIEW_EVIDENCE_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_OBRA_RUNS, self::FIELD_LEDGER => self::FIELD_AAEOS_FORGE_EVIDENCE_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_FORGE_OBRA_DURATION_P95, self::FIELD_FORGE_PARALLEL_AGENT_COUNT, self::FIELD_FORGE_COLLISION_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L4,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_PROGRAMMING_DURABLE_EXECUTION_HANDOFF,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_ARCHITECTURE, self::DEPARTMENT_DEV],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_REVIEW, self::FIELD_QA, self::DEPARTMENT_DELIVERY],
        ],
        self::DEPARTMENT_DELIVERY => [
            self::FIELD_HUMAN_NAME => self::FIELD_DELIVERY_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_RELEASE__ROLLBACK_DECISION__DEPLOYMENT_EVIDENCE_,
            self::FIELD_SCOPE => self::FIELD_MONTA_DELIVERY_PACK_CAN_NICO__VALIDA_COMPLETENESS__ENCAMINHA_PARA_HUMAN_REVIEW_E_CERT,
            self::FIELD_TRIGGERS => [self::FIELD_EXECUTION_COMPLETE_TRUE, self::FIELD_EVIDENCE_PACK_READY_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_EVIDENCE_PACK, self::FIELD_SCHEMA => self::SCHEMA_EVIDENCE_PACK],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_DELIVERY_PACK, self::FIELD_SCHEMA => self::SCHEMA_DELIVERY_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_RELEASE_AUTHORITY_DECLARED, self::FIELD_ROLLBACK_PLAN_PRESENT, self::FIELD_DELIVERY_PACK_COMPLETENESS_MIN_0_95, self::FIELD_EVIDENCE_TRACEABLE],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_ASSEMBLE_DELIVERY_PACK, self::FIELD_SIGN_DELIVERY_HASH, self::FIELD_REQUEST_HUMAN_REVIEW],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_EDIT_CODE, self::FIELD_APPROVE_RELEASE_WITHOUT_REVIEW, self::FIELD_MODIFY_SECURITY_POLICY],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_REVIEW, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_DELIVERY_PACK_HASH, self::FIELD_COMPLETENESS_REPORT_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_DELIVERY_PACKS, self::FIELD_LEDGER => self::FIELD_AAEOS_DELIVERY_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_DELIVERY_COMPLETENESS_AVG, self::FIELD_DELIVERY_REVIEW_LOOP_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_ENGINEERING_RELEASE_DECISION,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_REVIEW, self::FIELD_QA, self::DEPARTMENT_SECURITY],
            self::FIELD_EMITS_HANDOFF_TO => [self::DEPARTMENT_MEMORY],
        ],
        self::DEPARTMENT_MEMORY => [
            self::FIELD_HUMAN_NAME => self::FIELD_MEMORY_DEPARTMENT,
            self::FIELD_DESCRIPTION => self::FIELD_EVIDENCE_LEDGER__LEARNING__COMPOUNDING_SIGNAL_EXTRACTION_,
            self::FIELD_SCOPE => self::FIELD_PERSIST_NCIA_GOVERNADA_DE_LEARNINGS__CONTEXT_PACKS__DECIS_ES__FALHAS__CROSS_SESSION_CONTINUITY,
            self::FIELD_TRIGGERS => [self::FIELD_LEARNING_CAPSULE_EMITTED_TRUE, self::FIELD_SESSION_HANDOFF_REQUESTED_TRUE, self::FIELD_CONTEXT_PACK_REQUEST_TRUE],
            self::FIELD_INPUTS => [
                [self::FIELD_NAME => self::FIELD_LEARNING_CAPSULE, self::FIELD_SCHEMA => self::SCHEMA_LEARNING_CAPSULE],
            ],
            self::FIELD_OUTPUTS => [
                [self::FIELD_NAME => self::FIELD_MEMORY_RECORD, self::FIELD_SCHEMA => self::SCHEMA_MEMORY_RECORD],
                [self::FIELD_NAME => self::FIELD_CONTEXT_PACK, self::FIELD_SCHEMA => self::SCHEMA_CONTEXT_PACK],
            ],
            self::FIELD_GATES => [self::FIELD_EVIDENCE_PERSISTED, self::FIELD_LEARNING_SIGNAL_EXTRACTED, self::FIELD_PROMOTION_GATE_PASSED, self::FIELD_NOISE_IMMUNITY_CHECK_OK, self::FIELD_SCHEMA_VERSIONED],
            self::FIELD_ALLOWED_ACTIONS => [self::FIELD_PROMOTE_TO_MEMORY, self::FIELD_QUARANTINE_CAPSULE, self::FIELD_EMIT_CONTEXT_PACK],
            self::FIELD_FORBIDDEN_ACTIONS => [self::FIELD_BYPASS_PROMOTION_GATE, self::FIELD_MODIFY_EVIDENCE_LEDGER, self::FIELD_EXPOSE_SECRETS],
            self::FIELD_ESCALATION_TO => [self::DEPARTMENT_SECURITY, self::DEPARTMENT_OPERATOR],
            self::FIELD_EVIDENCE_REQUIRED => [self::FIELD_PROMOTION_EVIDENCE_HASH, self::FIELD_MEMORY_RECORD_HASH],
            self::FIELD_PERSISTENCE => [self::FIELD_PRIMARY_TABLE => self::FIELD_AAEOS_MEMORY_RECORDS, self::FIELD_LEDGER => self::FIELD_AAEOS_MEMORY_LEDGER],
            self::FIELD_OBSERVABILITY_SIGNALS => [self::FIELD_MEMORY_PROMOTION_RATE, self::FIELD_MEMORY_QUARANTINE_COUNT],
            self::FIELD_MATURITY_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE_SCHEMA => self::SCHEMA_LEARNING_COMPOUNDING_SIGNAL,
            self::FIELD_ACCEPTS_HANDOFF_FROM => [self::DEPARTMENT_DELIVERY, self::DEPARTMENT_REVIEW, self::FIELD_QA, self::DEPARTMENT_FORGE],
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENT_COUNT => count(self::CATALOGUE),
            self::FIELD_CANON_DEPARTMENT_COUNT => self::INT_11,
            self::FIELD_DEPARTMENTS => self::CATALOGUE,
            self::FIELD_HANDOFF_INVARIANTS => [
                self::FIELD_EXECUTIVE_INTAKE_HAS_NO_UPSTREAM,
                self::FIELD_MEMORY_HAS_NO_DOWNSTREAM,
                self::FIELD_EVERY_DEPARTMENT_DECLARES_GATES,
                self::FIELD_EVERY_DEPARTMENT_DECLARES_EVIDENCE_SCHEMA,
                self::FIELD_EVERY_DEPARTMENT_DECLARES_12_CANON_FIELDS,
            ],
            self::FIELD_EVIDENCE_COUNT => count(array_unique(array_column(self::CATALOGUE, self::FIELD_EVIDENCE_SCHEMA))),
            self::FIELD_SCHEMA_FIELDS_12_PRESENT => $this->schemaFields12Present(),
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
            return [self::FIELD_FROM => $from, self::FIELD_TO => $to, self::FIELD_ACCEPTED => false, self::FIELD_REASON => "unknown department '{$from}'"];
        }
        if (! isset(self::CATALOGUE[$to])) {
            return [self::FIELD_FROM => $from, self::FIELD_TO => $to, self::FIELD_ACCEPTED => false, self::FIELD_REASON => "unknown department '{$to}'"];
        }
        $allowedDownstream = AiValueNormalizer::arrayOrEmpty(self::CATALOGUE[$from][self::FIELD_EMITS_HANDOFF_TO] ?? null);
        if (! in_array($to, $allowedDownstream, true)) {
            return [
                self::FIELD_FROM => $from,
                self::FIELD_TO => $to,
                self::FIELD_ACCEPTED => false,
                self::FIELD_REASON => sprintf(self::FIELD_DEPARTMENT___S__DOES_NOT_EMIT_HANDOFF_TO___S___ALLOWED___S_,
                    $from, $to, implode(',', $allowedDownstream) ?: self::FIELD_NONE),
            ];
        }

        return [self::FIELD_FROM => $from, self::FIELD_TO => $to, self::FIELD_ACCEPTED => true, self::FIELD_REASON => null];
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
                $out[] = [self::FIELD_DEPARTMENT => $id, self::FIELD_MISSING => $missing];
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
            self::FIELD_RISK_SCOPE,
            self::FIELD_SPEC_PACK_HASH,
            self::FIELD_ACCEPTANCE_CRITERIA_PRESENT,
            self::FIELD_ROLLBACK_PLAN_PRESENT,
            self::FIELD_BREAKING_CHANGE_MATRIX_PRESENT,
            self::FIELD_OPERATOR_SIGNATURE_PRESENT,
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
            $result[self::FIELD_EVALUATION] = $evaluation;
        }

        // Observe-only: when a compiled-spec map is supplied, stamp SpecCompletenessScorer.
        $spec = AiValueNormalizer::arrayOrEmpty($input[self::FIELD_SPEC] ?? null);
        if ($spec !== []) {
            $result[self::FIELD_OBSERVE] = [
                self::FIELD_SPEC_COMPLETENESS => $this->specCompleteness->score($spec),
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
            self::FIELD_RULE_ID => self::FIELD_RISK_SCOPE_BELOW_MIN_AUTONOMOUS,
            self::FIELD_GATE_REQUIRED => false,
            self::FIELD_PASSED => true,
            self::FIELD_REASON => null,
        ];
    }

    private function riskScopeIndex(string $scope): int
    {
        static $levels = [self::FIELD_R0, self::FIELD_R1, self::FIELD_R2, self::FIELD_R3, self::FIELD_R4, self::FIELD_R5];
        $index = array_search(AiValueNormalizer::upperTrimmedString($scope), $levels, true);

        return $index === false ? -1 : (int) $index;
    }
}
