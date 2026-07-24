<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

final class AgentExecutionProviderPortNativeFcPackagingTest extends TestCase
{
    public function test_server_packages_native_function_call_arguments_without_model_patch_plan_json(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => '',
                'provider' => $provider,
                'model' => $model,
                'tool_calls' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'atlas_apply_patch',
                        'arguments' => [
                            'path' => 'app/StructuredReply.php',
                            'mode' => 'modify',
                            'next' => "<?php\n\nreturn ['ok' => true];\n",
                        ],
                    ],
                ]],
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7-FC',
            'prompt' => 'Resolve the structured function-call task.',
            'claim' => ['allowed_files' => ['app/StructuredReply.php']],
            'response_contract' => ['channel' => 'native_function_call', 'name' => 'atlas_apply_patch'],
        ]);

        self::assertSame('ok', $receipt['status']);
        self::assertSame('native_function_call', $receipt['response_channel']);
        self::assertFalse($receipt['contract_salvaged']);
        self::assertSame(['app/StructuredReply.php'], $receipt['patch_plan']['allowed_files']);
        self::assertSame([
            'path' => 'app/StructuredReply.php',
            'mode' => 'modify',
            'next' => "<?php\n\nreturn ['ok' => true];\n",
        ], $receipt['patch_plan']['patches'][0]);
    }

    public function test_native_function_call_cannot_escape_the_authorized_claim(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => '',
                'provider' => $provider,
                'model' => $model,
                'function_call' => [
                    'name' => 'atlas_apply_patch',
                    'arguments' => ['path' => 'app/Escaped.php', 'mode' => 'modify', 'next' => '<?php'],
                ],
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7-FC',
            'prompt' => 'Resolve the structured function-call task.',
            'claim' => ['allowed_files' => ['app/StructuredReply.php']],
            'response_contract' => ['channel' => 'native_function_call', 'name' => 'atlas_apply_patch'],
        ]);

        self::assertSame('invalid_provider_scope', $receipt['status']);
        self::assertSame('provider_response_scope', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }

    public function test_ambiguous_or_unknown_native_function_call_fails_closed_without_silent_packaging(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => "```php\n<?php return true;\n```",
                'provider' => $provider,
                'model' => $model,
                'tool_calls' => [[
                    'function' => [
                        'name' => 'unrelated_tool',
                        'arguments' => ['path' => 'app/StructuredReply.php', 'mode' => 'modify', 'next' => '<?php return true;'],
                    ],
                ]],
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7-FC',
            'prompt' => 'Resolve the structured function-call task.',
            'claim' => ['allowed_files' => ['app/StructuredReply.php']],
            'response_contract' => ['channel' => 'native_function_call', 'name' => 'atlas_apply_patch'],
        ]);

        self::assertSame('invalid_provider_patch', $receipt['status']);
        self::assertSame('provider_response_patch', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }
}
