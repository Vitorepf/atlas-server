<?php

namespace App\Services\Ai\VentureFoundry;

class VentureFoundryException extends \RuntimeException
{
    public static function missingField(string $entity, string $field): self
    {
        return new self("venture_foundry.{$entity}: required field [{$field}] is missing or empty.");
    }

    public static function invalidValue(string $entity, string $field, string $detail): self
    {
        return new self("venture_foundry.{$entity}.{$field}: {$detail}");
    }

    public static function notFound(string $entity, string $reference): self
    {
        return new self("venture_foundry.{$entity}: [{$reference}] not found.");
    }

    public static function invalidTransition(string $entity, string $from, string $to): self
    {
        return new self("venture_foundry.{$entity}: transition [{$from} -> {$to}] is not allowed.");
    }
}
