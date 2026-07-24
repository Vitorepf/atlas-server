<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Services\Ai\Aaeos\Control\Dispatch\DevLiveDispatcher;
use App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;
use Tests\TestCase;

final class AaeosNativeDevDispatchContractTest extends TestCase
{
    public function test_dev_commissions_typed_native_chain_without_provider_or_mutation(): void
    {
        $result = (new DevLiveDispatcher)->liveDispatch($this->cyclePlan(), [
            'workspace' => base_path(),
            'execute_provider' => false,
            'confirmed_dev_run' => $this->confirmedRun(),
        ]);

        self::assertSame('commissioned', $result['status']);
        self::assertSame('native_dev_commissioned', $result['effects'][0]['kind']);
        self::assertSame('prepared', $result['commissioning']['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['commissioning']['intent_ref']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['commissioning']['run_ref']);
        self::assertSame(SeniorEngineerLoopExecutor::class, $result['commissioning']['owner']);
        self::assertTrue($result['commissioning']['invoked']);
        self::assertSame(KernelRunExecutor::class, $result['commissioning']['downstream']['owner']);
        self::assertTrue($result['commissioning']['downstream']['invoked']);
        self::assertSame(AtlasDevExecutionService::class, $result['commissioning']['downstream']['downstream']['owner']);
        self::assertTrue($result['commissioning']['downstream']['downstream']['invoked']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
        self::assertArrayNotHasKey('session_pack', $result);
        self::assertArrayNotHasKey('next_commands', $result);
        self::assertArrayNotHasKey('recommended_flow', $result['commissioning']);
        self::assertFalse($this->containsKey($result, 'human_in_engineering_loop'));
    }

    /** @param array<string,mixed> $payload */
    private function containsKey(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === $needle || (is_array($value) && $this->containsKey($value, $needle))) {
                return true;
            }
        }

        return false;
    }

    public function test_dev_provider_request_is_refused_in_p1a(): void
    {
        $result = (new DevLiveDispatcher)->liveDispatch($this->cyclePlan(), [
            'workspace' => base_path(),
            'execute_provider' => true,
            'confirmed_dev_run' => $this->confirmedRun(),
        ]);

        self::assertSame('dispatch_refused', $result['status']);
        self::assertSame('provider_execution_refused', $result['effects'][0]['kind']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
    }

    public function test_dev_refuses_unconstituted_aaeos_input_without_fabricating_authority(): void
    {
        $result = (new DevLiveDispatcher)->liveDispatch($this->cyclePlan(), ['workspace' => base_path()]);

        self::assertSame('dispatch_refused', $result['status']);
        self::assertSame('confirmed_dev_run_required', $result['effects'][0]['reason']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
    }

    /** @return array<string,mixed> */
    private function cyclePlan(): array
    {
        return [
            'objective' => ['objective' => 'fix the validation boundary', 'raw' => 'fix validation'],
            'difficulty' => ['level' => 2],
            'admission' => ['allows_execution' => true],
            'world' => [],
        ];
    }

    private function confirmedRun(): ConfirmedDevRun
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'fix the validation boundary',
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
