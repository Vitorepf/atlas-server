<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SLICE C-territory-ladder — WIRING proof of the loop's scope-widener, wired LIVE but CONSERVATIVE.
 *
 * The decision method {@see AtlasLoopCampaignSupervisor::territoryClimbDecision()} is the pure brain
 * the starvation seam consults: at supply exhaustion it widens scope ONLY into an operator-defined rung
 * that PASSES the {@see AtlasLoopTerritoryLadder} no-blinder invariant. We drive the private method in
 * ISOLATION via reflection (newInstanceWithoutConstructor) the way the grinder wiring tests do — never
 * the heavy run() loop.
 *
 * LOAD-BEARING (the safety proof): a rung whose new root has NO frozen judge under it MUST yield
 * widen=false with an 'unprotected_root:...' violation. If the wire bypassed canPromote, an unprotected
 * root WOULD widen — proven by temporarily forcing widen=true (see the inline note on that test).
 */
final class AtlasLoopTerritoryLadderWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    /** Build the supervisor WITHOUT its heavy ctor, then inject a real ladder via reflection. */
    private function supervisor(): AtlasLoopCampaignSupervisor
    {
        $rc = new ReflectionClass(AtlasLoopCampaignSupervisor::class);
        $s = $rc->newInstanceWithoutConstructor();
        $prop = $rc->getProperty('territoryLadder');
        $prop->setAccessible(true);
        $prop->setValue($s, new AtlasLoopTerritoryLadder);

        return $s;
    }

    /**
     * Invoke the private decision directly.
     *
     * @param  list<string>  $current
     * @param  list<string>  $rungs
     * @return array{widen:bool, reason:string, next_roots:?list<string>, violations:list<string>}
     */
    private function decide(array $current, array $rungs, string $campaignId): array
    {
        $m = new ReflectionMethod(AtlasLoopCampaignSupervisor::class, 'territoryClimbDecision');
        $m->setAccessible(true);

        return (array) $m->invoke($this->supervisor(), $current, $rungs, $campaignId);
    }

    /** A real campaign row whose proposals_count IS the certified-leaps count the decision consults. */
    private function campaignWithCertifiedLeaps(int $certified): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'territory-ladder-wiring',
            'base_workspace' => '/tmp',
            'provider' => '',
            'config' => [],
            'proposals_count' => $certified,
        ]);
    }

    public function test_empty_rungs_is_the_gradual_release_no_op(): void
    {
        // The DEFAULT (no atlas.loop.territory_ladder_rungs configured): logged no-op, loop stops as before.
        $decision = $this->decide(
            ['app/Services/Ai/AutonomousEvolution'],
            [], // no operator-defined rung
            'no-campaign-needed',
        );

        $this->assertFalse($decision['widen'], 'no rung => never widens (gradual release default)');
        $this->assertSame('no_rung_defined', $decision['reason']);
        $this->assertNull($decision['next_roots'], 'no rung => no widened scope');
        $this->assertSame([], $decision['violations']);
    }

    public function test_rung_into_an_unprotected_root_is_blocked_by_the_no_blinder_invariant(): void
    {
        // LOAD-BEARING SAFETY PROOF. 'app/Services/Memory' has NO file in FORBIDDEN_SELF_TARGETS under it,
        // so widening there would let the loop edit that territory's judge. canPromote MUST reject it.
        // Seed enough certified leaps so ONLY the safety invariant (not the promotion rule) can block.
        $campaign = $this->campaignWithCertifiedLeaps(99);

        $decision = $this->decide(
            ['app/Services/Ai/AutonomousEvolution'], // current scope is protected
            ['app/Services/Memory'],                 // the rung is NOT protected
            $campaign->id,
        );

        $this->assertFalse($decision['widen'], 'an unprotected new root must NEVER widen (no-blinder invariant)');
        $this->assertSame('blocked', $decision['reason']);
        $this->assertNull($decision['next_roots'], 'blocked => no widened scope is returned');
        $this->assertContains(
            'unprotected_root:app/Services/Memory',
            $decision['violations'],
            'the unprotected new root must be named as the violation',
        );
    }

    public function test_protected_rung_with_enough_certified_leaps_is_promotable_and_widens(): void
    {
        // A rung that IS protected: 'app/Services/Ai/AutonomousEvolution/Verify' has FORBIDDEN_SELF_TARGETS
        // files strictly under it (e.g. AtlasEngineeringHonestyGate.php). With certified_leaps >= K(3),
        // zero red-main and trend-up (the decision hard-codes the latter two), it is promotable.
        $campaign = $this->campaignWithCertifiedLeaps(3);

        $decision = $this->decide(
            ['app/Services/Ai/AutonomousEvolution'],
            ['app/Services/Ai/AutonomousEvolution/Verify'],
            $campaign->id,
        );

        $this->assertTrue($decision['widen'], 'protected rung + enough certified leaps => promotable');
        $this->assertSame('promotable', $decision['reason']);
        $this->assertSame([], $decision['violations']);
        $this->assertIsArray($decision['next_roots']);
        $this->assertContains('app/Services/Ai/AutonomousEvolution', $decision['next_roots'], 'union keeps the current root');
        $this->assertContains('app/Services/Ai/AutonomousEvolution/Verify', $decision['next_roots'], 'union adds the rung');
    }

    public function test_protected_rung_but_too_few_certified_leaps_does_not_widen(): void
    {
        // The PROMOTION RULE half: the rung IS protected (safety invariant holds) but only 1 certified
        // leap (< K=3). Promotion rule fails => not promotable => the loop stops as before. This proves
        // the decision actually consults the campaign's real certified count via canPromote.
        $campaign = $this->campaignWithCertifiedLeaps(1);

        $decision = $this->decide(
            ['app/Services/Ai/AutonomousEvolution'],
            ['app/Services/Ai/AutonomousEvolution/Verify'],
            $campaign->id,
        );

        $this->assertFalse($decision['widen'], 'protected but too few certified leaps => promotion rule blocks widening');
        $this->assertSame('blocked', $decision['reason']);
        $this->assertNull($decision['next_roots']);
        $this->assertContains(
            'insufficient_certified_leaps:1/'.AtlasLoopTerritoryLadder::DEFAULT_CERTIFIED_LEAPS_THRESHOLD,
            $decision['violations'],
            'the real certified count is what the gate consults',
        );
    }

    public function test_the_real_petreo_list_is_what_freezes_the_territory_judge(): void
    {
        // Defense: the wire feeds the REAL FORBIDDEN_SELF_TARGETS as the frozen-safety-file set. A
        // protected rung must contain at least one of those exact pétreo files strictly under it; this
        // anchors the test to the production list, not a stand-in.
        $hasFileUnderVerify = false;
        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $file) {
            if (str_starts_with($file, 'app/Services/Ai/AutonomousEvolution/Verify/')) {
                $hasFileUnderVerify = true;
                break;
            }
        }

        $this->assertTrue(
            $hasFileUnderVerify,
            'the chosen protected rung must really be frozen by the pétreo list — anchors the safety proof',
        );
    }
}
