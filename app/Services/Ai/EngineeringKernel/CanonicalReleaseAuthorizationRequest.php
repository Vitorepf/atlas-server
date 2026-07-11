<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

/** Candidate binding whose Governor decision is reloaded before sovereign signing. */
final readonly class CanonicalReleaseAuthorizationRequest
{
    /** @param list<string> $files @param array<string,mixed> $context */
    public function __construct(
        public string $decisionHash,
        public string $taskPacketId,
        public string $candidateHash,
        public string $verificationHash,
        public string $rollbackHash,
        public array $files,
        public string $scopeHash,
        public string $baseCommit,
        public string $treeHash,
        public string $leaseId,
        public string $leaseOwner,
        public int $fencingToken,
        public string $nonce,
        public string $issuedAt,
        public string $expiresAt,
        public array $context,
    ) {
        if ($this->decisionHash === '' || $this->taskPacketId === '' || $this->candidateHash === ''
            || $this->verificationHash === '' || $this->rollbackHash === '' || $this->files === []
            || $this->scopeHash === '' || $this->baseCommit === '' || $this->treeHash === ''
            || $this->leaseId === '' || $this->leaseOwner === '' || $this->fencingToken < 1
            || $this->nonce === '' || $this->issuedAt === '' || $this->expiresAt === '') {
            throw new InvalidArgumentException('canonical_release_authorization_request_incomplete');
        }
    }
}
