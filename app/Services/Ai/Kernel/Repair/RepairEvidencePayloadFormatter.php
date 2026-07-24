<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Support\CanonicalValue;

final class RepairEvidencePayloadFormatter
{
    /**
     * @param  array<int,string>  $reasons
     * @return array<string,mixed>
     */
    public function decisionPayload(RepairRequest $request, RepairDecisionStatus $status, ?string $strategy, array $reasons): array
    {
        $decision = [
            'status' => $status->value,
            'strategy' => $strategy,
            'next_attempt' => $request->currentAttempt + 1,
            'reasons' => $reasons,
        ];

        $payload = [
            'event_type' => LedgerEventType::RepairInitiated->value,
            'schema_version' => AtlasRepairOrchestrator::CONTRACT_VERSION,
            'envelope_id' => $request->envelopeId,
            'receipt_id' => $request->receiptId,
            'failure_classification' => $request->failure->toArray(),
            'repair_policy' => $request->policy->toArray(),
            'decision' => $decision,
            'evidence_refs' => $request->evidenceRefs,
            'dry_run' => $request->dryRun,
            'repair_executed' => false,
        ];

        return $payload + [
            'decision_hash' => $this->stableHash([
                'schema_version' => AtlasRepairOrchestrator::CONTRACT_VERSION,
                'envelope_id' => $request->envelopeId,
                'receipt_id' => $request->receiptId,
                'failure_classification' => $request->failure->toArray(),
                'repair_policy' => $request->policy->toArray(),
                'decision' => $decision,
                'evidence_refs' => $request->evidenceRefs,
                'dry_run' => $request->dryRun,
            ]),
        ];
    }

    /**
     * @param  array<int,string>  $reasons
     * @return array<string,mixed>
     */
    public function resultPayload(RepairRequest $request, RepairDecision $decision, ?RepairAttempt $attempt, array $reasons): array
    {
        $payload = [
            'event_type' => LedgerEventType::RepairCompleted->value,
            'schema_version' => AtlasRepairOrchestrator::CONTRACT_VERSION,
            'envelope_id' => $request->envelopeId,
            'receipt_id' => $request->receiptId,
            'decision' => $decision->toArray(),
            'attempt' => $attempt?->toArray(),
            'reasons' => $reasons,
            'evidence_refs' => $request->evidenceRefs,
            'dry_run' => $request->dryRun,
            'repair_executed' => false,
        ];

        return $payload + [
            'result_hash' => $this->stableHash([
                'schema_version' => AtlasRepairOrchestrator::CONTRACT_VERSION,
                'envelope_id' => $request->envelopeId,
                'receipt_id' => $request->receiptId,
                'decision' => $decision->toArray(),
                'attempt' => $attempt?->toArray(),
                'reasons' => $reasons,
                'evidence_refs' => $request->evidenceRefs,
                'dry_run' => $request->dryRun,
                'repair_executed' => false,
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function stableHash(array $payload): string
    {
        return hash('sha256', json_encode(CanonicalValue::canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

}
