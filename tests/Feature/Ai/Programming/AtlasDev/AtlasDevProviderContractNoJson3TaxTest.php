<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use Tests\TestCase;

final class AtlasDevProviderContractNoJson3TaxTest extends TestCase
{
    public function test_hermes_model_label_does_not_fabricate_native_function_call_transport(): void
    {
        $responseContract = (new ProviderLock(
            provider: 'hermes_cli',
            modelFamily: 'kimi-k2.7-FC',
        ))->responseContractFor('tool_use_function_calling');

        self::assertSame('free_form', $responseContract['channel']);
        self::assertSame('free_form', AtlasDecideService::providerResponseContract(
            'hermes_cli',
            'kimi-k2.7-FC',
            'tool_use_function_calling',
        )['channel']);

        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'run_id' => 'p1-json-provider-contract',
            'delivery_id' => 'p1-json-provider-contract',
            'run_hash' => str_repeat('a', 64),
            'mode' => 'dev',
            'risk_class' => 'R3',
            'complexity_band' => 'C1',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'p1-json-intent'),
            'spec_hash' => hash('sha256', 'p1-json-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'p1-json-world'),
            'workspace' => base_path(),
            'base_commit' => str_repeat('b', 40),
            'allowed_scope' => ['app/StructuredReply.php'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['authority_hash' => hash('sha256', 'p1-json-authority')],
            'provider_route' => [
                'provider' => 'hermes_cli',
                'model' => 'kimi-k2.7-FC',
                'response_contract' => $responseContract,
            ],
            'mutate' => true,
        ]);

        $reflection = new \ReflectionClass(EliteExecutorKernel::class);
        $kernel = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('taskContext')->setValue($kernel, [
            'task_goal' => 'Return the structured function-call response for app/StructuredReply.php.',
        ]);
        $prompt = $reflection->getMethod('mutativePrompt')->invoke($kernel, $order);

        self::assertStringContainsString('one complete code fence', $prompt);
        self::assertStringNotContainsString('Reply with ONLY this JSON object', $prompt);
        self::assertStringNotContainsString('"patch_plan"', $prompt);
    }

    public function test_multi_target_free_form_preference_falls_back_to_the_explicit_canonical_json_contract(): void
    {
        $responseContract = (new ProviderLock(
            provider: 'hermes_cli',
            modelFamily: 'kimi-k2.7',
        ))->responseContractFor('patch');
        self::assertSame('free_form', $responseContract['channel']);

        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'run_id' => 'p1-json-free-form-contract',
            'delivery_id' => 'p1-json-free-form-contract',
            'run_hash' => str_repeat('c', 64),
            'mode' => 'dev',
            'risk_class' => 'R3',
            'complexity_band' => 'C1',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'p1-json-free-form-intent'),
            'spec_hash' => hash('sha256', 'p1-json-free-form-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'p1-json-free-form-world'),
            'workspace' => base_path(),
            'base_commit' => str_repeat('d', 40),
            'allowed_scope' => ['app/FreeFormReply.php', 'app/SecondReply.php'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['authority_hash' => hash('sha256', 'p1-json-free-form-authority')],
            'provider_route' => [
                'provider' => 'hermes_cli',
                'model' => 'kimi-k2.7',
                'response_contract' => $responseContract,
            ],
            'mutate' => true,
        ]);

        $reflection = new \ReflectionClass(EliteExecutorKernel::class);
        $kernel = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('taskContext')->setValue($kernel, [
            'task_goal' => 'Fix app/FreeFormReply.php.',
        ]);
        $prompt = $reflection->getMethod('mutativePrompt')->invoke($kernel, $order);

        self::assertStringNotContainsString('one complete code fence', $prompt);
        self::assertStringContainsString('Reply with ONLY this JSON object', $prompt);
        self::assertStringContainsString('"patch_plan"', $prompt);
    }
}
