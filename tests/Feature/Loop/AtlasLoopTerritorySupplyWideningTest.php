<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L3 (SUPPLY half) — the TERRITORY LADDER closes the other half of the Fibonacci rung-growth: the ambition
 * dial dares the biggest AVAILABLE candidate, and THIS grows what is available as capability is proven.
 *
 * Two links, both proven monotonic:
 *  (1) capability↑ => territory widens — the climb decision only promotes once proven leaps reach K (>=3) AND
 *      every widened root keeps a frozen judge under it (the no-blinder invariant); below K it stays put.
 *  (2) a widened campaign grows the refill SUPPLY frontier — the QueueRefiller now scans the persisted widened
 *      roots, so cycle n+1 has MORE/BIGGER candidates to dare. Flag-OFF => the global roots exactly as before
 *      => byte-identical.
 */
final class AtlasLoopTerritorySupplyWideningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    /** @param  array<string,mixed>  $config */
    private function campaign(int $certifiedLeaps = 0, array $config = []): AtlasLoopCampaign
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'territory widening',
            'config' => $config,
            'max_seconds' => 60,
        ]);
        if ($certifiedLeaps > 0) {
            // certifiedLeapsFor() reads the persisted proposals_count via a fresh query — set it at the DB.
            DB::table('atlas_loop_campaigns')->where('id', $campaign->id)->update(['proposals_count' => $certifiedLeaps]);
            $campaign->refresh();
        }

        return $campaign;
    }

    private function effectiveRoots(AtlasLoopCampaign $campaign): array
    {
        $refiller = app(AtlasLoopQueueRefiller::class);

        return (new ReflectionMethod($refiller, 'effectiveDiscoveryRoots'))->invoke($refiller, $campaign);
    }

    private function climbDecision(array $current, array $rungs, string $campaignId): array
    {
        $supervisor = app(AtlasLoopCampaignSupervisor::class);

        return (new ReflectionMethod($supervisor, 'territoryClimbDecision'))->invoke($supervisor, $current, $rungs, $campaignId);
    }

    // ---- (2) the refiller scans the widened roots (the supply frontier grows) ----

    public function test_flag_off_refiller_sources_the_global_roots_byte_identical(): void
    {
        config([
            'atlas.loop.territory_widened_roots_drive_refill' => false,
            'atlas.loop.campaign.discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
        ]);
        // Even a campaign whose territory was widened is ignored while the flag is OFF.
        $campaign = $this->campaign(0, ['discovery_roots' => ['app/Services/Ai/AutonomousEvolution', 'app/Services']]);

        $this->assertSame(['app/Services/Ai/AutonomousEvolution'], $this->effectiveRoots($campaign), 'flag OFF => the refiller ignores the widening => global roots exactly as before');
    }

    public function test_flag_on_a_widened_campaign_grows_the_refill_supply_frontier(): void
    {
        config([
            'atlas.loop.territory_widened_roots_drive_refill' => true,
            'atlas.loop.campaign.discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
        ]);
        $base = $this->campaign(0, []);
        $widened = $this->campaign(0, ['discovery_roots' => ['app/Services/Ai/AutonomousEvolution', 'app/Services']]);

        $baseRoots = $this->effectiveRoots($base);
        $widenedRoots = $this->effectiveRoots($widened);

        $this->assertSame(['app/Services/Ai/AutonomousEvolution'], $baseRoots, 'a never-widened campaign => the global base scope');
        $this->assertContains('app/Services', $widenedRoots, 'a territory-widened campaign => the refiller scans the WIDER scope');
        $this->assertGreaterThan(count($baseRoots), count($widenedRoots), 'the SUPPLY frontier strictly grew with the proven widening');
    }

    // ---- (1) capability↑ => territory widens (monotonic, no-blinder-gated) ----

    public function test_capability_below_threshold_does_not_widen(): void
    {
        $campaign = $this->campaign(2); // 2 certified leaps < K=3

        $decision = $this->climbDecision(['app/Services/Ai/AutonomousEvolution'], ['app/Services'], (string) $campaign->id);

        $this->assertFalse($decision['widen'], 'insufficient proven capability => no widening (gradual release)');
    }

    public function test_proven_capability_widens_the_territory(): void
    {
        $campaign = $this->campaign(5); // 5 >= K=3

        $decision = $this->climbDecision(['app/Services/Ai/AutonomousEvolution'], ['app/Services'], (string) $campaign->id);

        $this->assertTrue($decision['widen'], 'proven capability (>=K leaps) + a frozen-protected rung => the territory widens');
        $this->assertContains('app/Services', (array) $decision['next_roots'], 'the widened scope contains the new rung');
    }

    public function test_no_rung_defined_stays_a_logged_noop(): void
    {
        $campaign = $this->campaign(5);

        $decision = $this->climbDecision(['app/Services/Ai/AutonomousEvolution'], [], (string) $campaign->id);

        $this->assertFalse($decision['widen']);
        $this->assertSame('no_rung_defined', $decision['reason'], 'no operator rung => gradual-release no-op');
    }
}
