<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use Tests\TestCase;

final class AaeosDailyIntentOnlyCommissioningTest extends TestCase
{
    public function test_daily_raw_dev_and_forge_intents_refuse_without_native_authority_objects(): void
    {
        foreach ([AaeosExecutorMode::DEV, AaeosExecutorMode::FORGE] as $mode) {
            $receipt = (new AaeosCycleRuntime)->runCycle(
                rawIntent: 'commission '.$mode.' without effects',
                hints: [
                    'source' => 'human',
                    'interactive' => true,
                    'live_dispatch' => true,
                    'execute_provider' => false,
                    'workspace' => base_path(),
                ],
                worldOverrides: ['force_mode' => $mode],
                dryRun: false,
            );

            self::assertSame('dispatch_failed', $receipt['status']);
            self::assertSame(0, $receipt['dispatch']['live']['provider_calls']);
            self::assertFalse($receipt['dispatch']['live']['mutation_performed']);
            self::assertSame('blocked', $receipt['dispatch']['live']['effect_level']);
            self::assertSame(
                $mode === AaeosExecutorMode::DEV ? 'confirmed_dev_run_required' : 'forge_commissioning_required',
                $receipt['dispatch']['live']['effects'][0]['reason'],
            );
            self::assertStringNotContainsString('recommended_flow', json_encode($receipt, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('next_commands', json_encode($receipt, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('provider_opt_in_noted', json_encode($receipt, JSON_THROW_ON_ERROR));
            self::assertFalse($this->containsKey($receipt['dispatch']['live'], 'human_in_engineering_loop'));
        }
    }

    public function test_provider_request_is_a_refusal_not_a_green_dispatch(): void
    {
        $receipt = (new AaeosCycleRuntime)->runCycle(
            rawIntent: 'do not spawn a provider',
            hints: [
                'source' => 'human',
                'interactive' => true,
                'live_dispatch' => true,
                'execute_provider' => true,
                'workspace' => base_path(),
            ],
            worldOverrides: ['force_mode' => AaeosExecutorMode::DEV],
            dryRun: false,
        );

        self::assertSame('dispatch_failed', $receipt['status']);
        self::assertSame('blocked', $receipt['effect_level']);
        self::assertSame('confirmed_dev_run_required', $receipt['dispatch']['effects'][0]['reason']);
        self::assertSame(0, $receipt['dispatch']['live']['provider_calls']);
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
}
