<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalMaterializer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The materializer closes the loop-materialization gap WITHOUT crossing the
 * never-merge sovereignty line: it applies a certified diff to an isolated
 * throwaway workspace, never the source tree, and reports merge_to_source=false.
 */
class AtlasLoopProposalMaterializerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach (array_filter($this->dirs) as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function tmpDir(string $tag): string
    {
        $d = sys_get_temp_dir().'/atlas-mat-test-'.$tag.'-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;

        return $d;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }

    private function makeDiff(string $file, string $original, string $modified): string
    {
        $repo = $this->tmpDir('diffgen');
        file_put_contents($repo.'/'.$file, $original);
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        file_put_contents($repo.'/'.$file, $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    private function proposal(string $target, string $diff, string $status = AtlasLoopProposal::STATUS_CERTIFIED): AtlasLoopProposal
    {
        return (new AtlasLoopProposal())->forceFill([
            'status' => $status,
            'target_path' => $target,
            'diff_text' => $diff,
        ]);
    }

    public function test_materializes_certified_diff_into_isolated_workspace_without_touching_base(): void
    {
        $original = "<?php\n\nreturn 1;\n";
        $modified = "<?php\n\nreturn 2;\n";
        $diff = $this->makeDiff('snippet.php', $original, $modified);
        $this->assertNotSame('', trim($diff), 'precondition: a real git diff was generated');

        $base = $this->tmpDir('base');
        file_put_contents($base.'/snippet.php', $original);

        $r = app(AtlasLoopProposalMaterializer::class)->materialize($this->proposal('snippet.php', $diff), $base);
        $this->dirs[] = (string) $r['isolated_path'];

        $this->assertTrue($r['materialized'], 'reason: '.(string) $r['reason']);
        $this->assertTrue($r['applied']);
        $this->assertTrue($r['never_merged']);
        $this->assertFalse($r['merge_to_source']);
        // The diff applied in the ISOLATED workspace...
        $this->assertSame($modified, file_get_contents($r['isolated_path'].'/snippet.php'));
        // ...and the source base is UNTOUCHED (never merged).
        $this->assertSame($original, file_get_contents($base.'/snippet.php'));
    }

    public function test_refuses_an_uncertified_proposal(): void
    {
        $base = $this->tmpDir('base');
        file_put_contents($base.'/snippet.php', "<?php\nreturn 1;\n");

        $r = app(AtlasLoopProposalMaterializer::class)->materialize($this->proposal('snippet.php', 'x', 'draft'), $base);

        $this->assertFalse($r['materialized']);
        $this->assertSame('proposal_not_certified_for_review', $r['reason']);
        $this->assertFalse($r['merge_to_source']);
    }

    public function test_refuses_when_base_target_is_missing(): void
    {
        $r = app(AtlasLoopProposalMaterializer::class)->materialize(
            $this->proposal('ghost.php', 'diff --git a/ghost.php b/ghost.php'),
            $this->tmpDir('base'),
        );

        $this->assertFalse($r['materialized']);
        $this->assertSame('base_target_not_found', $r['reason']);
    }

    /**
     * The real value of the Arbor-ported apply ladder: a certified diff built against
     * an OLDER base, where main has since drifted (a context line changed). Strict
     * `git apply` rejects it — that is the ~83% drift waste the loop discards today.
     * The ladder lands it intact (fuzz/low-context/salvage) and the intact guard keeps
     * it honest (a garbage apply would fail `php -l` and not be accepted).
     */
    public function test_ladder_lands_a_drifted_diff_that_strict_apply_rejects(): void
    {
        $diffBase = "<?php\n\nclass Foo\n{\n    // value accessor\n    public function value(): int\n    {\n        return 1;\n    }\n}\n";
        $modified = "<?php\n\nclass Foo\n{\n    // value accessor\n    public function value(): int\n    {\n        return 2;\n    }\n}\n";
        $diff = $this->makeDiff('Foo.php', $diffBase, $modified);
        $this->assertNotSame('', trim($diff), 'precondition: a real git diff was generated');

        // current main drifted: the context comment changed since the diff was built.
        $currentBase = "<?php\n\nclass Foo\n{\n    // value accessor (cached)\n    public function value(): int\n    {\n        return 1;\n    }\n}\n";

        // precondition: strict `git apply` REJECTS the drifted diff.
        $probe = $this->tmpDir('probe');
        file_put_contents($probe.'/Foo.php', $currentBase);
        $this->git($probe, ['init', '-q']);
        $this->git($probe, ['add', '-A']);
        $this->git($probe, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'b', '--no-gpg-sign']);
        file_put_contents($probe.'/p.patch', $diff);
        $strict = new Process(['git', 'apply', '--whitespace=nowarn', 'p.patch'], $probe);
        $strict->run();
        $this->assertFalse($strict->isSuccessful(), 'precondition: strict git apply must reject the drifted diff');

        // the materializer's ladder lands it intact.
        $base = $this->tmpDir('base');
        file_put_contents($base.'/Foo.php', $currentBase);
        $r = app(AtlasLoopProposalMaterializer::class)->materialize($this->proposal('Foo.php', $diff), $base);
        $this->dirs[] = (string) $r['isolated_path'];

        $this->assertTrue($r['materialized'], 'ladder must land the drifted diff; reason: '.(string) $r['reason']);
        $this->assertTrue($r['applied']);
        $landed = (string) file_get_contents($r['isolated_path'].'/Foo.php');
        $this->assertStringContainsString('return 2;', $landed, 'the certified change must be present');
        // base UNTOUCHED (never merged).
        $this->assertSame($currentBase, file_get_contents($base.'/Foo.php'));
    }
}
