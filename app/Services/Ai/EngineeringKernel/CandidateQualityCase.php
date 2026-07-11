<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class CandidateQualityCase
{
    private function __construct(
        public ExecutionOrder $order,
        public VerifiedMutativeCandidate $candidate,
        public string $caseHash,
    ) {}

    public static function fromCandidate(ExecutionOrder $order, VerifiedMutativeCandidate $candidate, KernelEvidenceAuthority $authority): self
    {
        $verification = $candidate->verificationReceipt;
        $behavioral = is_array($verification['behavioral'] ?? null) ? $verification['behavioral'] : [];
        $expectedCandidateHash = CanonicalKernelPayload::hash([
            'order_hash' => $order->canonicalHash(), 'base_commit' => $order->baseCommit,
            'tree_hash' => $candidate->treeHash, 'diff_hash' => $candidate->diffHash,
            'files' => $candidate->files, 'verification_hash' => $verification['hash'] ?? null,
            'behavioral_hash' => CanonicalKernelPayload::hash($behavioral),
        ]);
        if ($candidate->status !== 'behaviorally_verified_pending_quality_court'
            || $candidate->authorityEligible || $candidate->orderHash !== $order->canonicalHash()
            || $candidate->baseCommit !== $order->baseCommit
            || ! hash_equals($expectedCandidateHash, $candidate->candidateHash)
            || ($verification['tree_hash'] ?? null) !== $candidate->treeHash
            || ($verification['diff_hash'] ?? null) !== $candidate->diffHash
            || ($verification['files'] ?? null) !== $candidate->files
            || ($verification['independent_from_provider'] ?? null) !== true
            || ! $authority->verifyMutativeVerificationReceipt($verification)
            || ! self::artifactsValid($candidate)) {
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
            'verification_hash' => $verification['hash'],
        ]);

        return new self($order, $candidate, $caseHash);
    }

    private static function artifactsValid(VerifiedMutativeCandidate $candidate): bool
    {
        $artifacts = [$candidate->verificationReceipt['junit_artifact'] ?? null,
            data_get($candidate->verificationReceipt, 'behavioral.junit_artifact')];
        $root = realpath($candidate->sandboxRoot.'/.atlas');
        if ($root === false) {
            return false;
        }
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                return false;
            }
            $path = (string) ($artifact['path'] ?? '');
            $real = realpath($path);
            if ($real === false || ! str_starts_with($real, $root.'/') || ! is_file($real) || is_link($path)
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $real))) {
                return false;
            }
        }

        return true;
    }
}
