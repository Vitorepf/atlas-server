<?php

namespace App\Services\Ai\Strategy;

class StrategyDomainException extends \RuntimeException
{
    public static function missingField(string $entity, string $field): self
    {
        return new self("strategy.{$entity}: required field [{$field}] is missing or empty.");
    }

    public static function invalidValue(string $entity, string $field, string $detail): self
    {
        return new self("strategy.{$entity}.{$field}: {$detail}");
    }
}
