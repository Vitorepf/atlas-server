<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;

/**
 * Transport capability attestor for R104-TRANSPORT.
 *
 * Returns explicit provider capability facts for ProviderLock::responseContractFor.
 * A model family suffix (e.g. kimi-*-FC) is NEVER a transport fact.
 *
 * Hermes native FC is opt-in: config atlas.ai.providers.hermes_cli.native_fc.enabled
 * must be true, and the provider key must be a Hermes execution surface. When
 * enabled, Atlas declares atlas_apply_patch and lifts structured tool_calls from
 * Hermes output into AiProviderResult.metadata for the port packager.
 */
final class HermesNativeFcCapabilityAttestor
{
    /**
     * @return list<string>
     */
    public static function capabilitiesFor(string $provider, ?string $model = null): array
    {
        unset($model); // model labels are never transport capability

        $provider = strtolower(trim($provider));
        if (! self::isHermesSurface($provider)) {
            return [];
        }

        if (! self::nativeFcEnabled()) {
            return [];
        }

        return [ProviderLock::RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL];
    }

    public static function nativeFcEnabled(): bool
    {
        return (bool) config('atlas.ai.providers.hermes_cli.native_fc.enabled', false);
    }

    public static function isHermesSurface(string $provider): bool
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, ['hermes_cli', 'hermes', 'hermes_acp'], true);
    }

    /**
     * Whether a job payload asks Hermes to declare+lift atlas_apply_patch facts.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function jobRequestsNativeFcLift(array $payload): bool
    {
        $hermes = is_array($payload['hermes'] ?? null) ? $payload['hermes'] : [];
        if ((bool) ($hermes['lift_native_function_calls'] ?? false) === true) {
            return true;
        }
        $contract = is_array($payload['response_contract'] ?? null)
            ? $payload['response_contract']
            : (is_array($hermes['response_contract'] ?? null) ? $hermes['response_contract'] : []);

        return ($contract['channel'] ?? null) === ProviderLock::RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL;
    }
}
