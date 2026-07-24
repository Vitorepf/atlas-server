<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ProviderLock implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.provider_lock.v1';

    public const RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL = 'native_function_call';

    public const RESPONSE_CHANNEL_FREE_FORM = 'free_form';

    public function __construct(
        public readonly string $provider,
        public readonly string $modelFamily,
        public readonly bool $fallbackAllowed = false,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            provider: AtlasDevSchemaArray::string($payload, 'provider'),
            modelFamily: AtlasDevSchemaArray::string($payload, 'model_family'),
            fallbackAllowed: array_key_exists('fallback_allowed', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'fallback_allowed')
                : false,
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'fallback_allowed' => $this->fallbackAllowed,
            'model_family' => $this->modelFamily,
            'provider' => $this->provider,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    /**
     * The response channel is a derived delivery capability, not a new source
     * of provider choice. A model label is not evidence that the selected
     * transport can declare a function or return its structured arguments:
     * callers may opt into the native channel only with an explicit capability
     * fact from that transport. Current CLI routes provide no such fact, so
     * their compatible single-target channel is free-form.
     *
     * @param  list<string>  $providerCapabilities
     * @return array{channel:string,name?:string,server_packages_patch_plan:bool}
     */
    public function responseContractFor(string $taskKind, string $intent = '', array $providerCapabilities = []): array
    {
        if (in_array(self::RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL, $providerCapabilities, true)
            && $this->isStructuredResponseTask($taskKind, $intent)) {
            return [
                'channel' => self::RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL,
                'name' => 'atlas_apply_patch',
                'server_packages_patch_plan' => true,
            ];
        }

        return [
            'channel' => self::RESPONSE_CHANNEL_FREE_FORM,
            'server_packages_patch_plan' => true,
        ];
    }

    private function isStructuredResponseTask(string $taskKind, string $intent): bool
    {
        if (in_array(strtolower(trim($taskKind)), [
            'tool_use_function_calling',
            'function_calling',
            'structured_response',
            'structured_output',
        ], true)) {
            return true;
        }

        return preg_match('/\b(?:function[\s-]*call|tool[\s-]*(?:call|use)|structured[\s-]*(?:response|output))\b/i', $intent) === 1;
    }
}
