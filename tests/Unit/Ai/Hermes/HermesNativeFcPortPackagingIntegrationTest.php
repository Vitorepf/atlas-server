<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\Hermes\HermesNativeFunctionCallSupport;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

/**
 * Hermetic product-path proof: when Hermes-shaped metadata carries lifted
 * atlas_apply_patch tool_calls, the port packages patch_plan without model JSON³.
 */
final class HermesNativeFcPortPackagingIntegrationTest extends TestCase
{
    public function test_port_packages_lifted_hermes_tool_calls_from_metadata(): void
    {
        $toolCalls = HermesNativeFunctionCallSupport::parseToolCallsFromText(json_encode([
            'tool_calls' => [[
                'name' => 'atlas_apply_patch',
                'arguments' => [
                    'path' => 'app/StructuredReply.php',
                    'mode' => 'modify',
                    'next' => "<?php\n\nreturn ['ok' => true];\n",
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES) ?: '');

        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => '',
                'provider' => $provider,
                'model' => $model,
                'metadata' => [
                    'hermes_transport' => 'cli',
                    'atlas_apply_patch_declared' => true,
                    'atlas_native_fc_lifted' => true,
                    'tool_calls' => $toolCalls,
                ],
                'tool_calls' => $toolCalls,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'qwen3.6-27b',
            'prompt' => 'Resolve the structured function-call task.',
            'claim' => ['allowed_files' => ['app/StructuredReply.php']],
            'response_contract' => [
                'channel' => 'native_function_call',
                'name' => 'atlas_apply_patch',
                'server_packages_patch_plan' => true,
            ],
        ]);

        self::assertSame('ok', $receipt['status']);
        self::assertSame('native_function_call', $receipt['response_channel']);
        self::assertFalse($receipt['contract_salvaged']);
        self::assertSame('app/StructuredReply.php', $receipt['patch_plan']['patches'][0]['path']);
    }
}
