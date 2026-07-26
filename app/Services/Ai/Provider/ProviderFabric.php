<?php

declare(strict_types=1);

namespace App\Services\Ai\Provider;

use App\Services\Ai\Hermes\HermesNativeFcCapabilityAttestor;
use App\Services\Ai\Hermes\HermesNativeFunctionCallSupport;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;

/**
 * ASDD S-PROVIDER-FABRIC: capability + response contract + tool declare seam.
 * Packaging of FC args remains AgentExecutionProviderPortAdapter (shipped).
 */
final class ProviderFabric
{
    public const SCHEMA = 'atlas.provider.fabric.v1';

    /**
     * @return array{schema:string,status:string,capabilities:list<string>,package_owner:string,response_channel_default:string}
     */
    public function contract(string $provider = 'hermes_cli', ?string $model = null): array
    {
        $capabilities = HermesNativeFcCapabilityAttestor::capabilitiesFor($provider, $model);

        return [
            'schema' => self::SCHEMA,
            'status' => 'wired_capability_route',
            'capabilities' => $capabilities,
            'package_owner' => \App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter::class,
            'response_channel_default' => ProviderLock::RESPONSE_CHANNEL_FREE_FORM,
        ];
    }

    /**
     * @return array{channel:string,name?:string,server_packages_patch_plan:bool}
     */
    public function responseContract(string $provider, ?string $model, string $taskType, string $intent = ''): array
    {
        $capabilities = HermesNativeFcCapabilityAttestor::capabilitiesFor($provider, $model);

        return (new ProviderLock(
            provider: $provider,
            modelFamily: $model ?? '',
        ))->responseContractFor($taskType, $intent, $capabilities);
    }

    /**
     * @return list<array{type:string,function:array{name:string,arguments:array<string,mixed>}}>
     */
    public function liftToolCallsFromProviderText(string $output): array
    {
        return HermesNativeFunctionCallSupport::parseToolCallsFromText($output);
    }

    public function atlasApplyPatchToolName(): string
    {
        return HermesNativeFunctionCallSupport::TOOL_NAME;
    }
}
