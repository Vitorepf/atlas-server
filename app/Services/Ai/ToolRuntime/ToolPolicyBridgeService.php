<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Services\Ai\Evidence\AuditEventService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Policy\PermissionGateService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridge to the Atlas Policy / Permission / Budget / Safety layer (Meta 3).
 *
 * STRICT MODE (production · `config('atlas_ai.tool_runtime.strict_mode')` true)
 *   - Throws `ToolPolicyEnforcementException` when the Policy runtime is
 *     unavailable OR when `PermissionGateService::evaluate` throws.
 *   - No fallback decision is ever returned.
 *
 * LENIENT MODE (default · local dev / isolated workspaces)
 *   - Returns a fallback decision (`require_approval` for high-risk authority
 *     groups, otherwise `allow`) so the Tool Runtime keeps operable.
 *   - Always emits a structured `Log::warning` AND a
 *     `tool_policy_bridge_degraded` audit event when the Evidence ledger is
 *     reachable. Silent degradation is no longer possible.
 *
 * The strict flag can also be forced per-call via `evaluateStrict()`, which
 * callers like ProgrammingPolicyBridge use when they need enforcement
 * regardless of global config.
 *
 * Canon: docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
 */
class ToolPolicyBridgeService
{
    public function __construct(private readonly Container $container) {}

    /**
     * Evaluate a tool against the Policy/Permission layer. Honors the
     * `atlas_ai.tool_runtime.strict_mode` config flag.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     *
     * @throws ToolPolicyEnforcementException when strict mode is active and
     *                                        the policy bridge is unavailable or throws.
     */
    public function evaluate(AiToolDefinition $tool, array $context = []): array
    {
        return $this->doEvaluate($tool, $context, $this->configStrict());
    }

    /**
     * Force strict evaluation regardless of global config. Used by callers
     * that must never accept a fallback decision (e.g., ProgrammingPolicyBridge
     * or any production HTTP path).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     *
     * @throws ToolPolicyEnforcementException always when the bridge fails.
     */
    public function evaluateStrict(AiToolDefinition $tool, array $context = []): array
    {
        return $this->doEvaluate($tool, $context, true);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     *
     * @throws ToolPolicyEnforcementException
     */
    private function doEvaluate(AiToolDefinition $tool, array $context, bool $strict): array
    {
        $request = $this->buildRequest($tool, $context);

        if (! $this->bridgeAvailable()) {
            $this->recordDegradation(
                $tool,
                $strict,
                'policy_runtime_unavailable',
                'Policy/Permission tables (ai_permission_gates / ai_policy_profiles) are absent.',
                null,
                $request,
            );
            if ($strict) {
                throw ToolPolicyEnforcementException::bridgeUnavailable($tool->tool_id);
            }

            return $this->fallbackDecision($tool, $request, 'policy_runtime_unavailable');
        }

        try {
            $gateService = $this->container->make(PermissionGateService::class);
            $gate = $gateService->evaluate($request);

            return [
                'decision' => (string) $gate->decision,
                'source' => 'atlas_policy_permission_gate',
                'reason' => 'policy_runtime',
                'receipt_hash' => (string) $gate->receipt_hash,
                'gate_id' => (string) $gate->id,
                'reasons' => $gate->reasons,
                'degraded' => false,
                'mode' => $strict ? 'strict' : 'lenient',
            ];
        } catch (Throwable $e) {
            $this->recordDegradation(
                $tool,
                $strict,
                'policy_runtime_exception',
                $e->getMessage(),
                $e,
                $request,
            );
            if ($strict) {
                throw ToolPolicyEnforcementException::evaluationFailed(
                    $tool->tool_id,
                    $e->getMessage(),
                    $e,
                    ['exception_class' => $e::class],
                );
            }

            return $this->fallbackDecision($tool, $request, 'policy_runtime_exception:'.$e->getMessage());
        }
    }

    public function bridgeAvailable(): bool
    {
        return DatabaseTableAvailability::all(['ai_permission_gates', 'ai_policy_profiles']);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function buildRequest(AiToolDefinition $tool, array $context): array
    {
        return [
            'requested_action' => $tool->tool_id,
            'gate_type' => 'tool',
            'risk_level' => (string) $tool->risk_level,
            'domain_id' => $context['domain_id'] ?? null,
            'tool_id' => $tool->tool_id,
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'evidence_refs' => $context['evidence_refs'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function fallbackDecision(AiToolDefinition $tool, array $request, string $reason): array
    {
        return [
            'decision' => ToolRuntimeCanon::isHighRiskAuthority($tool->authority_group)
                ? 'require_approval'
                : 'allow',
            'source' => 'tool_runtime_fallback',
            'reason' => $reason,
            'receipt_hash' => MissionCanonicalHash::sha256($request),
            'gate_id' => null,
            'degraded' => true,
            'mode' => 'lenient',
        ];
    }

    /**
     * Structured degradation record: log + (best-effort) audit event. This
     * runs in BOTH strict and lenient modes — in strict mode the exception
     * carries the same signal, in lenient mode it ensures the fallback is
     * never silent.
     *
     * @param  array<string,mixed>  $request
     */
    private function recordDegradation(
        AiToolDefinition $tool,
        bool $strict,
        string $reason,
        string $detail,
        ?Throwable $exception,
        array $request,
    ): void {
        $payload = [
            'tool_id' => $tool->tool_id,
            'authority_group' => (string) $tool->authority_group,
            'risk_level' => (string) $tool->risk_level,
            'mode' => $strict ? 'strict' : 'lenient',
            'reason' => $reason,
            'detail' => $detail,
            'exception_class' => $exception ? $exception::class : null,
            'mission_id' => $request['mission_id'] ?? null,
            'work_order_id' => $request['work_order_id'] ?? null,
            'domain_id' => $request['domain_id'] ?? null,
        ];

        Log::warning('atlas.tool_runtime.policy_bridge_degraded', $payload);

        $this->safeAuditRecord(
            AuditEventService::EVENT_TOOL_POLICY_BRIDGE_DEGRADED,
            'tool_definition',
            (string) $tool->id,
            $payload,
            $request['mission_id'] ?? null,
        );
    }

    /**
     * Best-effort audit event emission. Never throws back into the caller —
     * it is purely an observability hook. If the Evidence ledger is also
     * absent, the structured log above is already the durable signal.
     *
     * @param  array<string,mixed>  $payload
     */
    private function safeAuditRecord(string $eventType, string $targetType, string $targetId, array $payload, ?string $missionId): void
    {
        if (! DatabaseTableAvailability::has('ai_audit_events')) {
            return;
        }
        try {
            $audit = $this->container->make(AuditEventService::class);
            $audit->record($eventType, $targetType, $targetId, $payload, 'atlas_tool_runtime', $missionId);
        } catch (Throwable $e) {
            Log::warning('atlas.tool_runtime.audit_record_failed', [
                'event_type' => $eventType,
                'detail' => $e->getMessage(),
            ]);
        }
    }

    private function configStrict(): bool
    {
        return (bool) config('atlas_ai.tool_runtime.strict_mode', false);
    }
}
