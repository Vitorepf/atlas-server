<?php

namespace App\Services\Ai\Cyber;

class CyberDomainException extends \RuntimeException
{
    public static function missingField(string $entity, string $field): self
    {
        return new self("cyber.{$entity}: required field [{$field}] is missing or empty.");
    }

    public static function invalidValue(string $entity, string $field, string $detail): self
    {
        return new self("cyber.{$entity}.{$field}: {$detail}");
    }

    public static function unauthorized(string $entity, string $reason): self
    {
        return new self("cyber.{$entity}: unauthorized action blocked - {$reason}");
    }

    public static function forbiddenOffensive(string $action): self
    {
        return new self("cyber.runtime: forbidden offensive action [{$action}] - Atlas Cyber Runtime does NOT execute exploit, scan, credential collection or any offensive operation. Use authorized bug bounty intake with documented scope/RoE/legal/privacy gates instead.");
    }

    public static function outOfScope(string $target, string $context): self
    {
        return new self("cyber.runtime: scope violation — target [{$target}] is out of scope for {$context}. Review target is listed in out_of_scope_targets and cannot be certified via this defensive review.");
    }
}
