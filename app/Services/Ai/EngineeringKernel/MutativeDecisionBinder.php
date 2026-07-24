<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;

/**
 * P1b.1 / P4: mutative orders must bind a KernelEvidenceAuthority-sealed
 * decision.issued event. Fabricated decision_event_id strings never open the
 * provider/sandbox/mutation boundary.
 */
final class MutativeDecisionBinder
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly KernelEvidenceAuthority $authority,
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $decisionIssuer,
    ) {}

    /**
     * Ledger event_id is capped at 32 chars — never concatenate raw hashes/uuids.
     */
    public static function decisionEventId(string $seed): string
    {
        return substr(hash('sha256', 'atlas.engineering_kernel.mutative_decision:'.$seed), 0, 32);
    }

    /**
     * @param  array<string,mixed>  $extraMetadata  provider-safe metadata folded into the receipt
     */
    public function bind(ExecutionOrder $order, string $mode, string $decisionEventId, string $operatorId, array $extraMetadata = []): ExecutionOrder
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, EngineeringRoleRoster::MODES, true)) {
            throw new \InvalidArgumentException('mutative_decision_mode_invalid');
        }
        $decisionEventId = trim($decisionEventId);
        if ($decisionEventId === '' || strlen($decisionEventId) > 32) {
            throw new \InvalidArgumentException('mutative_decision_event_id_invalid');
        }

        $existing = $this->ledger->eventById($decisionEventId);
        if ($existing !== null && $this->authority->verifyEvent($existing, 'decision')) {
            return $this->withDecisionEvent($order, $decisionEventId);
        }

        $envelope = $this->envelopes->create([
            'operator' => [
                'operator_id' => $operatorId !== '' ? $operatorId : 'atlas-engineering-operator',
                'tenant_id' => 'atlas',
            ],
            'origin' => [
                'surface_id' => 'atlas.'.$mode,
                'session_id' => $order->deliveryId !== '' ? $order->deliveryId : $decisionEventId,
            ],
            'input' => [
                'kind' => 'engineering',
                'payload' => [
                    'delivery_id' => $order->deliveryId,
                    'mode' => $mode,
                    'plan_run_id' => $order->runId,
                ] + $extraMetadata,
            ],
        ]);

        $risk = in_array($order->riskClass, ['R0', 'R1'], true)
            ? 'low'
            : (in_array($order->riskClass, ['R2', 'R3'], true) ? 'medium' : ($order->riskClass === 'R4' ? 'high' : 'critical'));

        $bound = $this->withDecisionEvent($order, $decisionEventId, $envelope->envelopeId);
        $receipt = $this->decisionIssuer->issue($envelope, [
            'ttl_seconds' => 3600,
            'domain' => 'programming',
            'flow' => 'atlas.'.$mode,
            'risk' => $risk,
            'dry_run' => false,
            'required_evidence' => ['summary'],
            'provider_selection' => [
                'primary' => (string) ($order->providerRoute['provider'] ?? 'atlas_kernel'),
                'model' => (string) ($order->providerRoute['model'] ?? 'selected-by-decide'),
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'mutative_decision_binder',
            ],
            'metadata' => [
                'delivery_id' => $bound->deliveryId,
                'order_hash' => $bound->canonicalHash(),
                'spec_hash' => $bound->specHash,
                'roster_hash' => CanonicalKernelPayload::hash($bound->roleRoster),
                'mode' => $bound->mode,
                'plan_run_id' => $order->runId,
            ] + $extraMetadata,
        ]);

        $this->authority->issueDecision($receipt, $bound, [
            'event_id' => $decisionEventId,
            'envelope_id' => $bound->runId,
            'correlation_id' => $bound->idempotencyKey,
            'scope_type' => 'engineering_delivery',
            'scope_id' => $bound->deliveryId,
            'operator_id' => $operatorId !== '' ? $operatorId : 'atlas-engineering-operator',
            'tenant_id' => 'atlas',
        ]);

        return $bound;
    }

    private function withDecisionEvent(ExecutionOrder $order, string $decisionEventId, ?string $runId = null): ExecutionOrder
    {
        $data = $order->toArray();
        if ($runId !== null && $runId !== '') {
            $data['run_id'] = $runId;
        }
        $data['decision_receipt'] = ['decision_event_id' => $decisionEventId];
        $data['schema_version'] = 'atlas.execution_order.v2';

        return ExecutionOrder::fromArray($data);
    }
}
