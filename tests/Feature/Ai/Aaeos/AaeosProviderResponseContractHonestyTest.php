<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Services\Ai\Aaeos\Control\Dispatch\DevLiveDispatcher;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;
use Tests\TestCase;

final class AaeosProviderResponseContractHonestyTest extends TestCase
{
    public function test_direct_dev_and_aaeos_commissioning_share_the_free_form_contract_owner_without_provider_effect(): void
    {
        $directDevContract = (new ProviderLock(
            provider: 'hermes_cli',
            modelFamily: 'kimi-k2.7-FC',
        ))->responseContractFor('tool_use_function_calling');
        self::assertSame('free_form', $directDevContract['channel']);
        self::assertSame($directDevContract, AtlasDecideService::providerResponseContract(
            'hermes_cli',
            'kimi-k2.7-FC',
            'tool_use_function_calling',
        ));

        // P1a deliberately refuses provider execution. This proves the AAEOS
        // route reaches the same native Dev owner; it is not a fake provider run.
        $commissioning = (new DevLiveDispatcher)->liveDispatch($this->cyclePlan(), [
            'workspace' => base_path(),
            'execute_provider' => false,
            'confirmed_dev_run' => $this->confirmedRun(),
        ]);

        self::assertSame('commissioned', $commissioning['status']);
        self::assertSame(SeniorEngineerLoopExecutor::class, $commissioning['commissioning']['owner']);
        self::assertSame(KernelRunExecutor::class, $commissioning['commissioning']['downstream']['owner']);
        self::assertSame(AtlasDevExecutionService::class, $commissioning['commissioning']['downstream']['downstream']['owner']);
        self::assertSame(0, $commissioning['provider_calls']);
        self::assertFalse($commissioning['mutation_performed']);
    }

    public function test_unusable_response_is_visible_as_encoding_failure_and_never_becomes_a_patch(): void
    {
        $adapter = new \App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter(
            new \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => 'I cannot form a patch from this.',
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7-FC',
            'prompt' => 'Use the native patch function.',
            'claim' => ['allowed_files' => ['app/Shared.php']],
            'response_contract' => ['channel' => 'native_function_call', 'name' => 'atlas_apply_patch'],
        ]);

        self::assertSame('invalid_provider_contract', $receipt['status']);
        self::assertSame('provider_response_encoding', $receipt['failure_reason']);
        self::assertArrayNotHasKey('patch_plan', $receipt);
    }

    /** @return array<string,mixed> */
    private function cyclePlan(): array
    {
        return [
            'objective' => ['objective' => 'fix the response contract', 'raw' => 'fix response contract'],
            'difficulty' => ['level' => 2],
            'admission' => ['allows_execution' => true],
            'world' => [],
        ];
    }

    private function confirmedRun(): ConfirmedDevRun
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'fix app/Shared.php',
            'workspace' => base_path(),
            'operator_id' => 'native-owner',
            'product_intent_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
            'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R2',
            'duration_regime' => 'interactive',
            'topology' => 'single',
            'mutate' => false,
            'constraints' => [],
        ]);

        return ConfirmedDevRun::fromIntent($intent, $intent->operatorId, $intent->authorityHash);
    }
}
