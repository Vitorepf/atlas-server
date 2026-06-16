<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 3 — OBRA NET-DIFF FULL CERTIFICATION.
 *
 * The aggregate-drop measure proves complexity fell; it does NOT run the single-target anti-gaming stack
 * (behavioral-equivalence floor, overfit probe, diff-earned, mutation, completeness, cross-file consumer
 * contracts) on the ASSEMBLED multi-file diff. certifyObraNetDiff() routes the obra net diff (base_head..
 * branch, replayed into a base_head worktree as an unstaged dirty tree) through the FULL certify() against
 * the obra's HUMAN-FROZEN acceptance. Because certify() re-runs the WHOLE frozen command set, a node that
 * silently breaks a SIBLING's contract turns the assembled net diff RED — the defining large-obra failure
 * mode (independently-green steps that conflict once assembled) becomes provable end-to-end.
 *
 * Driven directly via a thin protected->public shim against a REAL git obra branch.
 */
final class AtlasLoopObraNetDiffCertificationTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the cert's optional gates to deterministic defaults so this frozen test proves the ROUTING
        // (the obra net diff flows through certify() and its ALWAYS-ON gates — honesty/diff-earned + the
        // overfit probe) independent of whatever the ambient .env happens to arm. The mutation/quality/
        // completeness gates have their OWN frozen tests; here we keep the overfit probe ON (the gate this
        // file asserts) and the always-on honesty gate, and pin the rest OFF for determinism.
        config()->set('atlas.loop.mutation_adequacy_gate.enabled', false);
        config()->set('atlas.loop.mutation_kill_ratio_floor', 0.0);
        config()->set('atlas.loop.quality_bar_gate_enabled', false);
        config()->set('atlas.loop.delivery_bar.armed', false); // the FEATURE-lane quality bar gates on this
        config()->set('atlas.loop.completeness_gate_enabled', false);
        config()->set('atlas.loop.confidence_gate_enabled', false);
        config()->set('atlas.loop.judge_consensus_gate_enabled', false);
        config()->set('atlas.loop.cross_file_consumer_gate.enabled', false);
        config()->set('atlas.loop.overfit_probe_enabled', true);
    }

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

    /** The frozen contract test: add(2,3) must equal 5 AND the greeter must render the frozen "hi:5" shape. */
    private function contractTest(): string
    {
        return "<?php\n"
            ."require __DIR__.'/../src/Calc.php';\n"
            ."require __DIR__.'/../src/Greeter.php';\n"
            ."if (atlas_add(2, 3) !== 5) { fwrite(STDERR, 'add'); exit(1); }\n"
            ."if (atlas_greet() !== 'hi:5') { fwrite(STDERR, 'greet'); exit(1); }\n"
            ."echo 'ok';\n";
    }

    /**
     * Build a repo with a committed baseline that FAILS the contract (so the obra diff must EARN its green)
     * and an obra branch whose net diff sets Calc + Greeter to the given bodies.
     *
     * @return array{branch:string, base_head:string}
     */
    private function buildObraBranch(string $calcBody, string $greeterBody): array
    {
        $this->repo = sys_get_temp_dir().'/atlas-netcert-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/src', 0o755, true);
        @mkdir($this->repo.'/tests', 0o755, true);

        // Baseline: a STUB add (returns 0) + a placeholder greeter => the contract test is RED at base_head.
        file_put_contents($this->repo.'/src/Calc.php', "<?php\nfunction atlas_add(int \$a, int \$b): int { return 0; }\n");
        file_put_contents($this->repo.'/src/Greeter.php', "<?php\nfunction atlas_greet(): string { return 'placeholder'; }\n");
        file_put_contents($this->repo.'/tests/contract.php', $this->contractTest());
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
        $baseHead = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $branch = 'atlas/obra/netcert-test';
        $this->git(['git', 'checkout', '-q', '-b', $branch]);
        // node 1 — make add real; node 2 — the greeter consumes add (cross-node edge), body per the scenario.
        file_put_contents($this->repo.'/src/Calc.php', "<?php\nfunction atlas_add(int \$a, int \$b): int { {$calcBody} }\n");
        file_put_contents($this->repo.'/src/Greeter.php', "<?php\nrequire_once __DIR__.'/Calc.php';\nfunction atlas_greet(): string { return {$greeterBody}; }\n");
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'obra']);
        // Leave the working tree on baseline so the replay worktree-add from base_head is clean.
        $this->git(['git', 'checkout', '-q', $baseHead]);

        return ['branch' => $branch, 'base_head' => $baseHead];
    }

    private function acceptance(): array
    {
        return [
            'commands' => ['php tests/contract.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 120,
        ];
    }

    private function envelope(array $branch): array
    {
        return ['branch' => $branch['branch'], 'executor_receipt' => ['base_head' => $branch['base_head']]];
    }

    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            /** @return array{certified:bool, reason:?string, receipt:array<string,mixed>|null} */
            public function netCert(string $repoRoot, array $envelope, array $allowed, array $acceptance, string $objective): array
            {
                return $this->certifyObraNetDiff($repoRoot, $envelope, $allowed, $acceptance, $objective);
            }
        };
    }

    public function test_a_genuine_multi_file_obra_net_diff_certifies_against_the_frozen_acceptance(): void
    {
        // CONSISTENT: add is real (5) and the greeter renders the frozen "hi:5" — the assembled diff is GREEN
        // and the green is diff-EARNED (reverting it returns to the RED stub baseline).
        $branch = $this->buildObraBranch('return $a + $b;', "'hi:'.atlas_add(2, 3)");

        $out = $this->adapter()->netCert($this->repo, $this->envelope($branch), ['src/Calc.php', 'src/Greeter.php'], $this->acceptance(), 'Extract add + wire the greeter');

        $this->assertTrue($out['certified'], 'a genuine multi-file obra with a real frozen suite certifies. reason='.(string) ($out['reason'] ?? ''));
        $this->assertNull($out['reason']);
    }

    public function test_a_node_that_breaks_a_sibling_contract_refuses_the_assembled_net_diff(): void
    {
        // node 1 (Calc) is perfectly fine — add(2,3) === 5 — but node 2 (Greeter) silently breaks the frozen
        // "hi:" contract shape ("bye:5"). The piece-wise-plausible obra is REFUSED because the ASSEMBLED net
        // diff fails the human-frozen acceptance: exactly the gaming the per-node gates miss.
        $branch = $this->buildObraBranch('return $a + $b;', "'bye:'.atlas_add(2, 3)");

        $out = $this->adapter()->netCert($this->repo, $this->envelope($branch), ['src/Calc.php', 'src/Greeter.php'], $this->acceptance(), 'Extract add + wire the greeter');

        $this->assertFalse($out['certified'], 'a cross-node contract break must refuse the assembled obra');
        $this->assertNotNull($out['reason']);
    }

    public function test_an_overfit_short_circuit_in_the_net_diff_is_refused(): void
    {
        // The contract still passes for the real input, but the net diff smuggles a literal short-circuit —
        // the deterministic overfit probe (default ON) scans the added lines and refuses regardless.
        $branch = $this->buildObraBranch('if (func_num_args() === 1) { return 5; } return $a + $b;', "'hi:'.atlas_add(2, 3)");

        $out = $this->adapter()->netCert($this->repo, $this->envelope($branch), ['src/Calc.php', 'src/Greeter.php'], $this->acceptance(), 'Extract add + wire the greeter');

        $this->assertFalse($out['certified'], 'an overfit short-circuit in the assembled net diff must refuse');
        $this->assertStringContainsString('overfit', (string) ($out['reason'] ?? ''), 'refused by the overfit probe specifically');
    }

    public function test_empty_acceptance_fails_open_and_never_false_rejects(): void
    {
        // No human-frozen acceptance => nothing to certify the assembled diff against => fail-OPEN (the
        // aggregate-drop + per-node gates already passed). Narrows coverage, never refuses a real obra.
        $branch = $this->buildObraBranch('return $a + $b;', "'hi:'.atlas_add(2, 3)");

        $out = $this->adapter()->netCert($this->repo, $this->envelope($branch), ['src/Calc.php'], [], 'x');

        $this->assertTrue($out['certified'], 'empty acceptance fails open (no false-reject)');
        $this->assertSame('no_obra_acceptance', $out['reason']);
    }
}
