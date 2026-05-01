<?php

namespace App\Services\Ai\Cli;

class IntentResolution
{
    /**
     * @param  list<string>  $signals
     */
    public function __construct(
        public readonly string $current,
        public readonly string $required,
        public readonly bool $changed,
        public readonly string $reason,
        public readonly array $signals,
    ) {}

    public function isWrite(): bool
    {
        return $this->required === 'write';
    }

    public function isDanger(): bool
    {
        return $this->required === 'danger';
    }

    public function isReadOnly(): bool
    {
        return $this->required === 'read';
    }
}
