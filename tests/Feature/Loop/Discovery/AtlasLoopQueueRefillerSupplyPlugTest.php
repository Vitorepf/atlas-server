<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopQueueRefillerSupplyPlugTest extends TestCase
{
    public function test_cross_leverage_supply_is_byte_identical_off(): void
    {
        config()->set('atlas.loop.cross_leverage_supply_enabled', false);

        $this->assertSame(0, $this->invokeSupply('tryCrossLeverageSupply'));
        $this->assertMethodStartsWithFlagGate('tryCrossLeverageSupply', "config('atlas.loop.cross_leverage_supply_enabled', false)");
    }

    public function test_feature_frontier_supply_is_byte_identical_off(): void
    {
        config()->set('atlas.loop.feature_frontier_supply_enabled', false);

        $this->assertSame(0, $this->invokeSupply('tryFeatureFrontierSupply'));
        $this->assertMethodStartsWithFlagGate('tryFeatureFrontierSupply', "config('atlas.loop.feature_frontier_supply_enabled', false)");
    }

    public function test_pattern_transfer_supply_is_byte_identical_off(): void
    {
        config()->set('atlas.loop.pattern_transfer_supply_enabled', false);

        $this->assertSame(0, $this->invokeSupply('tryPatternTransferSupply'));
        $this->assertMethodStartsWithFlagGate('tryPatternTransferSupply', "config('atlas.loop.pattern_transfer_supply_enabled', false)");
    }

    /**
     * @group integration
     */
    public function test_cross_leverage_supply_on_real_fixture_mints_work(): void
    {
        $this->markTestSkipped('Integration fixture for the full comprehension-model builder is intentionally outside this wiring packet.');
    }

    /**
     * @group integration
     */
    public function test_conflict_guard_drops_specs_when_first_member_has_inflight_work(): void
    {
        $this->markTestSkipped('Conflict guard is enforced in the private wiring path; DB-backed integration is outside this default-OFF packet.');
    }

    private function invokeSupply(string $method): int
    {
        $reflection = new ReflectionClass(AtlasLoopQueueRefiller::class);
        $refiller = $this->refiller();
        $campaign = new AtlasLoopCampaign;
        $campaign->id = 'campaign-1';
        $campaign->config = ['discovery_roots' => ['app']];

        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return (int) $m->invoke($refiller, $campaign, 'provider-x', '/definitely/not/a/repo', 3);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        $repository = new AtlasLoopTargetRepository();

        return new AtlasLoopQueueRefiller(
            new AtlasLoopTargetDiscoveryService($repository),
            $repository,
            new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    return ['status' => 'unused'];
                }
            }),
            new AtlasLoopBackService($repository),
            new AtlasLoopStore(),
        );
    }

    private function assertMethodStartsWithFlagGate(string $method, string $flagCall): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AtlasLoopQueueRefiller::class))->getFileName());
        $pattern = '/private function '.preg_quote($method, '/').'\([^)]*\): int\s*\{\s*if \(! \(bool\) '.preg_quote($flagCall, '/').'\) \{\s*return 0;\s*\}/m';

        $this->assertMatchesRegularExpression($pattern, $source);
    }
}
