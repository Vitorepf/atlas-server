<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\Kernel\Provider\AtlasProviderIdentityProjector;
use App\Services\Ai\Kernel\Provider\IdentityFragment;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Provider\ProviderDriverExecutionPlan;
use App\Services\Ai\Kernel\Provider\ProviderDriverExecutionResult;
use App\Services\Ai\Kernel\Provider\ProviderDriverExecutionStatus;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestStatus;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
use Illuminate\Support\Str;

abstract class AbstractCliProviderDriver implements ProviderDriver
{
    public function __construct(
        private readonly ProviderRequestHasher $hasher,
        private readonly ProviderPreparedRequestValidator $validator,
        private readonly AtlasProviderIdentityProjector $identityProjector,
    ) {}

    abstract public function providerId(): string;

    /**
     * @return array<int,string>
     */
    abstract public function supportedModels(): array;

    abstract public function legacyProviderClass(): string;

    public function identityFragment(): IdentityFragment
    {
        return $this->identityProjector->forProvider($this->providerId());
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function prepareRequest(array $prompt, array $context = []): array
    {
        $identity = $this->identityFragment();
        $payload = $this->payloadFromPrompt($prompt);
        $preparedPrompt = $this->promptWithoutPayload($prompt);

        $payload['identity_fragment'] = $identity->toArray();
        $payload = $this->enrichPayload($payload, $prompt, $context);

        $prepared = [
            'schema_version' => 1,
            'provider_driver' => static::class,
            'provider_id' => $this->providerId(),
            'status' => ProviderPreparedRequestStatus::Prepared->value,
            'execution_policy' => [
                'mode' => 'prepare_only',
                'provider_real_execution_allowed' => false,
                'dry_run' => $this->requestedDryRun($prompt, $context),
                'delegates_to_legacy_provider' => $this->legacyProviderClass(),
            ],
            'model' => $this->requestedModel($prompt, $context),
            'supported_models' => $this->supportedModels(),
            'payload' => $payload,
            'prompt' => $preparedPrompt,
            'audit' => [
                'identity_fragment_id' => $identity->identityId,
                'identity_fragment_hash' => $identity->contentHash,
                'identity_injected_at' => now()->toJSON(),
                'request_hash_algorithm' => ProviderRequestHasher::HASH_ALGORITHM,
                'request_hash_canonicalization' => ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION,
            ],
        ];

        $prepared['audit']['request_hash'] = $this->hasher->hashPreparedRequest($this->providerId(), $prepared);

        return $prepared;
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @return array<string,mixed>
     */
    private function payloadFromPrompt(array $prompt): array
    {
        $payload = $prompt['payload'] ?? [];

        return is_array($payload) ? $payload : [
            'raw_payload' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @return array<string,mixed>
     */
    private function promptWithoutPayload(array $prompt): array
    {
        unset($prompt['payload']);
        unset($prompt['execution_policy']);

        return $prompt;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function enrichPayload(array $payload, array $prompt, array $context): array
    {
        return $payload;
    }

    protected function identityFragmentForProvider(string $providerId): IdentityFragment
    {
        return $this->identityProjector->forProvider($providerId);
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function execute(array $request, array $context = []): array
    {
        $validation = $this->validator->validate($this, $request);
        $plan = ProviderDriverExecutionPlan::fromPreparedRequest(
            providerId: $this->providerId(),
            legacyProvider: $this->legacyProviderClass(),
            request: $request,
            context: $context,
            validation: $validation,
        );
        $audit = $plan->audit(
            identityFragmentHash: data_get($request, 'audit.identity_fragment_hash'),
            providerRealExecutionCalled: false,
        );

        if (! $validation['ok']) {
            return (new ProviderDriverExecutionResult(
                providerId: $this->providerId(),
                status: ProviderDriverExecutionStatus::NotExecuted,
                executed: false,
                providerRealExecutionCalled: false,
                legacyProvider: $this->legacyProviderClass(),
                requestHash: $plan->requestHash,
                audit: $audit,
                errors: $validation['errors'],
                warnings: $validation['warnings'],
                metadata: [
                    'reason' => 'ProviderDriver refused to delegate because request was not prepared by a compliant ProviderDriver wrapper.',
                    'integration_stage' => 'provider_driver_wrapper',
                    'execution_plan' => $plan->toArray(),
                ],
            ))->toArray();
        }

        if ($plan->dryRun) {
            return (new ProviderDriverExecutionResult(
                providerId: $this->providerId(),
                status: ProviderDriverExecutionStatus::DryRun,
                executed: false,
                providerRealExecutionCalled: false,
                legacyProvider: $this->legacyProviderClass(),
                requestHash: $plan->requestHash,
                audit: $audit,
                warnings: $validation['warnings'],
                metadata: [
                    'reason' => 'ProviderDriver dry-run respected; no provider process or legacy provider was called.',
                    'integration_stage' => 'provider_driver_wrapper',
                    'execution_plan' => $plan->toArray(),
                ],
            ))->toArray();
        }

        return (new ProviderDriverExecutionResult(
            providerId: $this->providerId(),
            status: ProviderDriverExecutionStatus::DelegatesToLegacyProvider,
            executed: false,
            providerRealExecutionCalled: false,
            legacyProvider: $this->legacyProviderClass(),
            requestHash: $plan->requestHash,
            audit: $audit,
            warnings: $validation['warnings'],
            metadata: [
                'reason' => 'ProviderDriver wrapper is prepared for integration but does not execute the legacy provider in this stage.',
                'integration_stage' => 'provider_driver_wrapper',
                'execution_plan' => $plan->toArray(),
            ],
        ))->toArray();
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     */
    private function requestedModel(array $prompt, array $context): string
    {
        $model = $prompt['model'] ?? $context['model'] ?? $this->supportedModels()[0];

        return Str::limit(trim((string) $model), 160, '');
    }

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     */
    private function requestedDryRun(array $prompt, array $context): bool
    {
        $promptDryRun = data_get($prompt, 'execution_policy.dry_run');

        if (is_bool($promptDryRun)) {
            return $promptDryRun;
        }

        return ($context['dry_run'] ?? null) === true;
    }
}
