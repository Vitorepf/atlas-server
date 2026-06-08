<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\HermesCliProvider;

class HermesCliProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'hermes_cli';
    }

    public function supportedModels(): array
    {
        return ['hermes_cli_default', 'hermes_selected_by_atlas_decide'];
    }

    public function legacyProviderClass(): string
    {
        return HermesCliProvider::class;
    }

    protected function enrichPayload(array $payload, array $prompt, array $context): array
    {
        $payload['hermes_runtime'] = [
            'schema_version' => 1,
            'role' => 'executive_runtime',
            'atlas_is_sovereign' => true,
            'memory_policy' => data_get($payload, 'hermes.memory_policy', 'off'),
            'requires_executive_mission' => true,
            'requires_decision_receipt' => true,
            'requires_evidence_packet' => true,
        ];

        return $payload;
    }
}
