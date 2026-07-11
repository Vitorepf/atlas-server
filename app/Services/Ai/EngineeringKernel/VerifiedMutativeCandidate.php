<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

final readonly class VerifiedMutativeCandidate
{
    /** @param list<string> $files @param list<string> $blockers @param array<string,mixed> $providerReceipt @param array<string,mixed> $sandboxReceipt */
    public function __construct(
        public string $status,
        public string $orderHash,
        public string $candidateHash,
        public string $baseCommit,
        public string $treeHash,
        public string $diffHash,
        public array $files,
        public string $sandboxRoot,
        public array $providerReceipt,
        public array $sandboxReceipt,
        public string $verificationRunId,
        public string $verificationHash,
        public string $providerIdentity,
        public string $authorIdentity,
        public string $verifierIdentity,
        public array $blockers,
        public bool $authorityEligible = false,
    ) {}

    /** @param list<string> $blockers @param array<string,mixed> $provider */
    public static function blocked(ExecutionOrder $order, array $blockers, array $provider = []): self
    {
        return new self('blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', [], '', $provider, [], '', '', '', '', '', $blockers, false);
    }
}
