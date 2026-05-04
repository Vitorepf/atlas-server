<?php

namespace App\Services\Ai\Programming;

class ProgrammingExecutionResult
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? 'unknown');
    }
}
