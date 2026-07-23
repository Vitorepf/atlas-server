<?php

namespace App\Services\Ai\Decide;

use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use InvalidArgumentException;

/**
 * GOD-DEBULK D3: kernel-contract receipt family relocated verbatim from
 * AtlasDecideService so the central Atlas Decide façade stays under 2000 LOC.
 * Bodies are byte-identical to the pre-split service (only the dispatched entry
 * `kernelContractReceipts` changed private->public); no routing logic changed.
 * The façade delegates here. The scanner source-pin for the `provider.prepare`
 * KernelSloProbe stage was relocated to this file under GOD-DEBULK D3 with its
 * str_contains strength unchanged (see SloAudit::scanSloObservability).
 */
class KernelContractSection
{
    public function __construct(
        private readonly SurfaceAdapterRegistry $surfaceAdapters,
        private readonly ProviderDriverRegistry $providerDrivers,
        private readonly ProviderPreparedRequestValidator $providerRequestValidator,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function kernelContractReceipts(
        array $options,
        array $policy,
        array $plan,
        string $decisionId,
        string $selectedProvider,
        ?string $selectedModel,
    ): array {
        $surface = $this->surfaceContractReceipt($options, $policy);
        $provider = $this->providerDriverReceipt($selectedProvider, $selectedModel, $decisionId, $plan);
        $blockingErrors = $this->kernelContractBlockingErrors($surface, $provider);

        return [
            'schema_version' => 1,
            'valid' => $blockingErrors === [],
            'execution_allowed' => $blockingErrors === [],
            'blocking_errors' => $blockingErrors,
            'surface' => $surface,
            'provider' => $provider,
        ];
    }

    /**
     * @param  array<string,mixed>  $surface
     * @param  array<string,mixed>  $provider
     * @return array<int,string>
     */
    private function kernelContractBlockingErrors(array $surface, array $provider): array
    {
        $errors = [];

        if (($surface['status'] ?? null) !== 'normalized') {
            $errors[] = 'surface_contract_not_normalized';
        }

        if (($provider['status'] ?? null) !== 'prepared') {
            $errors[] = 'provider_contract_not_prepared';
        }

        foreach ((array) data_get($provider, 'validation.errors', []) as $error) {
            if (is_string($error) && trim($error) !== '') {
                $errors[] = 'provider_validation:'.$error;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function surfaceContractReceipt(array $options, array $policy): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $surfaceId = $this->resolveSurfaceAdapterId($options, $policy);

        try {
            $adapter = $this->surfaceAdapters->get($surfaceId);
            $input = $adapter->normalizeInput(array_merge($payload, [
                'text' => (string) ($options['input_text'] ?? data_get($payload, 'text', '')),
                'source_type' => $options['source_type'] ?? data_get($payload, 'source_type'),
            ]));

            return [
                'status' => 'normalized',
                'surface_id' => $adapter->surfaceId(),
                'capabilities' => $adapter->supportedCapabilities(),
                'input_hash' => $input->inputHash,
                'primary_type' => $input->primaryType,
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'unregistered_surface',
                'surface_id' => $surfaceId,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     */
    private function resolveSurfaceAdapterId(array $options, array $policy): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $sourceType = strtolower((string) ($options['source_type'] ?? data_get($payload, 'source_type', '')));
        $surfaceId = strtolower((string) data_get($payload, 'surface_id', ''));
        $surface = strtolower((string) (data_get($payload, 'app_surface') ?? $policy['surface'] ?? ''));
        $workflow = strtolower((string) (data_get($payload, 'atlas_workflow_mode') ?? data_get($payload, 'mode') ?? $policy['mode'] ?? ''));
        $routingTask = strtolower((string) (data_get($payload, 'routing_task') ?? data_get($payload, 'programming_flow') ?? ''));

        if ($surfaceId === 'atlas_code' || $surface === 'atlas_code') {
            return 'atlas_code';
        }

        if ($sourceType === 'app' || $surface === 'atlas_app' || $surface === 'app') {
            return 'atlas_app';
        }

        if ($sourceType === 'api' || str_contains($surface, 'api')) {
            return 'atlas_api_interaction';
        }

        if ($workflow === 'forge' || $routingTask === 'forge') {
            return 'atlas_cli_forge';
        }

        if ($workflow === 'dev' || $routingTask !== '') {
            return 'atlas_cli_dev';
        }

        return 'atlas_cli_chat';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function providerDriverReceipt(string $selectedProvider, ?string $selectedModel, string $decisionId, array $plan): array
    {
        try {
            $driver = $this->providerDrivers->get($selectedProvider);
            $providerContract = $this->slo->measure('provider.prepare', function () use ($driver, $selectedModel, $decisionId, $plan): array {
                $prepared = $driver->prepareRequest([
                    'model' => $selectedModel ?: 'selected-by-decide',
                    'payload' => [
                        'decision_id' => $decisionId,
                        'task_profile' => $plan['task_profile'] ?? [],
                    ],
                ], [
                    'decision_id' => $decisionId,
                    'model' => $selectedModel ?: null,
                ]);

                return [
                    'prepared' => $prepared,
                    'validation' => $this->providerRequestValidator->validate($driver, $prepared),
                ];
            }, [
                'envelope_id' => 'provider_prepare_pre_envelope',
                'correlation_id' => $decisionId,
                'provider' => $selectedProvider,
                'model' => $selectedModel ?: 'selected-by-decide',
                'domain' => data_get($plan, 'task_profile.domain'),
                'flow' => data_get($plan, 'task_profile.flow'),
            ]);
            $prepared = $providerContract['prepared'];
            $validation = $providerContract['validation'];

            return [
                'status' => $validation['ok'] ? 'prepared' : 'provider_contract_failed',
                'provider_id' => $driver->providerId(),
                'model' => $prepared['model'] ?? null,
                'identity_fragment_id' => data_get($prepared, 'audit.identity_fragment_id'),
                'identity_fragment_hash' => data_get($prepared, 'audit.identity_fragment_hash'),
                'identity_fragment_source' => data_get($prepared, 'payload.identity_fragment.metadata.source'),
                'identity_fragment_fallback' => (bool) data_get($prepared, 'payload.identity_fragment.metadata.fallback', false),
                'request_hash' => data_get($prepared, 'audit.request_hash'),
                'request_hash_algorithm' => data_get($prepared, 'audit.request_hash_algorithm'),
                'request_hash_canonicalization' => data_get($prepared, 'audit.request_hash_canonicalization'),
                'delegates_to_legacy_provider' => data_get($prepared, 'execution_policy.delegates_to_legacy_provider'),
                'validation' => $validation,
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'unregistered_provider_driver',
                'provider_id' => $selectedProvider,
                'reason' => $exception->getMessage(),
            ];
        }
    }
}
