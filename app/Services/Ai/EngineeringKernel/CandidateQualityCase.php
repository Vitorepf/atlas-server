<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use InvalidArgumentException;

final readonly class CandidateQualityCase
{
    private function __construct(
        public ExecutionOrder $order,
        public VerifiedMutativeCandidate $candidate,
        public MutativeVerificationReference $verification,
        public string $engagementRecordId,
        public string $cycleRecordId,
        public string $caseHash,
    ) {}

    public static function fromCandidate(ExecutionOrder $order, VerifiedMutativeCandidate $candidate, AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle): self
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()) {
            throw new InvalidArgumentException('candidate_quality_case_company_binding_invalid');
        }
        $verification = new MutativeVerificationReference(
            $candidate->verificationRunId, $candidate->verificationHash, $candidate->candidateHash,
            $candidate->providerIdentity, $candidate->authorIdentity, $candidate->verifierIdentity,
        );
        $row = app(AtlasRealEngineeringExecutionKernelService::class)->verifiedMutativeVerification($verification, $order);
        $binding = (array) data_get($row->receipt, 'binding', []);
        if ($candidate->status !== 'behaviorally_verified_pending_quality_court'
            || $candidate->authorityEligible || $candidate->orderHash !== $order->canonicalHash()
            || $candidate->baseCommit !== $order->baseCommit || $candidate->candidateHash !== $verification->candidateHash
            || ($binding['tree_hash'] ?? null) !== $candidate->treeHash
            || ($binding['diff_hash'] ?? null) !== $candidate->diffHash
            || ($binding['files'] ?? null) !== $candidate->files) {
            throw new InvalidArgumentException('candidate_quality_case_binding_invalid');
        }
        foreach ($candidate->files as $file) {
            if (! in_array($file, $order->allowedScope, true) || in_array($file, $order->forbiddenScope, true)) {
                throw new InvalidArgumentException('candidate_quality_case_scope_invalid');
            }
        }
        $caseHash = CanonicalKernelPayload::hash([
            'order_hash' => $candidate->orderHash, 'spec_hash' => $order->specHash,
            'candidate_hash' => $candidate->candidateHash, 'diff_hash' => $candidate->diffHash,
            'tree_hash' => $candidate->treeHash, 'files' => $candidate->files,
            'verification_hash' => $verification->receiptHash,
            'engagement_record_id' => (string) $engagement->getKey(),
            'cycle_record_id' => (string) $cycle->getKey(),
        ]);

        return new self(
            $order, $candidate, $verification, (string) $engagement->getKey(),
            (string) $cycle->getKey(), $caseHash,
        );
    }
}
