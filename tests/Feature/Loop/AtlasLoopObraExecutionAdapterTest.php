<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\FixtureRefactorObraNodeDelivery;
use App\Services\Ai\Obra\AtlasObraExecutor;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OPTION-3 slice-1 (machinery, by-construction, ZERO spend) — the adapter wires the obra EXECUTOR
 * into the loop. A deterministic fixture delivery drives the executor end-to-end across a real
 * multi-node plan on an ISOLATED worktree (apply → per-node gate → integrated check → close), the
 * obra genuinely CERTIFIES, main stays byte-identical — AND the L4-10 gate REJECTS the fixture
 * (sealed execution_mode='fixture_obra_run'), so a fixture proves the machinery but can NEVER park as
 * real work. Only a real provider run earns a parkable L4-10 (slice-2, empirical).
 */
final class AtlasLoopObraExecutionAdapterTest extends TestCase
{
    private string $repo = '';

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->repo, null, null, 30.0))->run();
    }

    /** A class whose single method has cyclomatic ≈ $ifs + 1. */
    private function klass(string $class, int $ifs): string
    {
        $body = "    public function run(int \$v): string {\n";
        for ($i = 0; $i < $ifs; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return "<?php\nnamespace App;\nfinal class {$class} {\n{$body}\n}\n";
    }

    /** A 2-file committed cluster repo. */
    private function buildRepo(): void
    {
        $this->repo = sys_get_temp_dir().'/atlas-obra-exec-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/app', 0o755, true);
        file_put_contents($this->repo.'/app/HubA.php', $this->klass('HubA', 12));
        file_put_contents($this->repo.'/app/HubB.php', $this->klass('HubB', 8));
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
    }

    private function payload(): array
    {
        return [
            'objective' => 'Reduce the cyclomatic complexity of the HubA/HubB cluster, preserving behaviour.',
            'allowed_files' => ['app/HubA.php', 'app/HubB.php'],
            'target_relative_path' => 'app/HubA.php',
            'target_repo_path' => $this->repo,
            'cluster_hash' => 'cluster-exec-'.bin2hex(random_bytes(3)),
            // The integrated behaviour gate — a trivially-passing command (the fixture path proves the
            // executor MACHINERY, not the provider's correctness; behaviour is checked here generically).
            'acceptance' => ['commands' => ['php -r "exit(0);"']],
        ];
    }

    /** The fixture's pre-baked simpler versions of each allowed file (genuine cyclomatic drop). */
    private function fixture(): FixtureRefactorObraNodeDelivery
    {
        return new FixtureRefactorObraNodeDelivery([
            'app/HubA.php' => $this->klass('HubA', 3),
            'app/HubB.php' => $this->klass('HubB', 2),
        ]);
    }

    public function test_fixture_drives_the_executor_end_to_end_but_is_rejected_as_a_real_l4_10(): void
    {
        $this->buildRepo();
        $headBefore = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve($this->payload(), $this->fixture());

        // The MACHINERY ran: the executor delivered both nodes, certified the assembled obra, main untouched.
        $env = $result['envelope'] ?? [];
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $env['status'] ?? null, json_encode($env['reason'] ?? $result['reason']));
        $this->assertTrue((bool) ($env['certified'] ?? false), 'the assembled multi-node obra certified');
        $this->assertSame(2, (int) ($env['delivered_nodes'] ?? 0), 'both cluster files delivered as nodes');
        $this->assertTrue((bool) ($env['main_untouched'] ?? false), 'main is byte-identical (isolated worktree)');

        // But the L4-10 gate REJECTS the fixture — a deterministic stub can never park as real work.
        $this->assertFalse((bool) $result['ok'], 'a fixture run never produces a parkable real L4-10');
        $this->assertStringContainsString('l4_10_not_real', (string) $result['reason']);
        $this->assertNull($result['l4_10_evidence_path'], 'the rejected evidence file is cleaned up');

        // Main HEAD is exactly where it was; the obra branch was discarded.
        $headAfter = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());
        $this->assertSame($headBefore, $headAfter, 'main HEAD unchanged');
        $branches = (new Process(['git', 'branch', '--list', 'atlas/obra/*'], $this->repo))->mustRun()->getOutput();
        $this->assertSame('', trim($branches), 'the obra branch was discarded on the honest no-park');
    }

    public function test_single_file_payload_is_refused_not_multi_file(): void
    {
        $this->buildRepo();
        $p = $this->payload();
        $p['allowed_files'] = ['app/HubA.php'];
        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve($p, $this->fixture());
        $this->assertFalse((bool) $result['ok']);
        $this->assertSame('not_multi_file', $result['reason']);
    }

    public function test_forbidden_self_target_in_cluster_is_refused_before_execution(): void
    {
        $this->buildRepo();
        $p = $this->payload();
        $p['allowed_files'] = ['app/HubA.php', 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php'];
        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve($p, $this->fixture());
        $this->assertFalse((bool) $result['ok']);
        $this->assertStringContainsString('forbidden_self_target', (string) $result['reason']);
    }
}
