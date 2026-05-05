<?php

namespace App\Services\Ai\Kernel\Decision;

final readonly class DecisionBudgets
{
    /**
     * @param  array<string,mixed>  $values
     */
    public function __construct(
        public array $values,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
