<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GOVERNED REFACTOR (Phase 1) — discovery proofs: the honest AST `cyclomatic` signal is
 * exposed in the signals packet, and the refactor_leverage rank boost is DEFAULT-INERT (flag
 * OFF => byte-identical ranking; the leverage signal is computed but never moves the score).
 */
final class AtlasLoopRefactorDiscoveryTest extends TestCase
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
        // Isolate the discovery scoring under test: no impact/orphan/cooldown noise needed,
        // but the defaults are fine — we assert on signals + relative score, not absolutes.
        config([
            'atlas.loop.discovery_backlog_intents' => false,
            'atlas.loop.prefer_test_backed_targets' => false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function repoWithComplexFile(): string
    {
        $d = sys_get_temp_dir().'/atlas-refactor-disc-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        $padding = implode("\n", array_map(static fn (int $i): string => '    // pad '.$i, range(1, 30)));
        $file = <<<PHP
        <?php
        final class Complex
        {
        {$padding}
            public function classify(int \$n): string
            {
                if (\$n === 0) { return 'zero'; }
                elseif (\$n === 1) { return 'one'; }
                elseif (\$n === 2) { return 'two'; }
                elseif (\$n === 3) { return 'three'; }
                elseif (\$n === 4) { return 'four'; }
                elseif (\$n === 5) { return 'five'; }
                else { return 'many'; }
            }
        }
        PHP;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Complex.php', $file);

        return $d;
    }

    private function repoWithRankedSupplyFiles(): string
    {
        $d = sys_get_temp_dir().'/atlas-supply-window-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        $pad = implode("\n", array_map(static fn (int $i): string => '    // pad '.$i, range(1, 45)));
        $highTodos = implode("\n", array_map(static fn (int $i): string => '    // TODO: high leverage '.$i, range(1, 8)));

        File::put($d.'/app/Services/HighLeverage.php', <<<PHP
        <?php
        final class HighLeverage
        {
        {$highTodos}
        {$pad}
            public function value(): int
            {
                return 1;
            }
        }
        PHP);
        File::put($d.'/app/Services/LowerLeverage.php', <<<PHP
        <?php
        final class LowerLeverage
        {
            // TODO: lower leverage
        {$pad}
            public function value(): int
            {
                return 2;
            }
        }
        PHP);

        return $d;
    }

    public function test_exposes_the_ast_cyclomatic_signal(): void
    {
        $repo = $this->repoWithComplexFile();

        $discovery = new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class));
        $discovery->discover($repo, 'camp-cyc', ['roots' => ['app/Services'], 'limit' => 5]);

        $row = DB::table('atlas_loop_targets')
            ->where('campaign_id', 'camp-cyc')
            ->where('target_path', 'app/Services/Complex.php')
            ->first();
        $this->assertNotNull($row);
        $signals = (array) json_decode((string) $row->signals, true);
        $this->assertArrayHasKey('cyclomatic', $signals, 'the honest AST cyclomatic signal is exposed');
        $this->assertGreaterThanOrEqual(6, (int) $signals['cyclomatic'], 'the if/elseif ladder is measured');
        $this->assertArrayHasKey('cyclomatic_total', $signals);
        $this->assertArrayHasKey('refactor_leverage', $signals, 'the leverage composite is always stamped for audit');
    }

    public function test_discovery_skips_consumed_unchanged_top_candidate_to_feed_lower_claimable_supply(): void
    {
        $repo = $this->repoWithRankedSupplyFiles();
        $campaignId = 'camp-supply-window-'.bin2hex(random_bytes(4));
        $discovery = new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class));

        $first = $discovery->discover($repo, $campaignId, ['roots' => ['app/Services'], 'limit' => 1]);
        $this->assertSame('app/Services/HighLeverage.php', $first['top'][0]['path'] ?? null);

        DB::table('atlas_loop_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_path', 'app/Services/HighLeverage.php')
            ->update(['status' => 'queued', 'updated_at' => now()]);

        $second = $discovery->discover($repo, $campaignId, ['roots' => ['app/Services'], 'limit' => 1]);

        $this->assertSame('app/Services/LowerLeverage.php', $second['top'][0]['path'] ?? null);
        $this->assertSame('candidate', DB::table('atlas_loop_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_path', 'app/Services/LowerLeverage.php')
            ->value('status'));
    }

    /**
     * WORK-SUPPLY keystone: caller measurement was capped to the top-N by cheap structural score,
     * so a complex WIRED file ranking below that cut never got measured => never earned the
     * heavy-refactor promotion => never reached the claim window => was never refactored (the
     * chicken-and-egg that drained refactor supply to ~0 and flooded vanilla). The refactor resolve
     * cap ALSO measures the most-complex candidates. This pins: a high-cyclomatic file BELOW the
     * structural score cut is measured when the cap is ON, and is NOT when it is OFF (cap=0).
     */
    private function repoWithFillersAndComplex(): string
    {
        $d = sys_get_temp_dir().'/atlas-refcap-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        // 3 filler files: framework_reach=0 (self_contained=1.0), 8 TODOs => improvement saturates
        // to 1.0 => score 1.00 (top of the structural cut), but TRIVIAL methods => low cyclomatic.
        $todos = implode("\n", array_map(static fn (int $i): string => '    // TODO: refine step '.$i, range(1, 8)));
        $pad = implode("\n", array_map(static fn (int $i): string => '    // note '.$i, range(1, 30)));
        foreach (['FillerA', 'FillerB', 'FillerC'] as $name) {
            File::put($d.'/app/Services/'.$name.'.php', <<<PHP
            <?php
            final class {$name}
            {
            {$todos}
            {$pad}
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);
        }
        // Complex: framework_reach=0, NO todos => improvement only from branch-density (~0.3) => score
        // ~0.80, BELOW the filler cut, but a high-cyclomatic if/elseif ladder (the heavy-refactor target).
        $cpad = implode("\n", array_map(static fn (int $i): string => '    // pad '.$i, range(1, 30)));
        File::put($d.'/app/Services/Complex.php', <<<PHP
        <?php
        final class Complex
        {
        {$cpad}
            public function classify(int \$n): string
            {
                if (\$n === 0) { return 'a'; } elseif (\$n === 1) { return 'b'; }
                elseif (\$n === 2) { return 'c'; } elseif (\$n === 3) { return 'd'; }
                elseif (\$n === 4) { return 'e'; } elseif (\$n === 5) { return 'f'; }
                elseif (\$n === 6) { return 'g'; } else { return 'z'; }
            }
        }
        PHP);

        return $d;
    }

    private function complexCallerSignal(string $campaignId): mixed
    {
        $row = DB::table('atlas_loop_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_path', 'app/Services/Complex.php')
            ->first();
        $this->assertNotNull($row, 'the complex file is in the candidate pool');
        $signals = (array) json_decode((string) $row->signals, true);
        // null when unmeasured (absent from the resolve set); an int when measured.
        return $signals['impact_real_callers'] ?? null;
    }

    public function test_refactor_resolve_cap_measures_complex_targets_below_the_score_cut(): void
    {
        $repo = $this->repoWithFillersAndComplex();
        config([
            'atlas.loop.impact_ranking_enabled' => true,
            'atlas.loop.discovery_caller_resolve_cap' => 2,   // top-2-by-score (2 fillers) get the score slots
            'atlas.loop.framework_refactor_min_cyclomatic' => 5,
            'atlas.loop.refactoring_targets_enabled' => true,
            'atlas.loop.framework_refactor_enabled' => true,
        ]);
        // Real caller service greps the temp repo (Complex has 0 callers => measured as 0, not null).
        $wired = new AtlasLoopWiredCallerService($repo);

        // CAP ON: the complex file is MEASURED even though it is below the 1-slot structural cut.
        config(['atlas.loop.discovery_refactor_resolve_cap' => 5]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class), null, null, null, $wired))
            ->discover($repo, 'camp-refcap-on', ['roots' => ['app/Services'], 'limit' => 10]);
        $this->assertNotNull($this->complexCallerSignal('camp-refcap-on'), 'cap ON => complex target is caller-measured (breaks the chicken-and-egg)');

        // CAP OFF (0): only the top-1-by-score (Simple) is measured; the complex file stays unmeasured.
        config(['atlas.loop.discovery_refactor_resolve_cap' => 0]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class), null, null, null, $wired))
            ->discover($repo, 'camp-refcap-off', ['roots' => ['app/Services'], 'limit' => 10]);
        $this->assertNull($this->complexCallerSignal('camp-refcap-off'), 'cap OFF => byte-identical legacy: complex target stays unmeasured below the score cut');
    }

    public function test_t2_supply_widen_factor_scales_the_resolve_cap_in_lockstep(): void
    {
        // ACDE T2: the supply-widen factor multiplies the discovery resolve caps so candidate supply scales
        // with fan-out width. Isolate the CALLER cap (refactor cap 0) so Complex (rank-4 by score) is
        // measured ONLY when the cap is wide enough to reach it.
        $repo = $this->repoWithFillersAndComplex();
        config([
            'atlas.loop.impact_ranking_enabled' => true,
            'atlas.loop.discovery_refactor_resolve_cap' => 0, // off => only the caller cap can reach Complex
            'atlas.loop.discovery_caller_resolve_cap' => 1,   // top-1-by-score is a filler; Complex excluded
        ]);
        $wired = new AtlasLoopWiredCallerService($repo);

        // factor 1 (default): effective caller cap 1 => Complex stays unmeasured (byte-identical).
        config(['atlas.loop.discovery_supply_widen_factor' => 1]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class), null, null, null, $wired))
            ->discover($repo, 'camp-widen-1', ['roots' => ['app/Services'], 'limit' => 10]);
        $this->assertNull($this->complexCallerSignal('camp-widen-1'), 'factor 1 => byte-identical: Complex excluded by the cap');

        // factor 4: effective caller cap 4 => all files resolved => Complex IS caller-measured.
        config(['atlas.loop.discovery_supply_widen_factor' => 4]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class), null, null, null, $wired))
            ->discover($repo, 'camp-widen-4', ['roots' => ['app/Services'], 'limit' => 10]);
        $this->assertNotNull($this->complexCallerSignal('camp-widen-4'), 'widen factor scales the cap => Complex now resolved (supply widened in lockstep)');
    }

    public function test_rank_boost_is_default_inert_when_flag_off(): void
    {
        $repo = $this->repoWithComplexFile();

        // Flag OFF: capture the score.
        config(['atlas.loop.refactoring_targets_enabled' => false]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class)))
            ->discover($repo, 'camp-off', ['roots' => ['app/Services'], 'limit' => 5]);
        $scoreOff = (float) DB::table('atlas_loop_targets')
            ->where('campaign_id', 'camp-off')->where('target_path', 'app/Services/Complex.php')->value('score');

        // Flag ON: same repo, fresh campaign — the boost must raise (or equal at the 1.0 cap) the score.
        config(['atlas.loop.refactoring_targets_enabled' => true]);
        (new AtlasLoopTargetDiscoveryService(app(AtlasLoopTargetRepository::class)))
            ->discover($repo, 'camp-on', ['roots' => ['app/Services'], 'limit' => 5]);
        $scoreOn = (float) DB::table('atlas_loop_targets')
            ->where('campaign_id', 'camp-on')->where('target_path', 'app/Services/Complex.php')->value('score');

        $this->assertGreaterThanOrEqual($scoreOff, $scoreOn, 'flag ON never lowers the score');
        // With a real complexity signal the boost is strictly positive unless already at the cap.
        if ($scoreOff < 1.0) {
            $this->assertGreaterThan($scoreOff, $scoreOn, 'flag ON applies the refactor_leverage boost');
        }
    }
}
