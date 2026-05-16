<?php

declare(strict_types=1);

namespace App\Domain\Captures\Format;

interface FormatterStrategy
{
    public function name(): string;

    /** @param array<string,mixed> $capture */
    public function render(array $capture): string;
}
