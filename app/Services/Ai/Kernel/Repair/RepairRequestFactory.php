<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Support\AiStringListNormalizer;

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
            evidenceRefs: AiStringListNormalizer::trimmedStrings($evidenceRefs),
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

}
