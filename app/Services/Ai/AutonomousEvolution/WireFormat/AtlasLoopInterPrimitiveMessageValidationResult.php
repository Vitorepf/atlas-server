<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

/**
 * Immutable result of {@see AtlasLoopInterPrimitiveMessageValidator::validate()}. Pure value object.
 */
final class AtlasLoopInterPrimitiveMessageValidationResult
{
    /**
     * @param  list<array{field:string, kind:string, detail:string}>  $errors
     * @param  array<string,mixed>  $normalized canonical byte-stable normalization of the payload (only set on ok())
     */
    public function __construct(
        private readonly bool $ok,
        private readonly array $errors,
        private readonly array $normalized,
    ) {}

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * @return list<array{field:string, kind:string, detail:string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function normalized(): array
    {
        return $this->normalized;
    }
}
