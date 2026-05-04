<?php

namespace App\Services\Ai\ValueObjects;

class OperationalDecision
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

    public function selectedProvider(): string
    {
        return (string) data_get($this->data, 'provider_selection.selected_provider', 'claude_cli');
    }

    public function candidateProvider(): string
    {
        return (string) data_get($this->data, 'provider_selection.candidate_provider', $this->selectedProvider());
    }

    public function fallbackReason(): ?string
    {
        $reason = data_get($this->data, 'provider_selection.fallback_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function policyProfileId(): ?string
    {
        $profile = $this->data['policy_profile_id'] ?? null;

        return is_string($profile) && $profile !== '' ? $profile : null;
    }
}
