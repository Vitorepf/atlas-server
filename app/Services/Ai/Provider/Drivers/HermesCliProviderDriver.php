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
        $models = [
            'hermes_cli_default',
            'hermes_selected_by_atlas_decide',
            config('atlas.ai.providers.hermes_cli.model'),
        ];
        foreach ((array) config('atlas_rivals.models', []) as $spec) {
            if (($spec['provider'] ?? null) === 'hermes') {
                $models[] = $spec['cli_model'] ?? null;
            }
        }

        return array_values(array_unique(array_filter(
            $models,
            fn (mixed $model): bool => is_string($model) && trim($model) !== '',
        )));
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
