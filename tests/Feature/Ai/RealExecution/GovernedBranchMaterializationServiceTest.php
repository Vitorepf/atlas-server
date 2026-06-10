<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Materialization frontier — locks the SOVEREIGNTY INVARIANTS of the
 * "Atlas delivers a ready-to-merge branch, the OPERATOR merges" capability:
 * it produces a real branch, NEVER touches main (HEAD + working tree
 * byte-identical), is gated (certified + receipt), and fails safe (no branch
 * residue on a non-applying diff). Runs against a private throwaway git repo.
 */
final class GovernedBranchMaterializationServiceTest extends TestCase
{
    private string $repo = '';

    private string $headBefore = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-mat-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->g(['init', '-q']);
        $this->g(['add', '-A']);
        $this->g(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $this->headBefore = trim($this->gOut(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        parent::tearDown();
    }

    public function test_materializes_certified_diff_to_a_real_branch_without_touching_main(): void
    {
        $statusBefore = $this->gOut(['status', '--porcelain']);

        $r = (new GovernedBranchMaterializationService)->materialize([
            'id' => 't1',
            'diff_text' => $this->newFileDiff(),
            'repo_dir' => $this->repo,
            'certified' => true,
            'gate_receipt' => str_repeat('a', 64),
            'measure_cmd' => 'true',
        ]);

        $this->assertTrue($r['materialized'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('atlas/materialize/t1', $r['branch']);
        $this->assertContains('added.txt', $r['files_changed']);
        $this->assertTrue($r['main_untouched']);
        $this->assertTrue($r['never_merged']);
        $this->assertTrue($r['never_pushed']);
        $this->assertTrue(($r['measure']['passed'] ?? false));

        // The branch exists + carries the change.
        $this->assertTrue($this->branchExists('atlas/materialize/t1'));
        // MAIN untouched: HEAD + working tree byte-identical; the new file is NOT on main.
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
        $this->assertSame($statusBefore, $this->gOut(['status', '--porcelain']));
        $this->assertFileDoesNotExist($this->repo.'/added.txt');
        // Reversible: the branch can be deleted with no trace.
        $this->g(['branch', '-D', 'atlas/materialize/t1']);
        $this->assertFalse($this->branchExists('atlas/materialize/t1'));
    }

    public function test_blocks_without_certification_or_gate_receipt(): void
    {
        $a = (new GovernedBranchMaterializationService)->materialize([
            'id' => 't2', 'diff_text' => $this->newFileDiff(), 'repo_dir' => $this->repo,
            'certified' => false, 'gate_receipt' => str_repeat('a', 64),
        ]);
        $this->assertFalse($a['materialized']);
        $this->assertSame('not_certified', $a['reason']);

        $b = (new GovernedBranchMaterializationService)->materialize([
            'id' => 't3', 'diff_text' => $this->newFileDiff(), 'repo_dir' => $this->repo,
            'certified' => true, 'gate_receipt' => 'short',
        ]);
        $this->assertFalse($b['materialized']);
        $this->assertSame('gate_receipt_required', $b['reason']);
        $this->assertFalse($this->branchExists('atlas/materialize/t2'));
    }

    public function test_non_applying_diff_fails_safe_with_no_branch_residue(): void
    {
        $bad = "diff --git a/missing.txt b/missing.txt\n--- a/missing.txt\n+++ b/missing.txt\n@@ -1 +1 @@\n-nonexistent\n+changed\n";
        $r = (new GovernedBranchMaterializationService)->materialize([
            'id' => 't4', 'diff_text' => $bad, 'repo_dir' => $this->repo,
            'certified' => true, 'gate_receipt' => str_repeat('a', 64),
        ]);
        $this->assertFalse($r['materialized']);
        $this->assertSame('git_apply_failed', $r['reason']);
        // Clean failure: no partial branch left, main HEAD unchanged.
        $this->assertFalse($this->branchExists('atlas/materialize/t4'));
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    public function test_discard_branch_deletes_a_materialize_branch_without_touching_main(): void
    {
        // Materialize a real branch, then discard it (the S3.F1 off-target-reject path).
        $svc = new GovernedBranchMaterializationService;
        $svc->materialize([
            'id' => 'discardme', 'diff_text' => $this->newFileDiff(), 'repo_dir' => $this->repo,
            'certified' => true, 'gate_receipt' => str_repeat('a', 64),
        ]);
        $this->assertTrue($this->branchExists('atlas/materialize/discardme'));

        $r = $svc->discardBranch($this->repo, 'atlas/materialize/discardme');

        $this->assertTrue($r['discarded']);
        $this->assertFalse($this->branchExists('atlas/materialize/discardme'), 'the branch must be gone');
        // Main untouched + idempotent (discarding again is a no-op success).
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
        $again = $svc->discardBranch($this->repo, 'atlas/materialize/discardme');
        $this->assertTrue($again['discarded']);
        $this->assertSame('already_absent', $again['reason']);
    }

    public function test_discard_branch_refuses_anything_outside_the_materialize_namespace(): void
    {
        $svc = new GovernedBranchMaterializationService;
        // The current branch (main/master) must NEVER be deletable through this path.
        $current = trim($this->gOut(['rev-parse', '--abbrev-ref', 'HEAD']));

        $r = $svc->discardBranch($this->repo, $current);

        $this->assertFalse($r['discarded']);
        $this->assertSame('refused_non_materialize_branch', $r['reason']);
        // The protected branch still exists, main HEAD unchanged.
        $this->assertTrue($this->branchExists($current));
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    private function newFileDiff(): string
    {
        return "diff --git a/added.txt b/added.txt\nnew file mode 100644\n--- /dev/null\n+++ b/added.txt\n@@ -0,0 +1 @@\n+materialized line\n";
    }

    private function branchExists(string $branch): bool
    {
        $p = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], $this->repo);
        $p->run();

        return $p->isSuccessful();
    }

    /** @param list<string> $argv */
    private function g(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }

    /** @param list<string> $argv */
    private function gOut(array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $this->repo);
        $p->run();

        return $p->getOutput();
    }
}
