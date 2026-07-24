<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

final class AgentExecutionProviderPortJsonContractTest extends TestCase
{
    public function test_single_target_free_form_solution_is_packaged_and_not_marked_as_a_provider_contract_failure(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => "Solved:\n```php\n<?php\n\nreturn 'fixed';\n```",
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix app/FreeFormReply.php.',
            'claim' => ['allowed_files' => ['app/FreeFormReply.php']],
            'response_contract' => ['channel' => 'free_form'],
        ]);

        self::assertSame('ok', $receipt['status']);
        self::assertSame('free_form', $receipt['response_channel']);
        self::assertTrue($receipt['contract_salvaged']);
        self::assertArrayHasKey('failure_reason', $receipt);
        self::assertNull($receipt['failure_reason']);
        self::assertSame(['app/FreeFormReply.php'], $receipt['patch_plan']['allowed_files']);
    }

    public function test_truly_unusable_provider_text_fails_closed_as_response_encoding(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => 'The answer is forty-two.',
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix app/FreeFormReply.php.',
            'claim' => ['allowed_files' => ['app/FreeFormReply.php']],
            'response_contract' => ['channel' => 'free_form'],
        ]);

        self::assertSame('invalid_provider_contract', $receipt['status']);
        self::assertSame('provider_response_encoding', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }

    public function test_non_native_patch_plan_scope_rejection_exposes_provider_response_scope(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => json_encode(['patch_plan' => [
                    'allowed_files' => ['app/EscapedReply.php'],
                    'patches' => [['path' => 'app/EscapedReply.php', 'mode' => 'modify', 'next' => '<?php return true;']],
                ]], JSON_THROW_ON_ERROR),
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix app/FreeFormReply.php.',
            'claim' => ['allowed_files' => ['app/FreeFormReply.php']],
            'response_contract' => ['channel' => 'free_form'],
        ]);

        self::assertSame('invalid_provider_scope', $receipt['status']);
        self::assertSame('provider_response_scope', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }

    public function test_non_native_patch_plan_patch_rejection_exposes_provider_response_patch(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => json_encode(['patch_plan' => [
                    'allowed_files' => ['app/FreeFormReply.php'],
                    'patches' => [['path' => 'app/FreeFormReply.php', 'mode' => 'delete', 'next' => '<?php return true;']],
                ]], JSON_THROW_ON_ERROR),
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix app/FreeFormReply.php.',
            'claim' => ['allowed_files' => ['app/FreeFormReply.php']],
            'response_contract' => ['channel' => 'free_form'],
        ]);

        self::assertSame('invalid_provider_patch', $receipt['status']);
        self::assertSame('provider_response_patch', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }
}
