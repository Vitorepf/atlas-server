<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the WIRED-targeting upgrade: the real-caller resolver and the ungameable
 * Utility/Impact grade that re-resolves the caller graph fresh (never trusts the
 * stored receipt count, so a relabeled orphan cannot inflate the number).
 */
final class AtlasLoopWiredTargetingTest extends TestCase
{
    private ?AtlasLoopCampaign $campaign = null;

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

    public function test_wired_caller_grep_counts_production_callers_and_excludes_tests(): void
    {
        $root = sys_get_temp_dir().'/atlas_wired_'.bin2hex(random_bytes(5));
        @mkdir($root.'/app/Svc', 0700, true);
        @mkdir($root.'/app/Callers', 0700, true);
        @mkdir($root.'/tests/Unit', 0700, true);

        file_put_contents($root.'/app/Svc/UniquelyNamedTargetService.php', "<?php\nclass UniquelyNamedTargetService {}\n");
        file_put_contents($root.'/app/Callers/RealConsumer.php', "<?php\nuse App\\Svc\\UniquelyNamedTargetService;\nnew UniquelyNamedTargetService();\n");
        // A test referencing it must NOT count as a production caller.
        file_put_contents($root.'/tests/Unit/UniquelyNamedTargetServiceTest.php', "<?php\nnew UniquelyNamedTargetService();\n");

        $svc = new AtlasLoopWiredCallerService($root);
        $this->assertSame(1, $svc->callerCount('app/Svc/UniquelyNamedTargetService.php'), 'one production caller, the test excluded');

        // An orphan with only a test reference reads as 0.
        file_put_contents($root.'/app/Svc/OrphanScaffoldService.php', "<?php\nclass OrphanScaffoldService {}\n");
        file_put_contents($root.'/tests/Unit/OrphanScaffoldServiceTest.php', "<?php\nnew OrphanScaffoldService();\n");
        $this->assertSame(0, $svc->callerCount('app/Svc/OrphanScaffoldService.php'), 'test-only reference = orphan');

        @exec('rm -rf '.escapeshellarg($root));
    }

    public function test_wired_caller_fails_open_to_null_when_unmeasured(): void
    {
        // Nonexistent repo + no resolvable FQCN => NEITHER grep nor graph produced a
        // signal => tri-state null (unmeasured), so the merge value gate fails OPEN
        // instead of treating a real hub as a false orphan. Never a crash, never 0.
        $svc = new AtlasLoopWiredCallerService(sys_get_temp_dir().'/atlas_none_'.bin2hex(random_bytes(4)));

        $this->assertNull($svc->callerCount('app/Services/Ai/DefinitelyNotARealClass99.php'), 'unmeasured => null (fail-open)');
        // callerCounts returns only MEASURED paths, so an unmeasured path is absent.
        $this->assertArrayNotHasKey('app/Services/Ai/DefinitelyNotARealClass99.php', $svc->callerCounts(['app/Services/Ai/DefinitelyNotARealClass99.php']));
    }

    public function test_utility_grade_reproduces_low_baseline_on_orphan_trivial_ledger(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->seedMergedProposal("app/Services/Ai/Aaeos/Generated/Orphan{$i}Service.php", 'generated', 'bug', 4, false, null);
        }

        $grade = app(AtlasLoopUtilityGradeService::class)->grade(50);

        $this->assertSame(6, $grade['graded_merges']);
        $this->assertLessThan(4.0, $grade['grade'], 'orphan+trivial ledger cannot score high');
        $this->assertSame(0.0, $grade['axes']['wired'], 'generated orphans contribute zero WIRED');
        $this->assertSame(0.0, $grade['axes']['non_trivial'], 'trivial diffs contribute zero NON_TRIVIAL');
    }

    public function test_gamed_single_axis_cannot_buy_a_high_grade(): void
    {
        // Every merge claims a real, large, correctness, canary-green diff — but with ZERO
        // real callers (fresh re-resolve), WIRED (0.35) + COMPOUNDING (0.20) stay 0.
        for ($i = 0; $i < 10; $i++) {
            $this->seedMergedProposal("app/Services/Ai/Orphan/Pretender{$i}Service.php", 'real', 'bug', 80, true, true);
        }

        $grade = app(AtlasLoopUtilityGradeService::class)->grade(50);

        $this->assertSame(0.0, $grade['axes']['wired'], 'no real callers => WIRED re-resolves to 0 regardless of receipt claims');
        $this->assertLessThan(7.0, $grade['grade'], 'a gamed-but-orphan ledger is structurally capped well under 9.3');
    }

    public function test_value_gate_blocks_orphan_passes_wired_and_fails_open_on_degraded_infra(): void
    {
        $proposal = AtlasLoopProposal::create([
            'campaign_id' => $this->campaign()->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix a real bug',
            'target_path' => 'app/Services/Ai/Foo/BarService.php',
            'diff_text' => "diff --git a/app/Services/Ai/Foo/BarService.php b/app/Services/Ai/Foo/BarService.php\n+        \$x = 1;\n",
            'proposal_hash' => bin2hex(random_bytes(8)),
        ]);

        $svc = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService::class);
        $m = new \ReflectionMethod($svc, 'valueGateVerdict');
        $m->setAccessible(true);
        $changed = ['app/Services/Ai/Foo/BarService.php'];

        // measured orphan (0 callers) => blocked.
        $orphan = $m->invoke($svc, $proposal, $changed, 0);
        $this->assertFalse($orphan['passed'], 'measured 0-caller orphan must be blocked');

        // wired (>=1 caller) => passes.
        $wired = $m->invoke($svc, $proposal, $changed, 6);
        $this->assertTrue($wired['passed'], 'a wired target must pass the value gate');

        // degraded infra (unmeasured => null) => fails OPEN (passes), never a false block.
        $degraded = $m->invoke($svc, $proposal, $changed, null);
        $this->assertTrue($degraded['passed'], 'unmeasured callers must fail OPEN, not block a real fix');
    }

    private function campaign(): AtlasLoopCampaign
    {
        if ($this->campaign === null) {
            AtlasLoopProposal::$governedMergeInProgress = false;
            $this->campaign = AtlasLoopCampaign::create([
                'id' => (string) Str::uuid(),
                'schema_version' => 'atlas.loop.campaign.v1',
                'status' => 'running',
                'goal' => 'wired targeting proof',
                'config' => [],
                'max_seconds' => 60,
            ]);
        }

        return $this->campaign;
    }

    private function seedMergedProposal(string $target, string $targetKind, string $category, int $touchedLines, bool $canaryRan, ?bool $canaryPassed): void
    {
        $proposal = AtlasLoopProposal::create([
            'campaign_id' => $this->campaign()->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'seed',
            'target_path' => $target,
            'diff_text' => '',
            'proposal_hash' => bin2hex(random_bytes(8)),
            'quality' => [
                '_impact_receipt' => [
                    'schema_version' => 'atlas.loop.impact_receipt.v1',
                    'category' => $category,
                    'target_kind' => $targetKind,
                    'real_vs_generated' => $targetKind === 'generated' ? 'generated' : 'real',
                    'target_path' => $target,
                    'size' => ['touched_lines' => $touchedLines, 'files_changed' => 1],
                    'real_callers' => 0,
                    'impact_score' => 0.6,
                ],
                '_canary' => ['ran' => $canaryRan, 'passed' => $canaryPassed, 'target' => null],
            ],
        ]);

        AtlasLoopProposal::$governedMergeInProgress = true;
        $proposal->forceFill(['merged_to_main' => true, 'reviewed_at' => now()])->save();
        AtlasLoopProposal::$governedMergeInProgress = false;
    }
}
