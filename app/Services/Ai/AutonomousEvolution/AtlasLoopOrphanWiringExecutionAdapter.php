<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredAcceptanceProducer;
use Symfony\Component\Process\Process;

/**
 * §5.6 · ORPHAN-WIRING · the end-to-end executor that turns a comprehension-originated dead capability into a
 * CERTIFIED evolution — the second work type's full pipeline (the dedup lane is the first).
 *
 * The brain's scope model surfaces an orphan (a real, tested capability with ZERO production callers — e.g.
 * AtlasLoopResearchOriginator). This adapter executes the wiring of that orphan as a governed evolution:
 *
 *   1. the ENGINE authors a test asserting the orphan's WIRED behavior (on the pre-wiring tree);
 *   2. EARNED-RED + freeze — {@see AtlasLoopWiredAcceptanceProducer} proves the test genuinely FAILS before any
 *      wiring exists (an always-green/self-graded test ⇒ abort), then freezes it into a wired_proof acceptance;
 *   3. the authored test is COMMITTED so it becomes the FROZEN baseline — the cert's diff is then EXACTLY the
 *      wiring, and Guard 4e's git-stash correctly sees the orphan at 0 callers on HEAD;
 *   4. the ENGINE authors the wiring (the only candidate diff);
 *   5. CERTIFY via {@see AtlasEvolutionFrozenJudge} Guard 4e (wiredEarned ∧ orphanMethodKills).
 *
 * Mirrors {@see AtlasLoopObraExecutionAdapter}: the two authoring callables ARE the frontier-engine seam — a
 * real ProviderObraNodeDelivery in production, a fake-but-real authoring double in tests (ZERO provider spend).
 * The cert is AUTHOR-BLIND by design: it certifies the WIRING, not the writer — a genuine load-bearing wiring
 * certifies whoever wrote it; a cosmetic `new Orphan()`+hardcode is rejected by Guard 4e's neutralization
 * conjunct regardless. So the deterministic machinery here is provable end-to-end with a fixture engine, while
 * a real certified flow is still EMPIRICAL (the live engine reliably authoring meaningful wirings is §9,
 * measured over runs — not asserted here). Default-OFF: requires atlas.loop.refactor_wired_proof armed.
 */
class AtlasLoopOrphanWiringExecutionAdapter
{
    public function __construct(
        private readonly ?AtlasLoopWiredAcceptanceProducer $bridge = null,
        private readonly ?AtlasEvolutionFrozenJudge $judge = null,
    ) {}

    /**
     * @param  callable():array{test_rel:string, test_command:string, allowed_globs:list<string>}  $authorTest
     *         the engine writes the wired-behavior test on the pre-wiring tree and returns its locator
     * @param  callable():void  $authorWiring  the engine writes the production wiring that makes the test pass
     * @return array{certified:bool, reason:?string, acceptance:array<string,mixed>|null, verdict:array<string,mixed>|null}
     */
    public function execute(string $orphanRel, string $workspace, callable $authorTest, callable $authorWiring): array
    {
        $bridge = $this->bridge ?? new AtlasLoopWiredAcceptanceProducer;
        $judge = $this->judge ?? new AtlasEvolutionFrozenJudge;

        // 1. ENGINE authors the wired-behavior test on the pre-wiring tree.
        $authored = $authorTest();
        $testRel = trim((string) ($authored['test_rel'] ?? ''));
        $testCommand = trim((string) ($authored['test_command'] ?? ''));
        $allowedGlobs = array_values(array_filter((array) ($authored['allowed_globs'] ?? []), 'is_string'));
        if ($testRel === '' || $testCommand === '') {
            return ['certified' => false, 'reason' => 'authoring_incomplete', 'acceptance' => null, 'verdict' => null];
        }

        // 2. EARNED-RED + freeze: the authored test must GENUINELY fail before the wiring exists.
        $acceptance = $bridge->produce($orphanRel, $testRel, $testCommand, $allowedGlobs, $workspace);
        if ($acceptance === null) {
            return ['certified' => false, 'reason' => 'test_not_earned_red', 'acceptance' => null, 'verdict' => null];
        }

        // 3. COMMIT the authored test => it is the FROZEN baseline; the cert's diff is exactly the wiring and
        //    Guard 4e's git-stash sees the orphan at 0 callers on HEAD.
        $this->git($workspace, ['add', '-A']);
        $this->git($workspace, ['-c', 'user.email=loop@atlas', '-c', 'user.name=loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'orphan-wiring: freeze earned-RED test']);

        // 4. ENGINE authors the wiring (the only candidate diff). With the engine now authoring N wiring files
        //    (not just one), $authorWiring() may write SEVERAL files into the working tree.
        $authorWiring();

        // 4b. STAGE every authored wiring file (1 or N) into the index BEFORE the judge runs. A Guard 4e shape
        //     that inspects the INDEX (rather than only HEAD-vs-working-tree) would otherwise miss the extra
        //     un-staged files of a multi-file wiring. This idempotent `git add -A` guarantees ALL authored files
        //     are visible to the cert. It does NOT commit (the downstream handler still diffs staged+working),
        //     and it leaves the step-3 frozen-test commit untouched.
        $this->git($workspace, ['add', '-A']);

        // 5. CERTIFY — Guard 4e (wiredEarned ∧ orphanMethodKills) is author-blind.
        $verdict = $judge->score($workspace, $acceptance);

        return [
            'certified' => (bool) ($verdict['passed'] ?? false),
            'reason' => is_array($verdict['details'] ?? null) ? ($verdict['details']['reason'] ?? null) : null,
            'acceptance' => $acceptance,
            'verdict' => $verdict,
        ];
    }

    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }
}
