<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use InvalidArgumentException;

final readonly class ForgeObraId
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('forge_obra_id_required');
        }

        return new self($value);
    }
}
