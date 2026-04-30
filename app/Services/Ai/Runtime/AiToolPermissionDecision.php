<?php

namespace App\Services\Ai\Runtime;

class AiToolPermissionDecision
{
    /**
     * @param  array<int,string>  $reasons
     * @param  array<int,string>  $denials
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $requiresApproval,
        public readonly PermissionRequest $request,
        public readonly string $requestedMode,
        public readonly array $reasons = [],
        public readonly array $denials = [],
        public readonly array $metadata = [],
    ) {}

    public function message(): string
    {
        if ($this->allowed) {
            return implode(' ', $this->reasons) ?: 'Tool runtime autorizado.';
        }

        return implode(' ', $this->denials) ?: 'Tool runtime bloqueado pelo Atlas.';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'requires_approval' => $this->requiresApproval,
            'requested_mode' => $this->requestedMode,
            'request' => $this->request->toArray(),
            'reasons' => $this->reasons,
            'denials' => $this->denials,
            'metadata' => $this->metadata,
        ];
    }
}
