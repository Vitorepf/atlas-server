<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * C — the coverage portfolio cap. Characterization/coverage is verification, useful but NOT evolution; it
 * must never monopolize a campaign (the r24 soak minted 146 characterization proposals). This proves that
 * once a refill has minted the allowed number of coverage tasks, further coverage-deficit targets are
 * DEFERRED — and that the behaviour is governed by the flag (gate OFF ⇒ no cap). Uses the loop's
 * focused-migration setUp (the full suite has a Postgres-only extension; no RefreshDatabase).
 */
final class AtlasLoopCoveragePortfolioCapTest extends TestCase
{
    private string $tmpSource;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        // A trivial production file: it has NO non-cosmetic decision operator, so when the cap guard is
        // SKIPPED the lane falls through to the quarantine path — a 'quarantined' outcome that is cleanly
        // distinguishable from the cap's 'deferred', letting us prove the flag actually controls the cap.
        $this->tmpSource = sys_get_temp_dir().'/atlas-cov-cap-'.getmypid().'.php';
        file_put_contents($this->tmpSource, "<?php\n\nfunction noop(): int { return 1; }\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpSource);
        parent::tearDown();
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'coverage portfolio cap proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    private function target(AtlasLoopCampaign $c): AtlasLoopTarget
    {
        return AtlasLoopTarget::query()->create([
            'campaign_id' => $c->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'app/Svc/Coverage.php',
            'target_key' => hash('sha256', $c->id.'|coverage'),
            'content_hash' => 'h0',
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => 0.9,
            'novelty_score' => 1.0,
            'signals' => ['shape' => AtlasLoopCoverageDeficitSource::SHAPE],
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    /** @return string the lane outcome with the per-refill counter pre-set to $minted. */
    private function invokeLane(int $minted): string
    {
        $refiller = app(AtlasLoopQueueRefiller::class);

        $counter = new ReflectionProperty($refiller, 'coverageMintedThisRefill');
        $counter->setAccessible(true);
        $counter->setValue($refiller, $minted);

        $m = new ReflectionMethod($refiller, 'tryCoverageDeficitCharacterization');
        $m->setAccessible(true);

        $c = $this->campaign();
        $t = $this->target($c);

        return (string) $m->invoke($refiller, $c, $t, ['shape' => AtlasLoopCoverageDeficitSource::SHAPE], '', $this->tmpSource);
    }

    public function test_coverage_is_deferred_once_the_per_refill_cap_is_reached(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.coverage_portfolio_gate_enabled' => true,
            'atlas.loop.coverage_characterization_max_per_refill' => 2,
        ]);

        // counter already AT the cap ⇒ the next coverage target must be DEFERRED (not minted).
        $this->assertSame('deferred', $this->invokeLane(2));
    }

    public function test_gate_off_does_not_apply_the_cap(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.coverage_portfolio_gate_enabled' => false, // cap disabled
            'atlas.loop.coverage_characterization_max_per_refill' => 2,
        ]);

        // Same over-cap counter, but the gate is OFF ⇒ the cap guard is skipped; the lane proceeds and
        // (on this trivial no-operator file) quarantines — proving the flag, not the counter, drives the cap.
        $this->assertNotSame('deferred', $this->invokeLane(5));
    }

    public function test_under_cap_is_not_blocked_by_the_portfolio_gate(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.coverage_portfolio_gate_enabled' => true,
            'atlas.loop.coverage_characterization_max_per_refill' => 2,
        ]);

        // counter below the cap ⇒ the cap does not fire; the lane proceeds (quarantines the trivial file).
        $this->assertNotSame('deferred', $this->invokeLane(0));
    }

    private function makeTask(string $campaignId, string $kind): void
    {
        \App\Models\AtlasLoopTask::query()->create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'pending',
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => 'app/Svc/X.php',
            'objective' => 'x',
            'payload' => ['objective_kind' => $kind],
            'priority' => 0,
            'max_attempts' => 1,
            'dedupe_key' => 'dk-'.bin2hex(random_bytes(5)),
        ]);
    }

    public function test_campaign_cumulative_cap_defers_coverage_once_it_reaches_half_of_substantive(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.coverage_portfolio_gate_enabled' => true,
            'atlas.loop.coverage_relative_to_substantive' => true,
            'atlas.loop.coverage_characterization_max_per_refill' => 99, // isolate the campaign-cumulative cap
        ]);

        $refiller = app(AtlasLoopQueueRefiller::class);
        $counter = new ReflectionProperty($refiller, 'coverageMintedThisRefill');
        $counter->setAccessible(true);
        $counter->setValue($refiller, 0); // per-refill counter not the constraint here

        $m = new ReflectionMethod($refiller, 'tryCoverageDeficitCharacterization');
        $m->setAccessible(true);

        $c = $this->campaign();
        // 2 substantive (non-coverage) tasks already minted, plus 1 coverage already minted.
        $this->makeTask($c->id, 'refactor_extract_class');
        $this->makeTask($c->id, 'self_improvement');
        $this->makeTask($c->id, AtlasLoopCoverageDeficitSource::SHAPE); // coverageCount=1

        // campaignCap = max(1, intdiv(2,2)) = 1; coverageCount(1) >= 1 ⇒ the NEXT coverage is DEFERRED.
        $t = $this->target($c);
        $outcome = (string) $m->invoke($refiller, $c, $t, ['shape' => AtlasLoopCoverageDeficitSource::SHAPE], '', $this->tmpSource);
        $this->assertSame('deferred', $outcome, 'coverage already at floor(substantive/2) ⇒ deferred');
    }
}
