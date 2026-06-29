<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightFactsProducer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the orphan_spike Cortex Insights axis is live end-to-end: with the producer reporting a current orphan
 * absent from the seeded prior snapshot, `atlas:loop:cortex:insights axis --axis=orphan_spike` emits a real
 * orphan_spike observation naming the newly-appeared unwired organ.
 */
final class AtlasCortexInsightsOrphanSpikeTest extends TestCase
{
    private string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas-orphan-snapshot-'.bin2hex(random_bytes(5)).'.json';
        @unlink($this->snapshotPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotPath);
        parent::tearDown();
    }

    public function test_orphan_spike_axis_emits_observation_naming_the_new_orphan(): void
    {
        config(['atlas.loop.cortex.insights.enabled' => true]);

        $known = 'App\\Services\\Ai\\AutonomousEvolution\\Brain\\AlreadyKnownOrphan';
        $newOrphan = 'App\\Services\\Ai\\AutonomousEvolution\\Brain\\NewlyAppearedOrphan';

        // Prior snapshot already knows one orphan; the producer now reports it PLUS a freshly-appeared one.
        file_put_contents($this->snapshotPath, json_encode(['orphan_fqcns' => [$known]]));
        $this->app->bind(
            AtlasCortexInsightFactsProducer::class,
            fn (): AtlasCortexInsightFactsProducer => new AtlasCortexInsightFactsProducer(
                [$known, $newOrphan],
                $this->snapshotPath,
            ),
        );

        $exit = Artisan::call('atlas:loop:cortex:insights', ['action' => 'axis', '--axis' => 'orphan_spike', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded['observation']);
        $this->assertSame('orphan_spike', $decoded['observation']['observation_kind']);
        $this->assertContains($newOrphan, $decoded['observation']['facts']['new_orphan_fqcns']);
        $this->assertNotContains($known, $decoded['observation']['facts']['new_orphan_fqcns'], 'a prior-known orphan is not a NEW spike');
    }
}
