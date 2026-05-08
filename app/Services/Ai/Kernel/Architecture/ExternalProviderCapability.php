<?php

namespace App\Services\Ai\Kernel\Architecture;

final readonly class ExternalProviderCapability
{
    /**
     * @param  array<int,string>  $domains
     * @param  array<int,string>  $surfaces
     * @param  array<int,string>  $runtimes
     * @param  array<int,string>  $requiredGates
     * @param  array<int,string>  $sourceRefs
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        public string $capabilityType,
        public string $status,
        public string $authority,
        public array $domains,
        public array $surfaces,
        public array $runtimes,
        public array $requiredGates,
        public array $sourceRefs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'capability_type' => $this->capabilityType,
            'status' => $this->status,
            'authority' => $this->authority,
            'domains' => $this->domains,
            'surfaces' => $this->surfaces,
            'runtimes' => $this->runtimes,
            'required_gates' => $this->requiredGates,
            'source_refs' => $this->sourceRefs,
        ];
    }
}
