<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolInvocation;
use App\Models\AiToolReceipt;
use App\Services\Ai\Evidence\AuditEventService;
use App\Services\Ai\Evidence\ReceiptService as EvidenceReceiptService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bridge to the Atlas Evidence / Certification Runtime (Meta 4).
 *
 * STRICT MODE (production · `config('atlas_ai.tool_runtime.strict_mode')` true)
 *   - Throws `ToolReceiptEmissionException` when `ai_receipts` is absent OR
 *     when `EvidenceReceiptService::emit` throws.
 *   - The local `ai_tool_receipts` row is NOT written in strict mode when
 *     the Evidence bridge fails, because the caller is expected to surface
 *     the failure (mark the invocation `blocked`) instead of pretending the
 *     execution closed cleanly.
 *
 * LENIENT MODE (default · local dev / isolated workspaces)
 *   - Writes the local `ai_tool_receipts` row + records a local-only
 *     evidence_ref marker. Always emits a structured `Log::warning` AND a
 *     `tool_receipt_bridge_degraded` audit event when the Evidence ledger is
 *     reachable. Silent degradation is no longer possible.
 *
 * The strict flag can also be forced per-call via `emitStrict()`.
 *
 * Canon: docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
 */
class ToolReceiptService
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     *
     * @throws ToolReceiptEmissionException when strict mode is active and
     *                                      the Evidence bridge is unavailable or throws.
     */
    public function emit(AiToolInvocation $invocation, string $status, array $policy, ?array $output = null): AiToolReceipt
    {
        return $this->doEmit($invocation, $status, $policy, $output, $this->configStrict());
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     *
     * @throws ToolReceiptEmissionException always when the bridge fails.
     */
    public function emitStrict(AiToolInvocation $invocation, string $status, array $policy, ?array $output = null): AiToolReceipt
    {
        return $this->doEmit($invocation, $status, $policy, $output, true);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     *
     * @throws ToolReceiptEmissionException
     */
    private function doEmit(AiToolInvocation $invocation, string $status, array $policy, ?array $output, bool $strict): AiToolReceipt
    {
        $evidenceRefs = $this->bridgeToEvidenceRuntime($invocation, $status, $policy, $output, $strict);

        $hashInput = [
            'tool_invocation_id' => $invocation->id,
            'status' => $status,
            'input_hash' => $invocation->input_hash,
            'output_hash' => $invocation->output_hash,
            'policy_decision_ref' => $invocation->policy_decision_ref,
            'evidence_refs' => $evidenceRefs,
        ];

        return AiToolReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_invocation_id' => $invocation->id,
            'receipt_type' => 'tool_call',
            'status' => $status,
            'evidence_refs' => $evidenceRefs,
            'receipt_hash' => MissionCanonicalHash::sha256($hashInput),
        ]);
    }

    public function bridgeAvailable(): bool
    {
        return DatabaseTableAvailability::has('ai_receipts');
    }

    /**
     * STRICT MODE: throw if Evidence Runtime is unavailable or emit() throws.
     * LENIENT MODE: fall back to a local-only receipt marker, but emit a
     * structured Log warning and a `tool_receipt_bridge_degraded` audit event.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     * @return array<int,array<string,mixed>>
     *
     * @throws ToolReceiptEmissionException
     */
    private function bridgeToEvidenceRuntime(AiToolInvocation $invocation, string $status, array $policy, ?array $output, bool $strict): array
    {
        if (! $this->bridgeAvailable()) {
            $this->recordDegradation(
                $invocation,
                $strict,
                'evidence_runtime_unavailable',
                'Evidence Runtime table (ai_receipts) is absent.',
                null,
                $status,
            );
            if ($strict) {
                throw ToolReceiptEmissionException::bridgeUnavailable((string) $invocation->id);
            }

            return [[
                'kind' => 'tool_runtime_local_receipt',
                'reason' => 'evidence_runtime_unavailable',
                'tool_invocation_id' => $invocation->id,
                'degraded' => true,
                'mode' => 'lenient',
            ]];
        }

        try {
            $receiptService = $this->container->make(EvidenceReceiptService::class);
            $receipt = $receiptService->emit([
                'receipt_type' => 'tool_call',
                'status' => $this->mapStatusForEvidence($status),
                'target_type' => 'tool_invocation',
                'target_id' => $invocation->id,
                'mission_id' => $invocation->mission_id,
                'work_order_id' => $invocation->work_order_id,
                'actor_type' => 'atlas_tool_runtime',
                'action' => (string) ($invocation->tool->tool_id ?? 'tool'),
                'input_hash' => $invocation->input_hash,
                'output_hash' => $invocation->output_hash,
                'evidence_refs' => $output !== null ? [['kind' => 'tool_runtime_mock_output', 'output_hash' => $invocation->output_hash]] : [],
            ]);

            return [[
                'kind' => 'evidence_runtime_receipt',
                'receipt_id' => (string) $receipt->id,
                'receipt_hash' => (string) $receipt->receipt_hash,
                'degraded' => false,
                'mode' => $strict ? 'strict' : 'lenient',
            ]];
        } catch (Throwable $e) {
            $this->recordDegradation(
                $invocation,
                $strict,
                'evidence_runtime_exception',
                $e->getMessage(),
                $e,
                $status,
            );
            if ($strict) {
                throw ToolReceiptEmissionException::emissionFailed(
                    (string) $invocation->id,
                    $e->getMessage(),
                    $e,
                    ['exception_class' => $e::class],
                );
            }

            return [[
                'kind' => 'tool_runtime_local_receipt',
                'reason' => 'evidence_runtime_exception:'.$e->getMessage(),
                'tool_invocation_id' => $invocation->id,
                'degraded' => true,
                'mode' => 'lenient',
            ]];
        }
    }

    private function mapStatusForEvidence(string $status): string
    {
        return match ($status) {
            'succeeded' => 'ok',
            'failed' => 'failed',
            default => 'partial',
        };
    }

    /**
     * Structured degradation record: log + (best-effort) audit event. Always
     * runs in both strict and lenient modes so degradation is observable.
     */
    private function recordDegradation(
        AiToolInvocation $invocation,
        bool $strict,
        string $reason,
        string $detail,
        ?Throwable $exception,
        string $status,
    ): void {
        $payload = [
            'tool_invocation_id' => (string) $invocation->id,
            'tool_id' => (string) ($invocation->tool->tool_id ?? 'unknown'),
            'status' => $status,
            'mode' => $strict ? 'strict' : 'lenient',
            'reason' => $reason,
            'detail' => $detail,
            'exception_class' => $exception ? $exception::class : null,
            'mission_id' => $invocation->mission_id,
            'work_order_id' => $invocation->work_order_id,
        ];

        Log::warning('atlas.tool_runtime.receipt_bridge_degraded', $payload);

        $this->safeAuditRecord(
            AuditEventService::EVENT_TOOL_RECEIPT_BRIDGE_DEGRADED,
            'tool_invocation',
            (string) $invocation->id,
            $payload,
            $invocation->mission_id,
        );
    }

    /**
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
