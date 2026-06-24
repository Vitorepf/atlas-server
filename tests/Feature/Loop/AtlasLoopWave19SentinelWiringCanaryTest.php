<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopWave19SentinelWiringCanary;
use Tests\TestCase;

final class AtlasLoopWave19SentinelWiringCanaryTest extends TestCase
{
    public function test_flag_on_resolves_canary_and_runs_all_four_sentinels(): void
    {
        config()->set('atlas.loop.sentinels.wave19_enabled', true);
        // Trigger re-registration so the bindings appear under the live config.
        $this->app->register(\App\Providers\AppServiceProvider::class, force: true);

        $canary = $this->app->make(AtlasLoopWave19SentinelWiringCanary::class);
        $digest = $canary->runAll();

        $this->assertCount(4, $digest, 'one entry per wave-19 sentinel');
        $this->assertSame(AtlasLoopWave19SentinelWiringCanary::SENTINEL_IDS, array_keys($digest));
        foreach ($digest as $id => $entry) {
            $this->assertIsBool($entry['verdict'], "sentinel {$id} missing bool verdict");
            $this->assertIsArray($entry['facts'], "sentinel {$id} missing facts array");
        }
        foreach ($digest as $entry) {
            $this->assertArrayNotHasKey('score', $entry);
            $this->assertArrayNotHasKey('rank', $entry);
        }
    }

    public function test_flag_off_yields_empty_digest_and_zero_sentinel_bindings(): void
    {
        config()->set('atlas.loop.sentinels.wave19_enabled', false);
        // Fresh provider boot so any prior bindings under the canary key are discarded.
        $this->refreshApplication();
        config()->set('atlas.loop.sentinels.wave19_enabled', false);

        // Off ⇒ NO singleton bound under the canary key, proving the byte-identical no-op.
        $this->assertFalse(
            $this->app->bound(AtlasLoopWave19SentinelWiringCanary::class),
            'OFF must perform zero container singletons under the sentinel key (pétreo floor preserved)'
        );

        // Even when explicitly constructed, runAll() returns empty under the OFF flag.
        $canary = new AtlasLoopWave19SentinelWiringCanary(
            $this->app->make(\App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherSiblingRoleCoherenceSentinel::class),
            $this->app->make(\App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServedQueueInspectorSweepSentinel::class),
            $this->app->make(\App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServingQueueDiskConformanceSentinel::class),
            $this->app->make(\App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherDocGapOracleCoverageSentinel::class),
        );
        $this->assertSame([], $canary->runAll());
    }
}
