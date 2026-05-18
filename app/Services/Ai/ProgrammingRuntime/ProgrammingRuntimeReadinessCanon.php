<?php

namespace App\Services\Ai\ProgrammingRuntime;

/**
 * Canonical enums for the Programming Runtime Readiness service. The service
 * answers honestly whether the Atlas AI Programming Runtime is shippable —
 * not whether scaffold tables exist. Each check has a stable id and a fixed
 * severity tier (P0|P1|P2) so CI gates and humans can react deterministically.
 *
 * Gap source-of-truth: `atlas-programming-superiority-architecture.md`
 * (Top 15 gaps) and `atlas-architecture-critical-judgment-report.md`
 * (Gap severity table).
 */
final class ProgrammingRuntimeReadinessCanon
{
    public const SCHEMA_VERSION = 'atlas.programming.runtime_readiness.v1';

    public const STATUS_GREEN = 'green';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_STATUS_GREEN = 'green';

    public const CHECK_STATUS_WARN = 'warn';

    public const CHECK_STATUS_BLOCKED = 'blocked';

    public const SEVERITY_P0 = 'P0';

    public const SEVERITY_P1 = 'P1';

    public const SEVERITY_P2 = 'P2';

    public const CHECK_AIWORKER_KERNEL_INTEGRATION = 'aiworker_kernel_integration';

    public const CHECK_ROUTE_DECISION_V1_IMPLEMENTED = 'route_decision_v1_implemented';

    public const CHECK_ROUTE_DECISION_V1_HAS_PRODUCTION_CALLERS = 'route_decision_v1_has_production_callers';

    public const CHECK_ESCALATION_PACKET_V1_IMPLEMENTED = 'escalation_packet_v1_implemented';

    public const CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH = 'escalation_packet_v1_used_in_dev_forge_path';

    public const CHECK_MANDATORY_RAG_GATE_ENFORCED = 'mandatory_rag_gate_enforced';

    public const CHECK_TOOL_POLICY_EVIDENCE_STRICT_MODE = 'tool_policy_evidence_strict_mode';

    public const CHECK_MISSION_CERTIFICATION_QUALITY_AWARE = 'mission_certification_quality_aware';

    public const CHECK_E2E_CANONICAL_TEST_EXISTS = 'e2e_canonical_test_exists';

    public const CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS = 'dev_forge_no_parallel_escalation_schemas';

    /**
     * Kernel classes that AiWorker must inject at least one of for the
     * integration check to pass. List intentionally narrow: it covers the 5
     * canonical services the path HTTP would need to consult before provider
     * execution.
     *
     * @var array<int,string>
     */
    public const KERNEL_INTEGRATION_SENTINELS = [
        'App\\Services\\Ai\\Mission\\MissionFactoryService',
        'App\\Services\\Ai\\Mission\\MissionLifecycleService',
        'App\\Services\\Ai\\Mission\\MissionCertificationService',
        'App\\Services\\Ai\\Policy\\PermissionGateService',
        'App\\Services\\Ai\\Evidence\\CertificationRuntimeService',
        'App\\Services\\Ai\\RouterRuntime\\FlowRouterService',
        'App\\Services\\Ai\\RouterRuntime\\DomainRouterService',
    ];

    /**
     * Phase 1 (gateway-level) bridge sentinels. When AiGatewayService injects
     * the AiGatewayMissionBridge, the HTTP entry point persists a kernel
     * envelope in `payload.kernel`. This proves Phase 1 of the AiWorker→Kernel
     * integration ADR is shipped, even when AiWorker itself does not yet
     * consume the envelope (Phases 4-6 pending). The readiness check uses
     * this list to distinguish blocked (no bridge anywhere) from partial
     * (gateway only, worker still legacy) from green (both layers wired).
     *
     * @var array<int,string>
     */
    public const KERNEL_GATEWAY_BRIDGE_SENTINELS = [
        'App\\Services\\Ai\\Mission\\AiGatewayMissionBridge',
    ];

    /**
     * Canonical signals proving the mandatory RAG gate is enforced (not
     * advisory-only). The legacy heuristic grepped the string `failed_closed`
     * but the canonical implementation uses MandatoryRagGateResult::STATUS_BLOCKED.
     * The readiness check now inspects all three signals: canonical class
     * exists, blocked-status constant exists, and at least one production
     * caller exists outside Gate/ and tests.
     *
     * @var array<string,string>
     */
    public const MANDATORY_RAG_GATE_CANONICAL_SIGNALS = [
        'gate_class' => 'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php',
        'result_class' => 'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGateResult.php',
        'caller_search_root' => 'app/Services/Ai/Programming',
        'caller_search_needle' => 'MandatoryRagGate',
        'blocked_constant_token' => 'MandatoryRagGateResult::STATUS_BLOCKED',
    ];

    /** @var array<int,string> */
    public const ALL_CHECK_IDS = [
        self::CHECK_AIWORKER_KERNEL_INTEGRATION,
        self::CHECK_ROUTE_DECISION_V1_IMPLEMENTED,
        self::CHECK_ROUTE_DECISION_V1_HAS_PRODUCTION_CALLERS,
        self::CHECK_ESCALATION_PACKET_V1_IMPLEMENTED,
        self::CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH,
        self::CHECK_MANDATORY_RAG_GATE_ENFORCED,
        self::CHECK_TOOL_POLICY_EVIDENCE_STRICT_MODE,
        self::CHECK_MISSION_CERTIFICATION_QUALITY_AWARE,
        self::CHECK_E2E_CANONICAL_TEST_EXISTS,
        self::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS,
    ];
}
