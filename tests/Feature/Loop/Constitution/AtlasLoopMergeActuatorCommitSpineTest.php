<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateToken;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4 — the merge-time commit-spine enforces the PASS-token under the lock: a token
 * bound to the post-apply tree COMMITS; a token bound to a different tree (the tree moved since the gate, or
 * a forged token) does NOT commit. This is what makes a property_gated edit land iff the gate said PASS for
 * this exact tree.
 */
final class AtlasLoopMergeActuatorCommitSpineTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-spine-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        File::put($d.'/f.txt', "a\n");
        foreach ([['init', '-q'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't'], ['add', '-A'], ['commit', '-q', '-m', 'base', '--no-gpg-sign']] as $argv) {
            (new Process(array_merge(['git'], $argv), $d))->run();
        }

        return $d;
    }

    private function git(string $repo, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $repo);
        $p->run();

        return trim($p->getOutput());
    }

    public function test_a_token_bound_to_the_post_apply_tree_commits(): void
    {
        $repo = $this->repo();
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);

        File::put($repo.'/f.txt', "b\n");                 // the candidate change
        $this->git($repo, ['add', '--', 'f.txt']);
        $candidateTree = $this->git($repo, ['write-tree']); // the tree the gate judged

        $token = (new AtlasLoopConstitutionGateToken())->mint($candidateTree, 'battery-1', 'PASS', 'nonce-1');
        $res = (new AtlasLoopMergeActuator())->commitWithConstitutionToken($repo, ['f.txt'], 'constitution edit', $token, 'battery-1', 'nonce-1');

        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertSame('constitution_token_verified', $res['reason']);
        $this->assertNotSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']), 'exactly one new commit landed');
    }

    public function test_a_token_bound_to_a_different_tree_does_not_commit(): void
    {
        $repo = $this->repo();
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);

        File::put($repo.'/f.txt', "c\n");
        // A token minted for a DIFFERENT tree (the tree moved since the gate, or a forged bind).
        $token = (new AtlasLoopConstitutionGateToken())->mint('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef', 'battery-1', 'PASS', 'nonce-1');

        $res = (new AtlasLoopMergeActuator())->commitWithConstitutionToken($repo, ['f.txt'], 'rigged', $token, 'battery-1', 'nonce-1');

        $this->assertFalse($res['committed']);
        $this->assertStringContainsString('constitution_token_invalid', $res['reason']);
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']), 'no commit object landed');
    }

    public function test_a_replayed_nonce_does_not_commit(): void
    {
        $repo = $this->repo();
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);

        File::put($repo.'/f.txt', "d\n");
        $this->git($repo, ['add', '--', 'f.txt']);
        $tree = $this->git($repo, ['write-tree']);
        $token = (new AtlasLoopConstitutionGateToken())->mint($tree, 'battery-1', 'PASS', 'nonce-1');

        // nonce-1 already consumed ⇒ replay ⇒ no commit.
        $res = (new AtlasLoopMergeActuator())->commitWithConstitutionToken($repo, ['f.txt'], 'replay', $token, 'battery-1', 'nonce-1', ['nonce-1']);
        $this->assertFalse($res['committed']);
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']));
    }
}
