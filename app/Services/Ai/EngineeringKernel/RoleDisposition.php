<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class RoleDisposition
{
    public const STATUSES = ['pass', 'block', 'not_applicable'];

    private function __construct(
        public string $role,
        public string $status,
        public string $reason,
        public string $orderHash,
        public string $specHash,
        public string $candidateHash,
        public string $diffHash,
        public string $treeHash,
        public string $signerContext,
        public string $signature,
    ) {
        if (! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)
            || ! in_array($status, self::STATUSES, true)
            || $reason === '' || $signerContext === '' || $signature === ''
            || array_any([$orderHash, $specHash, $candidateHash, $diffHash], static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) !== 1)
            || preg_match('/^[a-f0-9]{40,64}$/', $treeHash) !== 1) {
            throw new InvalidArgumentException('role_disposition_invalid');
        }
    }

    public static function ownerEvidenceAbsent(CandidateQualityCase $case, string $role, string $signerContext, string $signature): self
    {
        return new self(
            $role, 'block', 'owner_evidence_absent', $case->order->canonicalHash(), $case->order->specHash,
            $case->candidate->candidateHash, $case->candidate->diffHash, $case->candidate->treeHash,
            $signerContext, $signature,
        );
    }

    public static function finalPriorReceiptsBlocked(CandidateQualityCase $case, string $signerContext, string $signature): self
    {
        return new self(
            'final_certification', 'block', 'prior_21_not_all_pass_or_na', $case->order->canonicalHash(), $case->order->specHash,
            $case->candidate->candidateHash, $case->candidate->diffHash, $case->candidate->treeHash,
            $signerContext, $signature,
        );
    }

    public static function finalCertified(CandidateQualityCase $case, string $signerContext, string $signature): self
    {
        return new self(
            'final_certification', 'pass', 'all_21_mutative_role_receipts_verified',
            $case->order->canonicalHash(), $case->order->specHash, $case->candidate->candidateHash,
            $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature,
        );
    }

    public static function qaCandidateVerified(CandidateQualityCase $case, string $signerContext, string $signature): self
    {
        return new self(
            'qa_testing', 'pass', 'candidate_mechanical_and_behavioral_verification_passed',
            $case->order->canonicalHash(), $case->order->specHash, $case->candidate->candidateHash,
            $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature,
        );
    }

    public static function architectureCandidateAdjudicated(CandidateQualityCase $case, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('architecture_disposition_status_invalid');
        }

        return new self(
            'architecture', $status, $reason,
            $case->order->canonicalHash(), $case->order->specHash, $case->candidate->candidateHash,
            $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature,
        );
    }

    public static function dataCandidateAdjudicated(CandidateQualityCase $case, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('data_disposition_status_invalid');
        }

        return new self(
            'data', $status, $reason,
            $case->order->canonicalHash(), $case->order->specHash, $case->candidate->candidateHash,
            $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature,
        );
    }

    public static function appsecPrivacyCandidateAdjudicated(CandidateQualityCase $case, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('appsec_privacy_disposition_status_invalid');
        }

        return new self(
            'appsec_privacy', $status, $reason,
            $case->order->canonicalHash(), $case->order->specHash, $case->candidate->candidateHash,
            $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature,
        );
    }

    public static function performanceCandidateAdjudicated(CandidateQualityCase $case, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('performance_disposition_status_invalid');
        }

        return new self('performance_resilience', $status, $reason, $case->order->canonicalHash(), $case->order->specHash,
            $case->candidate->candidateHash, $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature);
    }

    public static function backendCandidateAdjudicated(CandidateQualityCase $case, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('backend_disposition_status_invalid');
        }

        return new self('backend', $status, $reason, $case->order->canonicalHash(), $case->order->specHash,
            $case->candidate->candidateHash, $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature);
    }

    public static function surfaceApplicabilityAdjudicated(CandidateQualityCase $case, string $role, string $status, string $reason, string $signerContext, string $signature): self
    {
        if (! in_array($role, ['frontend', 'mobile'], true) || ! in_array($status, ['block', 'not_applicable'], true)) {
            throw new InvalidArgumentException('surface_applicability_disposition_invalid');
        }

        return new self($role, $status, $reason, $case->order->canonicalHash(), $case->order->specHash,
            $case->candidate->candidateHash, $case->candidate->diffHash, $case->candidate->treeHash, $signerContext, $signature);
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'role' => $this->role, 'status' => $this->status, 'reason' => $this->reason,
            'order_hash' => $this->orderHash, 'spec_hash' => $this->specHash,
            'candidate_hash' => $this->candidateHash, 'diff_hash' => $this->diffHash,
            'tree_hash' => $this->treeHash, 'signer_context' => $this->signerContext,
            'signature' => $this->signature,
        ];
    }
}
