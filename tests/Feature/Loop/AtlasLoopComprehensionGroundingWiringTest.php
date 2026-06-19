<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Wiring proof for the COMPREHENSION GROUNDING GATE keep-conjunct in
 * {@see AtlasLoopTaskGrinder::gateImplementationProposals()}.
 *
 * A proposal whose semantic certifier verdict is certified=true but whose declared (allowed) files
 * cite a symbol that resolves NOWHERE in the repo is treated as hallucinated comprehension and DROPPED.
 * The gate is FAIL-OPEN, so a proposal whose cited file is REAL is KEPT, and the flag OFF restores the
 * pre-wire keep decision (byte-identical).
 *
 * The certification here is GENUINE — not a stub: a real non-git base workspace + a real proposal diff
 * that earns its green (the command is RED once the diff is reverted), so the semantic certifier really
 * returns certified=true. The ONLY thing that varies between Case A and Case B is the cited file's
 * existence, which is exactly the lever the grounding conjunct keys off.
 */
final class AtlasLoopComprehensionGroundingWiringTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the cert pipeline on its deterministic defaults; only the grounding flag is under test.
        config(['atlas.loop.comprehension_grounding_gate_enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    /**
     * A self-contained NON-git base workspace (the discovery cp -R shape) carrying a single source file
     * whose body the proposal diff will flip from `return 1` to `return 2`.
     */
    private function base(): string
    {
        $base = sys_get_temp_dir().'/atlas-comp-base-'.bin2hex(random_bytes(4));
        $this->dirs[] = $base;
        File::ensureDirectoryExists($base.'/src');
        File::put($base.'/src/Calc.php', "<?php\nfunction calc_value() { return 1; }\n");
        // A FROZEN acceptance script (referenced by the command, so the judge freezes it). It exits 0
        // only when the SOURCE returns 2 — so the proposal's diff EARNS its green (reverting the diff
        // restores `return 1` => the frozen script exits non-zero => diff_earned passes).
        File::put($base.'/verify.php', "<?php\nrequire __DIR__.'/src/Calc.php';\nexit(calc_value() === 2 ? 0 : 1);\n");

        return $base;
    }

    /** A REAL git-generated diff that flips return 1 -> return 2 against the base layout. */
    private function realDiff(string $base): string
    {
        $tmp = sys_get_temp_dir().'/atlas-comp-diff-'.bin2hex(random_bytes(4));
        $this->dirs[] = $tmp;
        (new Process(['cp', '-R', $base, $tmp]))->run();
        foreach ([
            ['git', '-C', $tmp, 'init', '-q'],
            ['git', '-C', $tmp, 'add', '-A'],
            ['git', '-C', $tmp, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'b'],
        ] as $cmd) {
            (new Process($cmd))->run();
        }
        File::put($tmp.'/src/Calc.php', "<?php\nfunction calc_value() { return 2; }\n");
        $p = new Process(['git', '-C', $tmp, 'diff']);
        $p->run();

        return $p->getOutput();
    }

    /**
     * Drive the real gate. $extraCitations are appended to allowed_files ALONGSIDE the genuinely-changed
     * `src/Calc.php` (which the certifier's declared-scope check requires) — so the certification verdict is
     * held FIXED (certified=true) across cases and the ONLY thing that moves the keep decision is whether the
     * EXTRA cited file resolves in the repo (the grounding lever).
     *
     * @param  list<string>  $extraCitations
     * @return array<string,mixed>
     */
    private function runGate(string $base, string $diff, array $extraCitations): array
    {
        $allowedFiles = array_merge(['src/Calc.php'], $extraCitations);
        $explorerTask = [
            'objective' => 'flip calc_value to 2',
            'base_workspace' => $base,
            'acceptance' => [
                // The command references the FROZEN verify.php (not the changed source), so the judge's
                // tamper guard is satisfied; it earns its green on revert_recheck (forced by the gate).
                'commands' => ['php verify.php'],
                'allowed_globs' => ['src/Calc.php'],
                'frozen_globs' => ['verify.php'],
                'metric_kind' => 'gate',
            ],
            'allowed_files' => $allowedFiles,
        ];
        $result = ['proposals' => [[
            'proposal_hash' => 'h1',
            'objective' => 'flip calc_value to 2',
            'diff_text' => $diff,
        ]]];
        $payload = ['allowed_files' => $allowedFiles, 'code_graph_workspace' => $base];

        $m = new ReflectionMethod(AtlasLoopTaskGrinder::class, 'gateImplementationProposals');
        $m->setAccessible(true);

        // frameworkTask=false => per-proposal fail-closed (a gate error drops just that proposal),
        // never the whole task — the discovery lane the grounding conjunct guards.
        return (array) $m->invoke(app(AtlasLoopTaskGrinder::class), $result, $explorerTask, $payload, false);
    }

    public function test_certified_proposal_is_kept_when_its_cited_file_is_real(): void
    {
        $base = $this->base();
        $diff = $this->realDiff($base);
        $this->assertNotSame('', trim($diff), 'sanity: real diff generated');

        // The extra cited file is REAL and present in the cited repo root (verify.php under $base), so it
        // resolves via the file-scan oracle. Certified + grounded (src/Calc.php also resolves) => KEPT.
        $result = $this->runGate($base, $diff, ['verify.php']);

        $this->assertCount(1, $result['proposals'], 'a genuinely certified, grounded proposal must survive');
    }

    public function test_certified_proposal_with_a_fabricated_citation_is_dropped(): void
    {
        $base = $this->base();
        $diff = $this->realDiff($base);

        // SAME genuinely-certified proposal, but its declared file cites a symbol that resolves NOWHERE.
        // Grounding refutes the hallucinated comprehension => DROPPED. This is the load-bearing case:
        // with the `&& $grounded` conjunct reverted, the certified proposal would be KEPT (=> RED).
        $result = $this->runGate($base, $diff, ['app/Totally/Fake/Nonexistent.php']);

        $this->assertSame([], $result['proposals'], 'a fabricated citation must drop the certified proposal');
    }

    public function test_flag_off_keeps_the_proposal_even_with_a_fabricated_citation(): void
    {
        config(['atlas.loop.comprehension_grounding_gate_enabled' => false]);
        $base = $this->base();
        $diff = $this->realDiff($base);

        // Flag OFF => grounding is inert => the pre-wire keep decision (certified alone) governs => KEPT.
        $result = $this->runGate($base, $diff, ['app/Totally/Fake/Nonexistent.php']);

        $this->assertCount(1, $result['proposals'], 'flag OFF must be byte-identical to the pre-wire keep');
    }
}
