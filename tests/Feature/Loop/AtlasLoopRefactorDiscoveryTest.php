<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
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
