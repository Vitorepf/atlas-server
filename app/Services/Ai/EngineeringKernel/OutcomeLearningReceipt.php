<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

final readonly class OutcomeLearningReceipt
{
    private function __construct(
        public string $schemaVersion,
        public string $runId,
        public string $deliveryId,
        public string $observationHash,
        public string $status,
        public bool $claimEligible,
        public string $receiptHash,
    ) {}

    public static function fromObservation(OutcomeObservation $observation): self
    {
        $payload = [
            'schema_version' => 'atlas.outcome_learning_receipt.v1',
            'run_id' => $observation->runId,
            'delivery_id' => $observation->deliveryId,
            'observation_hash' => $observation->canonicalHash(),
            'status' => 'held_for_causal_adjudication',
            'claim_eligible' => false,
        ];

        return new self(
            schemaVersion: $payload['schema_version'],
            runId: $payload['run_id'],
            deliveryId: $payload['delivery_id'],
            observationHash: $payload['observation_hash'],
            status: $payload['status'],
            claimEligible: false,
            receiptHash: CanonicalKernelPayload::hash($payload),
        );
    }
}
