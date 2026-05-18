<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Persistence;

/**
 * Canonical filenames for Atlas Dev receipts, per contracts doc section 4.4
 * ("storage/atlas-dev/receipts/<run_id>/" layout).
 */
final class ArtifactNames
{
    public const OPERATION_ENVELOPE = 'operation_envelope.json';

    public const COMPACT_SDD = 'compact_sdd.json';

    public const CONTEXT_RETRIEVAL_PLAN = 'context_retrieval_plan.json';

    public const CODE_DISCOVERY_MANIFEST = 'code_discovery_manifest.json';

    public const OPEN_BRAIN_PROJECTION = 'open_brain_projection.json';

    public const MINI_PROGRAMMING_SPEC = 'mini_programming_spec.json';

    public const TASK_CONTRACT = 'task_contract.json';

    public const CONTEXT_PACK = 'context_pack.json';

    public const PROMPT_PROJECTION = 'prompt_projection.json';

    public const ROUTING_DECISION = 'routing_decision.json';

    public const PROVIDER_CALL_RESULT = 'provider_call_result.json';

    public const DIFF_PARSE_RESULT = 'diff_parse_result.json';

    public const PATCH_APPLY_RESULT = 'patch_apply_result.json';

    public const SCOPE_GUARD_RECEIPT = 'scope_guard_receipt.json';

    public const VERIFICATION_RECEIPT = 'verification_receipt.json';

    public const ESCALATION_DECISION = 'escalation_decision.json';

    public const FAST_PATH_TELEMETRY = 'fast_path_telemetry.json';

    public const RUN_EXECUTION_STATE_BASE = 'run_execution_state';

    public const RUN_CANCELLATION = 'run_cancellation.json';

    public const FAILURE_CAPSULE_BASE = 'failure_capsule';

    public const ERROR_LEDGER_BASE = 'error_ledger';

    public const SENIOR_ENGINEER_LOOP_AUDIT = 'senior_engineer_loop_audit.json';

    public const SENIOR_ENGINEER_LOOP_EXECUTION = 'senior_engineer_loop_execution.json';

    public const MANDATORY_RAG_GATE = 'mandatory_rag_gate.json';

    public const SPECIALIST_FLOW_DECISION = 'specialist_flow_decision.json';

    public const PATCH_INTELLIGENCE_RECEIPT = 'patch_intelligence_receipt.json';

    public const TEST_SELECTION_RECEIPT = 'test_selection_receipt.json';

    private function __construct() {}
}
