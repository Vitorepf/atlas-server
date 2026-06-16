<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 1 — PART B frozen ratchet for certifyAggregateDrop()'s STRUCTURAL routing flag.
 *
 * certifyAggregateDrop replays the obra net diff (base_head..branch) into a fresh worktree and
 * re-measures the scoped drop. When the obra introduced a net-new file AND
 * atlas.loop.complexity_method_identity_gate is ON, it routes to measureScopedStructuralDrop
 * (anti-relocation per-identity census) so a create-class + redirect obra is provable. With the flag
 * OFF (default) it routes to measureScopedComplexityDrop EXACTLY as today — whose new-file-lock refuses
 * any net-new file — so the routing is byte-identical-OFF.
 *
 * This drives certifyAggregateDrop directly (exposed via a thin protected->public test shim) against a
 * REAL git obra branch, so the flag's effect on the verdict is pinned in BOTH states.
 */
final class AtlasLoopObraAggregateDropRoutingTest extends TestCase
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
        (new Process($argv, $this->repo, null, null, 30.0))->mustRun();
    }

    private function god(string $ns, string $class, int $ifs): string
    {
        $body = "    public function run(int \$v): string {\n";
        for ($i = 0; $i < $ifs; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return "<?php\nnamespace {$ns};\nfinal class {$class} {\n{$body}\n}\n";
    }

    /**
     * Build a repo with a committed baseline (app/Hub.php god method) and an obra branch whose diff
     * SIMPLIFIES Hub::run in place AND creates a NEW app/Support/HubHelper.php of simpler helpers.
     *
     * @return array{branch:string, base_head:string}
     */
    private function buildObraBranch(): array
    {
        $this->repo = sys_get_temp_dir().'/atlas-aggdrop-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/app/Support', 0o755, true);
        file_put_contents($this->repo.'/app/Hub.php', $this->god('App', 'Hub', 12)); // worst ≈ 13
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
        $baseHead = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $branch = 'atlas/obra/routing-test';
        $this->git(['git', 'checkout', '-q', '-b', $branch]);
        file_put_contents($this->repo.'/app/Hub.php', $this->god('App', 'Hub', 3)); // 13 -> 4 in place
        file_put_contents(
            $this->repo.'/app/Support/HubHelper.php',
            "<?php\nnamespace App\\Support;\nfinal class HubHelper {\n"
            ."    public function stepA(int \$v): string { if (\$v > 0) { return 'a'; } return 'z'; }\n"
            ."    public function stepB(int \$v): string { if (\$v > 1) { return 'b'; } return 'z'; }\n"
            ."}\n",
        );
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'obra']);
        // Leave the working tree on baseline HEAD so the replay worktree-add from base_head is clean.
        $this->git(['git', 'checkout', '-q', $baseHead]);

        return ['branch' => $branch, 'base_head' => $baseHead];
    }

    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            /** @return array{reduced:bool, reason:?string, proof:array<string,mixed>|null} */
            public function routeDrop(string $repoRoot, array $envelope, array $allowed): array
            {
                return $this->certifyAggregateDrop($repoRoot, $envelope, $allowed);
            }
        };
    }

    private function envelope(array $branch): array
    {
        return [
            'branch' => $branch['branch'],
            'executor_receipt' => ['base_head' => $branch['base_head']],
        ];
    }

    public function test_flag_off_routes_to_default_lane_and_refuses_the_create_class_obra(): void
    {
        config()->set('atlas.loop.complexity_method_identity_gate', false);
        $branch = $this->buildObraBranch();

        $drop = $this->adapter()->routeDrop($this->repo, $this->envelope($branch), ['app/Hub.php']);

        // The default lane's new-file-lock refuses the net-new HubHelper.php — byte-identical to today.
        $this->assertFalse((bool) $drop['reduced'], 'flag OFF => default lane refuses the create-class obra (unchanged)');
        $this->assertNotTrue((bool) ($drop['proof']['structural'] ?? false), 'the default (non-structural) lane was used');
    }

    public function test_flag_on_routes_to_structural_lane_and_certifies_the_create_class_obra(): void
    {
        config()->set('atlas.loop.complexity_method_identity_gate', true);
        $branch = $this->buildObraBranch();

        $drop = $this->adapter()->routeDrop($this->repo, $this->envelope($branch), ['app/Hub.php']);

        // The structural lane folds the new file into scope and certifies the genuine in-place simplify.
        $this->assertTrue((bool) $drop['reduced'], 'flag ON => structural lane certifies the genuine create-class + in-place simplify');
        $this->assertTrue((bool) ($drop['proof']['structural'] ?? false), 'the structural lane was used');
    }
}
