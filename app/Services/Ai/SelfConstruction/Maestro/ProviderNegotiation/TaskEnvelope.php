<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class TaskEnvelope
{
    /**
     * @param  list<string>  $requiredCapabilities
     * @param  list<string>  $providerIds
     */
    public function __construct(
        public string $taskId,
        public string $kind,
        public array $requiredCapabilities,
        public string $deadline,
        public bool $localOnly,
        public string $sensitivityClass,
        public array $providerIds = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'kind' => $this->kind,
            'required_capabilities' => array_values($this->requiredCapabilities),
            'deadline' => $this->deadline,
            'local_only_bool' => $this->localOnly,
            'sensitivity_class' => $this->sensitivityClass,
            'provider_ids' => array_values($this->providerIds),
        ];
    }
}
