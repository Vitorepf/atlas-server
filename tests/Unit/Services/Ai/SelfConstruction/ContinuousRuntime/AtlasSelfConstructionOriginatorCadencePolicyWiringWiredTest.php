<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionOriginatorCadencePolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionOriginatorCadencePolicyWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function tearDown(): void
    {
        if ($this->factsPath !== '' && is_file($this->factsPath)) {
            @unlink($this->factsPath);
        }
        parent::tearDown();
    }

    public function test_replenish_action_includes_cadence_decision(): void
    {
        $payload = $this->runReplenish(['claimable_depth' => 1, 'blocked_count' => 0]);

        self::assertArrayHasKey('cadence_decision', $payload['result']);
        self::assertSame(
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_SEED_NOW,
            $payload['result']['cadence_decision']['action'],
        );
    }

    public function test_replenish_action_calls_integration_when_cadence_says_seed_now(): void
    {
        $payload = $this->runReplenish(['claimable_depth' => 1, 'blocked_count' => 0]);

        self::assertNotNull($payload['result']['replenish_result']);
    }

    public function test_replenish_action_skips_integration_when_cadence_says_pause(): void
    {
        $payload = $this->runReplenish([
            'claimable_depth' => 10,
            'blocked_count' => 0,
            'worker_throughput_rate' => 0.1,
        ]);

        self::assertSame(
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_PAUSE,
            $payload['result']['cadence_decision']['action'],
        );
        self::assertNull($payload['result']['replenish_result']);
    }

    public function test_replenish_action_skips_integration_when_cadence_says_unblock_first(): void
    {
        $payload = $this->runReplenish([
            'claimable_depth' => 1,
            'blocked_count' => 9,
        ]);

        self::assertSame(
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_UNBLOCK_FIRST,
            $payload['result']['cadence_decision']['action'],
        );
        self::assertNull($payload['result']['replenish_result']);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function runReplenish(array $facts): array
    {
        $this->factsPath = sys_get_temp_dir().'/atlas-cadence-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->factsPath, json_encode($facts, JSON_THROW_ON_ERROR));

        Artisan::call('atlas:self-construction:continuous-runtime', [
            'action' => 'replenish',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);

        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
