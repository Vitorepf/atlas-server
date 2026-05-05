<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;
use App\Services\Ai\Kernel\Failure\FailureClassification;

final class RepairRequestFactory
{
    /**
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,mixed>  $metadata
     */
    public function fromKernelContext(
        string $envelopeId,
        ?string $receiptId,
        FailureClassification $failure,
        RepairPolicy|DecisionRepairPolicy|array $policy,
        int $currentAttempt = 0,
        array $evidenceRefs = [],
        bool $dryRun = true,
        array $metadata = [],
    ): RepairRequest {
        return new RepairRequest(
            envelopeId: $envelopeId,
            receiptId: $receiptId,
            failure: $failure,
            policy: $this->normalizePolicy($policy),
            currentAttempt: $currentAttempt,
            evidenceRefs: $this->stringList($evidenceRefs),
            dryRun: $dryRun,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function fromPayload(array $payload): RepairRequest
    {
        return RepairRequest::fromArray($payload);
    }

    /**
     * @param  array<int,string>  $allowedStrategies
     */
    public function defaultPolicy(
        bool $enabled = true,
        int $maxAttempts = 1,
        array $allowedStrategies = [],
        bool $requiresEvidenceForHeavyRepair = true,
    ): RepairPolicy {
        return RepairPolicy::fromArray([
            'enabled' => $enabled,
            'max_attempts' => $maxAttempts,
            'allowed_strategies' => $allowedStrategies === [] ? RepairStrategy::values() : $allowedStrategies,
            'requires_evidence_for_heavy_repair' => $requiresEvidenceForHeavyRepair,
        ]);
    }

    /**
     * @param  RepairPolicy|DecisionRepairPolicy|array<string,mixed>  $policy
     */
    private function normalizePolicy(RepairPolicy|DecisionRepairPolicy|array $policy): RepairPolicy
    {
        if ($policy instanceof RepairPolicy) {
            return $policy;
        }

        if ($policy instanceof DecisionRepairPolicy) {
            return RepairPolicy::fromDecisionRepairPolicy($policy);
        }

        return RepairPolicy::fromArray($policy);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $value): ?string => is_string($value) ? trim($value) : null,
                $values,
            ),
            fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }
}
