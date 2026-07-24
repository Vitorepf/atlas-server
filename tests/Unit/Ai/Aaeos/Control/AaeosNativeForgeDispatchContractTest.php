<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\Dispatch\ForgeLiveDispatcher;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraRuntime;
use Tests\TestCase;

final class AaeosNativeForgeDispatchContractTest extends TestCase
{
    public function test_forge_commissions_canonical_obra_runtime_without_starting_it(): void
    {
        $result = (new ForgeLiveDispatcher)->liveDispatch($this->cyclePlan(), [
            'workspace' => base_path(),
            'execute_provider' => false,
            'forge_commissioning' => $this->commissioning(),
        ]);

        self::assertSame('commissioned', $result['status']);
        self::assertSame('native_forge_commissioned', $result['effects'][0]['kind']);
        self::assertSame(ForgeCommissioning::class, $result['commissioning']['commissioning_owner']);
        self::assertSame(ForgeObraRuntime::class, $result['commissioning']['runtime_owner']);
        self::assertTrue($result['commissioning']['runtime_owner_invoked']);
        self::assertSame('prepared', $result['commissioning']['status']);
        self::assertSame('commissioning_only', $result['commissioning']['authority_status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['commissioning']['commissioning_ref']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
        self::assertArrayNotHasKey('forge_intake', $result);
        self::assertArrayNotHasKey('next_commands', $result);
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

    public function test_forge_provider_request_is_refused_until_p1b_authority_is_green(): void
    {
        $result = (new ForgeLiveDispatcher)->liveDispatch($this->cyclePlan(), [
            'workspace' => base_path(),
            'execute_provider' => true,
            'forge_commissioning' => $this->commissioning(),
        ]);

        self::assertSame('dispatch_refused', $result['status']);
        self::assertSame('provider_execution_refused', $result['effects'][0]['kind']);
        self::assertSame('p1b_authority_not_green', $result['effects'][0]['reason']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
    }

    public function test_forge_refuses_unconstituted_aaeos_input_without_fabricating_authority(): void
    {
        $result = (new ForgeLiveDispatcher)->liveDispatch($this->cyclePlan(), ['workspace' => base_path()]);

        self::assertSame('dispatch_refused', $result['status']);
        self::assertSame('forge_commissioning_required', $result['effects'][0]['reason']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
    }

    /** @return array<string,mixed> */
    private function cyclePlan(): array
    {
        return [
            'objective' => ['objective' => 'deliver a multi-packet authentication obra', 'raw' => 'auth obra'],
            'difficulty' => ['level' => 4],
            'admission' => ['allows_execution' => true],
            'world' => [],
        ];
    }

    private function commissioning(): ForgeCommissioning
    {
        return ForgeCommissioning::fromArray([
            'prompt' => 'deliver a multi-packet authentication obra',
            'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64),
            'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64),
            'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => ForgeCommissioning::RELEASE_POLICY_CANONICAL_COMMIT_WITH_CANARY,
            'interruption_policy' => ForgeCommissioning::INTERRUPTION_POLICY_PAUSE_DRAIN_RESUME,
            'risk_class' => 'R4',
            'topology' => 'DAG',
        ]);
    }
}
