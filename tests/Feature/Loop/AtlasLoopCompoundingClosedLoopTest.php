<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopCapabilityTrendService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraCandidateRanker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CAPSTONE — the CLOSED COMPOUNDING LOOP, end-to-end through the REAL services (no fakes for the signal path).
 *
 * The Fibonacci invariant: a DELIVERY raises the loop's own ceiling so the next cycle dares something BIGGER.
 * This proves the closing link the four seam-tests leave separate: improving merged deliveries (the L2/L5 gain
 * surface) make the REAL AtlasLoopCapabilityTrendService bend upward, which lifts the L3 capability_factor the
 * ranker feeds into ambition — and AtlasLoopCapabilityRungGrowthTest already proves factor↑ => rung↑. So:
 *   no proven deliveries => factor 0 (baseline rung)   ──►   a proven upward delivery trend => factor > 0 (bigger rung).
 * That is f(n) feeding f(n+1): the loop compounds, it does not run linearly. Flag-OFF => null (byte-identical).
 */
final class AtlasLoopCompoundingClosedLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'closed compounding loop',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    /** Merge $n deliveries aged $ageHours ago, each clean (green canary) or not (red). */
    private function deliver(string $campaignId, int $ageHours, bool $green, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            AtlasLoopProposal::$governedMergeInProgress = true;
            $p = AtlasLoopProposal::create([
                'campaign_id' => $campaignId,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'delivery',
                'target_path' => 'app/Services/Ai/AutonomousEvolution/Gain'.$ageHours.'_'.$i.'.php',
                'diff_text' => 'x',
                'proposal_hash' => substr(hash('sha256', $ageHours.'|'.$i.'|'.($green ? 'g' : 'r')), 0, 40),
                'merged_to_main' => true,
            ]);
            AtlasLoopProposal::$governedMergeInProgress = false;
            DB::table('atlas_loop_proposals')->where('id', $p->id)->update([
                'quality' => json_encode(['_canary' => ['ran' => true, 'passed' => $green]]),
                'updated_at' => Carbon::now()->subHours($ageHours),
            ]);
        }
    }

    /** The capability_factor the ranker would feed into ambition, via the REAL CapabilityTrendService. */
    private function factor(): ?float
    {
        return (new ReflectionMethod(new AtlasLoopObraCandidateRanker, 'capabilityFactorForContext'))
            ->invoke(new AtlasLoopObraCandidateRanker);
    }

    public function test_improving_deliveries_compound_into_a_higher_capability_ceiling(): void
    {
        config([
            'atlas.loop.capability_ambition_enabled' => true,
            'atlas.loop.capability_trend_enabled' => true,
            'atlas.loop.capability_slope_full' => 0.1, // a strong sustained bend saturates the factor toward full ambition
        ]);
        $campaign = $this->campaign();

        // cycle 0 — nothing delivered yet: the trend is flat, the loop sits at the BASELINE rung.
        $this->assertSame(0.0, $this->factor(), 'no proven deliveries => capability factor 0 => baseline rung');

        // the loop delivers AND IMPROVES: older deliveries had a red canary, recent ones are green => the
        // clean-delivery rate bends upward over time (the only thing that means "capability(t) is rising").
        $this->deliver((string) $campaign->id, 150, false, 2); // oldest bucket: red
        $this->deliver((string) $campaign->id, 5, true, 2);     // newest bucket: green

        // the REAL trend instrument confirms the upward bend (this is the gain surface feeding capability).
        $trend = (new AtlasLoopCapabilityTrendService)->trend();
        $this->assertTrue($trend['bending'], 'a rising clean-delivery rate => the real trend bends upward; buckets='.json_encode($trend['buckets']));

        // cycle n — the proven bend lifts the ceiling: the factor rose from 0, so L3 dares a BIGGER rung.
        $factor = $this->factor();
        $this->assertGreaterThan(0.0, $factor, 'a proven upward delivery trend RAISES the capability factor => the next cycle dares a bigger rung (f(n) feeds f(n+1))');
    }

    public function test_flag_off_is_byte_identical_even_with_deliveries(): void
    {
        config(['atlas.loop.capability_ambition_enabled' => false]);
        $campaign = $this->campaign();
        $this->deliver((string) $campaign->id, 5, true, 3);

        $this->assertNull($this->factor(), 'flag OFF => no capability_factor => the §3 default ambition stands (byte-identical)');
    }
}
