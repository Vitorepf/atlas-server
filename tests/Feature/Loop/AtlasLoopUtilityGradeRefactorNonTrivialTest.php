<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * MERGE-QUALITY >=9, HONESTLY (the by-construction proof + the anti-gaming negative control).
 *
 * The grade was blind to the loop's substantive output: a behavior-preserving complexity-reducing
 * framework refactor (proven by the semantic certifier) scored category=unknown -> NON_TRIVIAL=0.
 * The honest fix makes the grade RE-MEASURE the AST max-per-method cyclomatic drop from the REAL
 * merge commit (git show parent-vs-commit) and credit NON_TRIVIAL only when the drop is real. For
 * a refactor objective the re-measure is AUTHORITATIVE — never the writable objective/diff text.
 *
 * This test proves: (1) a window of proven-refactor hub merges computes grade >=9 BY CONSTRUCTION;
 * (2) a "refactor" whose committed diff does NOT reduce complexity (even with edge_case keywords
 * like "null"/"fallback" in the diff) is NOT credited — the keyword path cannot launder it;
 * (3) a genuine correctness merge still counts via the existing category path (no regression).
 */
final class AtlasLoopUtilityGradeRefactorNonTrivialTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

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

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function git(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return trim($p->getOutput());
    }

    /**
     * Build a temp repo with: a high-cyclomatic hub Foo.php + 3 real callers (parent commit), then
     * a genuine simplification of Foo.php (child commit = a REAL AST drop). Returns [repo, dropSha].
     */
    private function repoWithProvenDrop(): array
    {
        $d = sys_get_temp_dir().'/atlas-grade9-'.bin2hex(random_bytes(4));
        @mkdir($d.'/app/Services', 0o755, true);
        @mkdir($d.'/app/Callers', 0o755, true);
        $this->dirs[] = $d;

        // High-complexity hub: one method with ~16 branches => max-per-method cyclomatic ~17.
        $ifs = '';
        for ($i = 0; $i < 16; $i++) {
            $ifs .= "        if (\$n === {$i}) { return 'k{$i}'; }\n";
        }
        file_put_contents($d.'/app/Services/Foo.php', "<?php\nnamespace App\\Services;\nfinal class Foo\n{\n    public function classify(int \$n): string\n    {\n{$ifs}        return 'z';\n    }\n}\n");
        // 3 real production callers (so the fresh caller re-grep returns >=3 => wired + hub).
        foreach (['CallerA', 'CallerB', 'CallerC'] as $caller) {
            file_put_contents($d.'/app/Callers/'.$caller.'.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Foo;\nfinal class {$caller} { public function go(Foo \$f): string { return \$f->classify(1); } }\n");
        }
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // Genuine simplification: the 16-branch method becomes a lookup (max ~2, total drops),
        // a >15-line diff.
        file_put_contents($d.'/app/Services/Foo.php', "<?php\nnamespace App\\Services;\nfinal class Foo\n{\n    private const MAP = ['k0','k1','k2','k3','k4','k5','k6','k7','k8','k9','k10','k11','k12','k13','k14','k15'];\n    public function classify(int \$n): string\n    {\n        return self::MAP[\$n] ?? 'z';\n    }\n}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'refactor: reduce complexity of Foo', '--no-gpg-sign']);
        $dropSha = $this->git($d, ['rev-parse', 'HEAD']);

        return [$d, $dropSha];
    }

    /** In the SAME repo, add a fake "refactor" of Bar.php: >15 changed lines incl. "null", but NO max drop. */
    private function addFakeRefactor(string $d): string
    {
        file_put_contents($d.'/app/Services/Bar.php', "<?php\nnamespace App\\Services;\nfinal class Bar\n{\n    public function run(int \$n): int\n    {\n        if (\$n > 0) { return \$n; }\n        if (\$n < 0) { return -\$n; }\n        return 0;\n    }\n}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base bar', '--no-gpg-sign']);

        // "Refactor" that adds >15 lines (comments incl. the edge_case keyword 'null'/'fallback')
        // WITHOUT reducing the worst-method cyclomatic — re-measure must reject it.
        $pad = '';
        for ($i = 0; $i < 18; $i++) {
            $pad .= "        // padding line {$i}: null fallback note for documentation\n";
        }
        file_put_contents($d.'/app/Services/Bar.php', "<?php\nnamespace App\\Services;\nfinal class Bar\n{\n    public function run(int \$n): int\n    {\n{$pad}        if (\$n > 0) { return \$n; }\n        if (\$n < 0) { return -\$n; }\n        return 0;\n    }\n}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'refactor: reduce complexity of Bar', '--no-gpg-sign']);

        return $this->git($d, ['rev-parse', 'HEAD']);
    }

    private function seedRefactorMerge(string $hash, string $commit, string $target, string $objective): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'grade9', 'config' => [], 'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            AtlasLoopProposal::create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => $objective,
                'target_path' => $target,
                'diff_text' => '',
                'proposal_hash' => $hash,
                'merged_to_main' => true,
                'reviewed_at' => now(),
                'quality' => [
                    '_canary' => ['ran' => true, 'passed' => true, 'target' => 'sib'],
                    '_impact_receipt' => ['schema_version' => 'atlas.loop.impact_receipt.v1', 'commit' => $commit],
                ],
            ]);
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }
    }

    public function test_window_of_proven_refactor_hub_merges_computes_grade_at_least_9(): void
    {
        [$repo, $drop] = $this->repoWithProvenDrop();
        for ($i = 0; $i < 8; $i++) {
            $this->seedRefactorMerge('g9-'.$i, $drop, 'app/Services/Foo.php', 'Refactor Foo.php to substantially REDUCE complexity while preserving behavior');
        }

        $grade = (new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService($repo), $repo))->grade(50);

        $this->assertSame(8, $grade['graded_merges']);
        $this->assertGreaterThanOrEqual(0.99, $grade['axes']['non_trivial'], 'every proven-refactor merge is credited via the re-measure');
        $this->assertSame(8, $grade['evidence']['re_measured_refactor_merges']);
        $this->assertGreaterThanOrEqual(0.99, $grade['axes']['wired']);
        $this->assertGreaterThanOrEqual(0.99, $grade['axes']['compounding'], '>=3 real callers => hub');
        $this->assertGreaterThanOrEqual(9.0, $grade['grade'], 'proven hub-refactor window computes >=9 by construction: '.json_encode($grade['axes']));
    }

    public function test_fake_refactor_without_real_drop_is_not_credited_even_with_keywords(): void
    {
        [$repo, $drop] = $this->repoWithProvenDrop();
        $noDrop = $this->addFakeRefactor($repo);

        // Direct proof: the re-measure rejects the non-reducing "refactor".
        $svc = new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService($repo), $repo);
        $m = new \ReflectionMethod($svc, 'reMeasuredComplexityDrop');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($svc, $drop, 'app/Services/Foo.php') === true, 'the genuine drop is true');
        $this->assertFalse($m->invoke($svc, $noDrop, 'app/Services/Bar.php') === true, 'a non-reducing refactor (diff mentions null/fallback) is NOT a drop');

        // End-to-end: a window of only fake refactors => NON_TRIVIAL stays 0 (keyword cannot launder).
        $this->seedRefactorMerge('fake-1', $noDrop, 'app/Services/Bar.php', 'Refactor Bar.php to substantially REDUCE complexity');
        $grade = $svc->grade(50);
        $this->assertSame(0, $grade['evidence']['re_measured_refactor_merges'], 'no real drop => not re-measured-credited');
        $this->assertSame(0, $grade['evidence']['non_trivial_merges'], 'a fake refactor with edge_case keywords is NOT counted non_trivial');
    }

    public function test_genuine_correctness_merge_still_counts_via_category_path(): void
    {
        // A non-refactor correctness merge still earns NON_TRIVIAL via the existing category path
        // (regression guard for the OR->AND change).
        [$repo, $drop] = $this->repoWithProvenDrop();
        $this->seedRefactorMerge('bug-1', $drop, 'app/Services/Foo.php', 'Fix a null-pointer bug in Foo that caused an error on empty input');

        $grade = (new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService($repo), $repo))->grade(50);

        // objective is a bug fix (not a refactor objective) on a >15-line commit with a green
        // canary => counted via the {bug,edge_case,perf} keyword door.
        $this->assertSame(1, $grade['evidence']['non_trivial_merges']);
        $this->assertSame(0, $grade['evidence']['re_measured_refactor_merges'], 'a bug objective is not routed through the refactor re-measure');
    }
}
