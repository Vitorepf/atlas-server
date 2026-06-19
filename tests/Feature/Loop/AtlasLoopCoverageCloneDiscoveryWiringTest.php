<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCloneDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §11.6 — coverage-deficit + clone-dedup discovery sources WIRED into the LIVE discover()
 * path. Both lanes were dead (the classes existed + were unit-tested but nothing called
 * them in the feed). This pins that after discover() the upserted target rows carry the
 * coverage shape='characterization_test' (coverage lane fired) and clone_dedup=1.0 +
 * dedup_objective (clone lane fired) — signals that ONLY appear if the wired blocks ran.
 */
final class AtlasLoopCoverageCloneDiscoveryWiringTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

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
        // Quiet the unrelated lanes so we assert on the coverage/clone signals alone.
        config([
            'atlas.loop.discovery_backlog_intents' => false,
            'atlas.loop.prefer_test_backed_targets' => false,
            'atlas.loop.impact_ranking_enabled' => false,
            'atlas.loop.orphan_gate_enabled' => false,
            'atlas.loop.target_cooldown_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /**
     * A decision-dense, self-contained (framework_reach==0), 40<=LOC<=400 file with NO
     * sibling test — the exact coverage-deficit target. The if-ladder carries >=7 real
     * frozen mutants (===, >=, <=, >, return true/false) so the deficit saturates to 1.0.
     */
    private function repoWithUntestedDenseFile(): string
    {
        $d = sys_get_temp_dir().'/atlas-covdisc-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        // Pad with comments to clear the 40-LOC admissibility floor WITHOUT adding any
        // framework reach (no `use App\`, no `::`, no app()/config()/@test).
        $pad = implode("\n", array_map(static fn (int $i): string => '    // pad line '.$i, range(1, 30)));
        $src = <<<PHP
        <?php
        final class DenseDecider
        {
        {$pad}
            public function decide(int \$a, int \$b): bool
            {
                if (\$a === \$b) { return true; }
                if (\$a >= 0 && \$b <= 10) { return false; }
                if (\$a > 0) { return true; }
                return false;
            }
        }
        PHP;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/DenseDecider.php', $src);

        return $d;
    }

    /**
     * Two structural clones (renamed + reformatted copy of the same logic) reused from
     * AtlasLoopCloneDetectorTest. Both admissible (padded to >=40 LOC, framework_reach==0).
     */
    private function repoWithClonePair(): string
    {
        $d = sys_get_temp_dir().'/atlas-clonedisc-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        $pad = implode("\n", array_map(static fn (int $i): string => '    // pad line '.$i, range(1, 30)));
        $a = <<<PHP
        <?php
        final class SumPositiveA
        {
        {$pad}
            public function sumPositive(array \$items): int
            {
                \$total = 0;
                foreach (\$items as \$value) {
                    if (\$value > 0) {
                        \$total = \$total + \$value;
                    }
                }
                return \$total;
            }
        }
        PHP;
        // Same logic, every variable renamed + reindented/recommented => a structural clone.
        $b = <<<PHP
        <?php
        final class SumPositiveB
        {
        {$pad}
            public function sumPositive(array \$entries): int
            {
                \$acc = 0;
                foreach (\$entries as \$element) {
                    if (\$element > 0) {
                        \$acc = \$acc + \$element;
                    }
                }
                return \$acc;
            }
        }
        PHP;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/SumPositiveA.php', $a);
        File::put($d.'/app/Services/SumPositiveB.php', $b);

        return $d;
    }

    private function service(string $repo): AtlasLoopTargetDiscoveryService
    {
        // wiredCallers null (arg 5) => no orphan flagging on the temp file; sibling resolver
        // anchored to the temp repo so hasSibling() reads the temp tests/ tree (empty => no
        // sibling). Coverage source + clone detector are the two newly-wired tail args.
        return new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            null,
            null,
            null,
            new AtlasLoopSiblingTestResolver($repo),
            null,
            new AtlasLoopCoverageDeficitSource,
            new AtlasLoopCloneDetector,
        );
    }

    /** @return array<string,mixed> */
    private function signalsFor(string $campaignId, string $path): array
    {
        $row = DB::table('atlas_loop_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_path', $path)
            ->first();
        $this->assertNotNull($row, "target {$path} must be upserted into the ledger");

        return (array) json_decode((string) $row->signals, true);
    }

    public function test_coverage_deficit_lane_fires_and_stamps_characterization_shape(): void
    {
        $repo = $this->repoWithUntestedDenseFile();
        config(['atlas.loop.discovery_coverage_deficit_enabled' => true]);

        $this->service($repo)->discover($repo, 'camp-cov-on', ['roots' => ['app/Services'], 'limit' => 5]);

        $signals = $this->signalsFor('camp-cov-on', 'app/Services/DenseDecider.php');

        // LOAD-BEARING: shape==='characterization_test' ONLY appears if the coverage block ran
        // AND emitted a high-deficit objective.
        $this->assertSame(
            AtlasLoopCoverageDeficitSource::SHAPE,
            $signals['shape'] ?? null,
            'the coverage-deficit lane must stamp the characterization_test shape',
        );
        $this->assertNotEmpty($signals['coverage_objective'] ?? null, 'a non-empty coverage objective is emitted');
        $this->assertFalse($signals['has_sibling_test'] ?? true, 'the untested file has no sibling');
        $this->assertGreaterThanOrEqual(0.5, (float) ($signals['coverage_deficit'] ?? 0.0));
    }

    public function test_coverage_deficit_lane_inert_when_flag_off(): void
    {
        $repo = $this->repoWithUntestedDenseFile();
        config(['atlas.loop.discovery_coverage_deficit_enabled' => false]);

        $this->service($repo)->discover($repo, 'camp-cov-off', ['roots' => ['app/Services'], 'limit' => 5]);

        $signals = $this->signalsFor('camp-cov-off', 'app/Services/DenseDecider.php');
        $this->assertArrayNotHasKey('shape', $signals, 'flag OFF => no coverage shape stamped');
        $this->assertArrayNotHasKey('coverage_objective', $signals, 'flag OFF => no coverage objective');
        $this->assertArrayNotHasKey('coverage_deficit', $signals, 'flag OFF => coverage lane is byte-identical no-op');
    }

    public function test_clone_dedup_lane_fires_and_stamps_dedup_on_first_file(): void
    {
        $repo = $this->repoWithClonePair();
        config(['atlas.loop.discovery_clone_dedup_enabled' => true]);

        $this->service($repo)->discover($repo, 'camp-clone-on', ['roots' => ['app/Services'], 'limit' => 5]);

        // detectClones targets the FIRST file (string order) => SumPositiveA.php.
        $signals = $this->signalsFor('camp-clone-on', 'app/Services/SumPositiveA.php');

        // LOAD-BEARING: clone_dedup==1.0 ONLY appears if the clone block ran AND found the pair.
        // (JSON round-trips the float 1.0 as the integer 1, so compare numerically.)
        $this->assertArrayHasKey('clone_dedup', $signals, 'the clone-dedup lane must stamp clone_dedup');
        $this->assertEquals(1.0, $signals['clone_dedup'], 'clone_dedup must be 1.0 (the dedup flag)');
        $this->assertSame('app/Services/SumPositiveB.php', $signals['clone_partner'] ?? null);
        $this->assertNotEmpty($signals['dedup_objective'] ?? null, 'a non-empty dedup objective is emitted');
        $this->assertSame('dedup', $signals['work_shape'] ?? null);
        $this->assertGreaterThanOrEqual(0.9, (float) ($signals['clone_similarity'] ?? 0.0));
    }

    public function test_clone_dedup_lane_inert_when_flag_off(): void
    {
        $repo = $this->repoWithClonePair();
        config(['atlas.loop.discovery_clone_dedup_enabled' => false]);

        $this->service($repo)->discover($repo, 'camp-clone-off', ['roots' => ['app/Services'], 'limit' => 5]);

        $signals = $this->signalsFor('camp-clone-off', 'app/Services/SumPositiveA.php');
        $this->assertArrayNotHasKey('clone_dedup', $signals, 'flag OFF => clone lane is byte-identical no-op');
        $this->assertArrayNotHasKey('dedup_objective', $signals);
    }
}
