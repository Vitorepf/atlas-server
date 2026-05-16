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

    public const FAILURE_CAPSULE_BASE = 'failure_capsule';

    public const ERROR_LEDGER_BASE = 'error_ledger';

    private function __construct() {}
}
