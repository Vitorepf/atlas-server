<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopCertChainClosure;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4.5 · §3.6(i) — the candidate-bytes proof. The frozen probe scores the WORKTREE's
 * actual bytes via the cert-chain closure Merkle: a probe expecting the candidate root accepts the edited
 * worktree (exit 0), and a probe expecting the LIVE root rejects it (exit 66) — so the §3.4 R3 "the symlink
 * loaded the live judge" trap is caught loudly, never silently green.
 */
final class AtlasLoopCertProbeTest extends TestCase
{
    private string $worktree = '';

    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = base_path('app/Services/Ai/AutonomousEvolution/Constitution/probe/atlas-loop-cert-probe.php');
    }

    protected function tearDown(): void
    {
        if ($this->worktree !== '' && is_dir($this->worktree)) {
            (new Process(['git', 'worktree', 'remove', '--force', $this->worktree], base_path()))->run();
            (new Process(['git', 'worktree', 'prune'], base_path()))->run();
        }
        parent::tearDown();
    }

    private function runProbe(string $worktree, string $expectedMerkle): int
    {
        $p = new Process([PHP_BINARY, $this->probe, $worktree, $expectedMerkle], base_path(), null, null, 120.0);
        $p->run();

        return (int) $p->getExitCode();
    }

    public function test_probe_confirms_candidate_bytes_and_rejects_the_live_tree(): void
    {
        $closure = new AtlasLoopCertChainClosure();
        $liveMerkle = $closure->merkleRoot(base_path());

        // A real detached worktree off HEAD — carries every committed closure file.
        $this->worktree = sys_get_temp_dir().'/atlas-probe-wt-'.bin2hex(random_bytes(4));
        $add = new Process(['git', 'worktree', 'add', '--detach', $this->worktree, 'HEAD'], base_path(), null, null, 120.0);
        $add->run();
        $this->assertTrue($add->isSuccessful(), 'worktree add: '.$add->getErrorOutput());

        // Inject a unique marker into ONE closure file in the worktree (simulating a candidate edit).
        $target = $this->worktree.'/app/Services/Ai/AutonomousEvolution/AtlasLoopQualityGrader.php';
        $this->assertFileExists($target);
        File::append($target, "\n// atlas-candidate-marker-".bin2hex(random_bytes(4))."\n");

        // The candidate root is computed over the WORKTREE's edited bytes — it must differ from live.
        $candidateMerkle = $closure->merkleRoot($this->worktree);
        $this->assertNotSame($liveMerkle, $candidateMerkle, 'the marker changed a closure file ⇒ the root moved');

        // Probe expecting the CANDIDATE root accepts the edited worktree (it really hashed those bytes).
        $this->assertSame(0, $this->runProbe($this->worktree, $candidateMerkle), 'candidate bytes proven ⇒ PASS');

        // Probe expecting the LIVE root REJECTS the edited worktree (bytes_not_proven) — the live judge can
        // never masquerade as the candidate.
        $this->assertSame(66, $this->runProbe($this->worktree, $liveMerkle), 'scored tree ≠ live ⇒ exit 66');
    }

    public function test_probe_on_a_non_worktree_is_bytes_not_proven(): void
    {
        $this->assertSame(66, $this->runProbe(sys_get_temp_dir().'/atlas-nope-'.bin2hex(random_bytes(4)), ''));
    }
}
