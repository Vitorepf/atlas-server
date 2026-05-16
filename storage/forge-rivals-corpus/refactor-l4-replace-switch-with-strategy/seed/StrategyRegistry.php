<?php

declare(strict_types=1);

namespace App\Domain\Captures\Format;

final class StrategyRegistry
{
    /** @var array<string,FormatterStrategy> */
    private array $strategies = [];

    public function register(FormatterStrategy $strategy): void
    {
        $this->strategies[$strategy->name()] = $strategy;
    }

    public function resolve(string $format): FormatterStrategy
    {
        if (! isset($this->strategies[$format])) {
            throw new \InvalidArgumentException("unknown_format:{$format}");
        }

        return $this->strategies[$format];
    }
}
