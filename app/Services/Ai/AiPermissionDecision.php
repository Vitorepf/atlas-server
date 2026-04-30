<?php

namespace App\Services\Ai;

class AiPermissionDecision
{
    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $reasons
     * @param  array<int,string>  $denials
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $mode,
        public readonly string $workspace,
        public readonly ?string $codexSandbox,
        public readonly array $capabilities,
        public readonly array $reasons,
        public readonly array $denials = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'mode' => $this->mode,
            'workspace' => $this->workspace,
            'codex_sandbox' => $this->codexSandbox,
            'capabilities' => $this->capabilities,
            'reasons' => $this->reasons,
            'denials' => $this->denials,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimePayload(): array
    {
        return [
            'mode' => $this->mode,
            'workspace' => $this->workspace,
            'codex_sandbox' => $this->codexSandbox,
            'capabilities' => $this->capabilities,
            'permission_decision' => $this->toArray(),
        ];
    }

    public function denialMessage(): string
    {
        return implode(' ', array_filter($this->denials)) ?: 'Atlas permission engine denied this AI tool runtime.';
    }
}
