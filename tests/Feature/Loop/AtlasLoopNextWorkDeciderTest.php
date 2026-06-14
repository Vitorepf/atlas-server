<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNextWorkDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * DECISION ("o quê a seguir") — frozen proof that the next-work priority is UNGAMEABLE by the
 * merge-quality standard: the band is set by work-SHAPE and RAISE-ONLY corrected from fresh measured
 * truth; the offset is leverage re-resolved FRESH from the campaign workspace (never the stored
 * score); a forged/stale stored score can never cross a band or out-sort a measured target; and the
 * caller resolver is ANCHORED to the campaign workspace, never base_path().
 */
final class AtlasLoopNextWorkDeciderTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private const BAND_EDGE = 2_000;

    private const BAND_REFACTOR = 4_000;

    private const BAND_OBRA = 6_000;

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function decider(): AtlasLoopNextWorkDecider
    {
        return new AtlasLoopNextWorkDecider();
    }

    /** A temp repo whose Hub has $callers real callers and a worst-method cyclomatic of ~$branches. */
    private function repoWith(string $hubBody, int $callers): string
    {
        $d = sys_get_temp_dir().'/atlas-decider-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::ensureDirectoryExists($d.'/app/Callers');
        File::put($d.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nfinal class Hub {\n{$hubBody}\n}\n");
        for ($i = 0; $i < $callers; $i++) {
            File::put($d.'/app/Callers/Caller'.$i.'.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Hub;\nfinal class Caller{$i} { public function go(Hub \$h): int { return \$h->classify(1); } }\n");
        }

        return $d;
    }

    /** A method with ~$n decision points (cyclomatic ≈ n+1). */
    private function complexMethod(int $n): string
    {
        $body = "    public function classify(int \$v): string {\n";
        for ($i = 0; $i < $n; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return $body;
    }

    public function test_bands_are_strictly_ordered_and_non_overlapping(): void
    {
        // Offset can never cross a band: skip(<1000) < edge[2000..2999] < refactor[4000..4999] < obra[6000..6999].
        $repo = $this->repoWith($this->complexMethod(2), 0); // low leverage so offset is small
        $dec = $this->decider();

        $skip = $dec->decide($repo, 'app/Services/Hub.php', ['orphan' => true, 'impact_real_callers' => 0], 1.0, AtlasLoopWorkShapeRouter::SHAPE_SKIP);
        $edge = $dec->decide($repo, 'app/Services/Hub.php', [], 1.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $obra = $dec->decide($repo, 'app/Services/Hub.php', [], 0.0, 'multi_file_refactor');

        $this->assertLessThan(1_000, $skip['priority'], 'skip band floor');
        $this->assertGreaterThanOrEqual(self::BAND_EDGE, $edge['priority']);
        $this->assertLessThan(self::BAND_REFACTOR, $edge['priority'], 'edge offset never crosses into the refactor band');
        $this->assertGreaterThanOrEqual(self::BAND_OBRA, $obra['priority'], 'obra hint lands in the obra band');
    }

    public function test_decider_anchors_callers_to_campaign_workspace_not_base_path(): void
    {
        // The Hub class is unique to this temp repo; base_path() has no such class. A measured
        // caller count of exactly 3 PROVES the resolver grepped THIS tree, not base_path().
        $repo = $this->repoWith($this->complexMethod(12), 3);
        $out = $this->decider()->decide($repo, 'app/Services/Hub.php', [], 0.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);

        $this->assertTrue((bool) $out['receipt']['reresolved'], 'leverage was re-resolved from the workspace');
        $this->assertSame(3, $out['receipt']['measured_callers'], 'callers grepped from the CAMPAIGN workspace, not base_path()');
    }

    public function test_measured_high_leverage_hub_outranks_barely_wired_sibling_across_band(): void
    {
        // A heavy hub the coarse shape under-classified (no sibling => edge hint) must end >= a
        // barely-wired refactor-hinted file, because the band is RAISE-ONLY promoted from fresh truth.
        $hubRepo = $this->repoWith($this->complexMethod(29), 19);   // 19 callers, cx ~30
        $barelyRepo = $this->repoWith($this->complexMethod(10), 1); // 1 caller, cx ~11

        $hub = $this->decider()->decide($hubRepo, 'app/Services/Hub.php', [], 0.1, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $barely = $this->decider()->decide($barelyRepo, 'app/Services/Hub.php', [], 0.9, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);

        $this->assertSame(self::BAND_REFACTOR, $hub['band'], 'the heavy hub is promoted to the refactor band by fresh truth despite the edge hint');
        $this->assertGreaterThanOrEqual($barely['priority'], $hub['priority'], 'higher fresh leverage wins regardless of the stored score');
    }

    public function test_band_promotion_is_raise_only_never_demote(): void
    {
        // A refactor-hinted target with LOW measured leverage keeps the refactor band (not demoted).
        $repo = $this->repoWith($this->complexMethod(2), 1); // cx ~3 < min(10)
        $out = $this->decider()->decide($repo, 'app/Services/Hub.php', [], 0.5, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        $this->assertSame(self::BAND_REFACTOR, $out['band'], 'refactor hint is never demoted by low measured leverage');

        // An UNMEASURED file (ghost path) keeps its router/hint band — promotion never fires on unmeasured data.
        $ghost = $this->decider()->decide($repo, 'app/Services/Ghost.php', [], 0.5, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $this->assertSame(self::BAND_EDGE, $ghost['band'], 'unmeasured target is not promoted (fail-open)');
        $this->assertFalse((bool) $ghost['receipt']['reresolved']);
    }

    public function test_unmeasured_fallback_is_capped_below_any_measured_target(): void
    {
        // Force BOTH fresh reads null (ghost path) with a forged stored score of 1.0 -> offset capped
        // to the ceiling (199). A genuinely measured target in the same band clears it.
        $repo = $this->repoWith($this->complexMethod(12), 5);
        $unmeasured = $this->decider()->decide($repo, 'app/Services/Ghost.php', [], 1.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $measured = $this->decider()->decide($repo, 'app/Services/Hub.php', [], 0.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);

        $this->assertLessThanOrEqual(self::BAND_EDGE + 199, $unmeasured['priority'], 'unmeasured fallback is capped at band+199');
        $this->assertTrue((bool) ($unmeasured['receipt']['unmeasured_fallback'] ?? false));
        $this->assertGreaterThan($unmeasured['priority'], $measured['priority'], 'any measured target out-sorts a fail-open-degraded one in the same band');
    }

    public function test_forged_stored_score_can_never_cross_a_band(): void
    {
        // An absurd forged score on an edge-hinted target can never reach the refactor band floor.
        $repo = $this->repoWith($this->complexMethod(2), 0); // low leverage, measured
        $forgedMeasured = $this->decider()->decide($repo, 'app/Services/Hub.php', [], 999.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $forgedGhost = $this->decider()->decide($repo, 'app/Services/Ghost.php', [], 999.0, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);

        $this->assertLessThan(self::BAND_REFACTOR, $forgedMeasured['priority'], 'forged score cannot cross into the refactor band (measured path ignores it)');
        $this->assertLessThan(self::BAND_REFACTOR, $forgedGhost['priority'], 'forged score cannot cross into the refactor band (capped fallback path)');
    }

    public function test_unknown_shape_hint_is_rederived_not_trusted(): void
    {
        // A forged/garbage hint must NOT land in a band blind — it re-derives via the router. With
        // edge-ish signals (no callers, low cx) the router yields edge_fix, never obra(6000).
        $repo = $this->repoWith($this->complexMethod(2), 0);
        $out = $this->decider()->decide($repo, 'app/Services/Hub.php', ['impact_real_callers' => 1, 'cyclomatic' => 3], 0.5, 'HACKED_OBRA_6000');
        $this->assertLessThan(self::BAND_OBRA, $out['priority'], 'a forged hint cannot buy the obra band');
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $out['shape'], 'unknown hint re-derived to the router shape');
    }
}
