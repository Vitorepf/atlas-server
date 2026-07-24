<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionTestRun;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use InvalidArgumentException;

final class EngineeringFinalCertifier
{
    public const DOMAIN = 'atlas.engineering_kernel.read_only_final_certifier.v1';

    public const MUTATIVE_DOMAIN = 'atlas.engineering_kernel.mutative_final_certifier.v1';

    /** @param list<AiEngineeringCompanyRoleRun> $prior @return array<string,mixed> */
    public function certify(ExecutionOrder $order, AiRealExecutionTestRun $test, array $prior): array
    {
        return $this->certifyReadOnly($order, $test, $prior);
    }

    public function certifyCandidate(CandidateQualityCase $case): RoleDisposition
    {
        $expectedRoles = array_values(array_filter(EngineeringRoleRoster::OFFICIAL_ROLES, static fn (string $role): bool => $role !== 'final_certification'));
        $candidates = AiEngineeringCompanyRoleRun::query()
            ->where('engagement_record_id', $case->engagementRecordId)
            ->where('cycle_record_id', $case->cycleRecordId)
            ->whereIn('role_id', $expectedRoles)->get()
            ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
        $rows = $candidates->keyBy('role_id');
        $persistedValid = $candidates->count() === 21 && $rows->count() === 21;
        foreach ($expectedRoles as $role) {
            $row = $rows->get($role);
            $receiptDomain = (string) data_get($row?->receipt, 'owner_domain', '');
            $ownerDomain = match ($role) {
                'qa_testing' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN,
                'architecture' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN,
                'data' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_DATA_OWNER_DOMAIN,
                'appsec_privacy' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
                'performance_resilience' => $receiptDomain === EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                    ? EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                    : AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
                'backend' => $receiptDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN
                    ? AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN
                    : EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN,
                'frontend', 'mobile' => $receiptDomain === EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                    ? EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                    : AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role),
                default => EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN,
            };
            if (! $row instanceof AiEngineeringCompanyRoleRun
                || ! app(KernelEvidenceAuthority::class)->mutativeRoleReceiptValid($row, $case, $ownerDomain, 'v1')) {
                $persistedValid = false;
            }
        }
        $allPriorApproved = $persistedValid && ! $candidates->contains(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'disposition.status') === 'block');
        $payload = [
            'purpose' => 'mutative_final_certification',
            'role' => 'final_certification', 'status' => $allPriorApproved ? 'pass' : 'block',
            'reason' => $allPriorApproved ? 'all_21_mutative_role_receipts_verified' : 'prior_21_not_all_pass_or_na',
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'case_hash' => $case->caseHash,
            'prior_21_persisted_valid' => $persistedValid, 'signer_context' => self::MUTATIVE_DOMAIN,
        ];

        $signature = $this->signatureForDomain($payload, self::MUTATIVE_DOMAIN);

        return $allPriorApproved
            ? RoleDisposition::finalCertified($case, self::MUTATIVE_DOMAIN, $signature)
            : RoleDisposition::finalPriorReceiptsBlocked($case, self::MUTATIVE_DOMAIN, $signature);
    }

    /** @param array<string,mixed> $disposition */
    public function mutativeDispositionValid(CandidateQualityCase $case, array $disposition): bool
    {
        try {
            return $this->certifyCandidate($case)->toArray() === $disposition;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<AiEngineeringCompanyRoleRun> $prior @return array<string,mixed> */
    private function certifyReadOnly(ExecutionOrder $order, AiRealExecutionTestRun $test, array $prior): array
    {
        if (! app(EngineeringQualityCourt::class)->priorReceiptsValid($order, $test, $prior)) {
            throw new InvalidArgumentException('read_only_final_certifier_prior_receipts_invalid');
        }
        $payload = ['role' => 'final_certification', 'status' => 'pass', 'reason' => 'all_21_prior_receipts_verified',
            'order_hash' => $order->canonicalHash(), 'evidence_hash' => (string) $test->test_hash,
            'justification' => 'all_21_prior_receipts_verified', 'applicability_rule' => 'requires_21_prior_receipts',
            'signer_context' => self::DOMAIN];
        $payload['signature'] = $this->signature($payload);

        return $payload;
    }

    /** @param array<string,mixed> $disposition */
    public function dispositionValid(ExecutionOrder $order, AiRealExecutionTestRun $test, array $disposition): bool
    {
        $unsigned = $disposition;
        $signature = (string) ($unsigned['signature'] ?? '');
        unset($unsigned['signature']);

        return ($unsigned['role'] ?? null) === 'final_certification' && ($unsigned['order_hash'] ?? null) === $order->canonicalHash()
            && ($unsigned['evidence_hash'] ?? null) === $test->test_hash && ($unsigned['signer_context'] ?? null) === self::DOMAIN
            && hash_equals($signature, $this->signature($unsigned));
    }

    /** @param array<string,mixed> $payload */
    private function signature(array $payload): string
    {
        return $this->signatureForDomain($payload, self::DOMAIN);
    }

    /** @param array<string,mixed> $payload */
    private function signatureForDomain(array $payload, string $domain): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return hash_hmac('sha256', CanonicalKernelPayload::hash($payload), hash_hmac('sha256', $domain, is_string($decoded) ? $decoded : '', true));
    }
}
