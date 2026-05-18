<?php

namespace App\Services\Ai\DomainRuntime;

use RuntimeException;

class DomainRuntimeException extends RuntimeException
{
    public static function duplicateDomain(string $domainId): self
    {
        return new self("Domain with domain_id [{$domainId}] already exists; manifests are unique by domain_id.");
    }

    public static function manifestMissingField(string $domainId, string $field): self
    {
        return new self("Manifest [{$domainId}] missing required field [{$field}].");
    }

    public static function unknownDomain(string $domainId): self
    {
        return new self("Domain manifest [{$domainId}] not found.");
    }

    public static function invalidMaturityStage(int $stage): self
    {
        return new self("maturity_stage must be between 1 and 5, got [{$stage}].");
    }

    public static function maturityRequiresEvidence(int $stage): self
    {
        return new self("Maturity stage [{$stage}] (Operating Unit / Autonomous Enterprise Unit) requires evidence refs, metrics and certification; promotion blocked.");
    }

    public static function handoffMissingContext(): self
    {
        return new self('Handoff is invalid without context_pack and receipt_hash.');
    }

    public static function handoffViolatesRules(string $from, string $to): self
    {
        return new self("Handoff [{$from}] -> [{$to}] is not permitted by manifest handoff_rules.");
    }

    public static function capabilityExists(string $domainId, string $capabilityId): self
    {
        return new self("Capability [{$capabilityId}] already exists in domain [{$domainId}].");
    }
}
