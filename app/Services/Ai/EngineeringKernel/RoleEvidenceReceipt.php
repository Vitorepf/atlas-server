<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

final readonly class RoleEvidenceReceipt
{
    /** @param list<string> $evidenceRefs */
    private function __construct(
        public string $caseHash,
        public RoleDisposition $disposition,
        public string $ownerDomain,
        public string $ownerVersion,
        public string $issuedAt,
        public string $expiresAt,
        public array $evidenceRefs,
        public string $receiptHash,
    ) {}

    /** @param list<string> $evidenceRefs */
    public static function issue(CandidateQualityCase $case, RoleDisposition $disposition, string $ownerDomain, string $ownerVersion, string $issuedAt, string $expiresAt, array $evidenceRefs): self
    {
        $payload = [
            'purpose' => 'mutative_candidate_quality_role_evidence', 'case_hash' => $case->caseHash,
            'disposition' => $disposition->toArray(), 'owner_domain' => $ownerDomain,
            'owner_version' => $ownerVersion, 'issued_at' => $issuedAt, 'expires_at' => $expiresAt,
            'evidence_refs' => $evidenceRefs,
        ];

        return new self(
            $case->caseHash, $disposition, $ownerDomain, $ownerVersion, $issuedAt, $expiresAt,
            $evidenceRefs, CanonicalKernelPayload::hash($payload),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'case_hash' => $this->caseHash, 'disposition' => $this->disposition->toArray(),
            'owner_domain' => $this->ownerDomain, 'owner_version' => $this->ownerVersion,
            'issued_at' => $this->issuedAt, 'expires_at' => $this->expiresAt,
            'evidence_refs' => $this->evidenceRefs, 'receipt_hash' => $this->receiptHash,
        ];
    }
}
