<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OBRA (heavy work) — frozen ratchet for the MULTI-FILE certification JUDGE, the gate that makes
 * "park a multi-file obra for operator review" TRUSTWORTHY. The judge re-measures AST cyclomatic
 * across the changed files via a real git-stash before/after and certifies a drop under the PER-FILE
 * rule: NO changed file's worst method regressed (anti-laundering) AND at least one file's worst
 * method dropped AND the decisions aggregate (total − methods) did not rise.
 *
 * The per-file rule replaced a cluster-GLOBAL-max rule that false-rejected the majority of genuine
 * cluster refactors — any obra that simplified the hub but did not happen to own the cluster's single
 * global-worst method (which often lives in an untouched sibling) was wrongly refused. The
 * no-file-regressed + decisions-non-increasing terms keep the ungameability lock: an obra still cannot
 * launder a headline drop by inflating a sibling. This test pins BOTH directions (genuine cluster
 * drop certifies; laundering rejected) so a future edit can never silently weaken — or re-break — the judge.
 */
final class AtlasLoopMultiFileComplexityJudgeTest extends TestCase
{
    private string $workspace = '';

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    /** A class whose single method has cyclomatic ≈ $ifs + 1 (one decision point per `if`). */
    private function klass(string $class, int $ifs): string
    {
        $body = "    public function run(int \$v): string {\n";
        for ($i = 0; $i < $ifs; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return "<?php\nfinal class {$class} {\n{$body}\n}\n";
    }

    /** A 2-file git repo (HubA cx≈$aBefore, HubB cx≈$bBefore) committed as the baseline. */
    private function repo(int $aBefore, int $bBefore): string
    {
        $dir = sys_get_temp_dir().'/atlas-mfcx-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        file_put_contents($dir.'/src/HubA.php', $this->klass('HubA', $aBefore - 1));
        file_put_contents($dir.'/src/HubB.php', $this->klass('HubB', $bBefore - 1));
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            (new Process($argv, $dir, null, null, 30.0))->run();
        }

        return $dir;
    }

    private function acceptance(): array
    {
        return [
            'commands' => ['php -r "exit(0);"'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,
            'revert_recheck' => false,
            'timeout_seconds' => 30,
        ];
    }

    private function certify(): array
    {
        return app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'reduce cluster complexity',
            'allowed_files' => ['src/HubA.php', 'src/HubB.php'],
        ]);
    }

    public function test_genuine_aggregate_drop_is_certified_as_reduced(): void
    {
        // Baseline: HubA cx 12, HubB cx 2 (max 12, total 14). Candidate: HubA -> 6, HubB unchanged
        // (max 6, total 8). Both max and total fall => a real cluster refactor.
        $this->workspace = $this->repo(12, 2);
        file_put_contents($this->workspace.'/src/HubA.php', $this->klass('HubA', 5)); // cx 6

        $receipt = $this->certify();
        $proof = $receipt['complexity_proof'];

        $this->assertIsArray($proof, 'the multi-file judge ran the aggregate measurement');
        $this->assertTrue((bool) $proof['reduced'], 'a genuine aggregate drop (max and total fall) is certified as reduced');
        $this->assertSame(12, (int) $proof['baseline_max']);
        $this->assertSame(6, (int) $proof['candidate_max']);
        $this->assertLessThanOrEqual((int) $proof['baseline_total'], (int) $proof['candidate_total']);
    }

    public function test_hub_drop_certifies_even_when_cluster_global_worst_lives_in_an_untouched_sibling(): void
    {
        // THE big-obra=3/10 keystone (false-reject). A real cluster refactor: HubA's worst method
        // genuinely drops (9 -> 4); HubB is ALSO in the change set (its call-site to HubA updated)
        // but HubB's worst method (14, the cluster's GLOBAL-worst) is unrelated and unchanged. The old
        // cluster-global-max rule rejected this (max 14 -> 14, "no drop") — falsely killing the
        // majority of genuine cluster refactors. The per-file rule certifies it: a real per-file drop
        // happened, NO file regressed, and the decisions aggregate did not rise.
        $this->workspace = $this->repo(9, 14); // HubA worst 9, HubB worst 14 (the global worst)
        file_put_contents($this->workspace.'/src/HubA.php', $this->klass('HubA', 3)); // 9 -> 4: real worst-method drop
        // HubB changes (call-site update) but its worst method stays 14 — modelled as the same 14-cx
        // method with a trailing comment, so the file differs yet HubB's per-file max is unchanged.
        file_put_contents($this->workspace.'/src/HubB.php', $this->klass('HubB', 13)."// call-site updated to HubA::run\n");

        $proof = $this->certify()['complexity_proof'];

        $this->assertIsArray($proof);
        $this->assertSame(14, (int) $proof['baseline_max']);
        $this->assertSame(14, (int) $proof['candidate_max'], 'the cluster global-max did NOT drop (it lives in the sibling) — the OLD rule false-rejected here');
        $this->assertLessThanOrEqual((int) $proof['baseline_total'], (int) $proof['candidate_total'], 'no total/decisions inflation (the anti-gaming lock still holds)');
        $this->assertTrue((bool) $proof['reduced'], 'a real per-file hub drop with no file regressed IS a reduction (per-file rule)');
    }

    public function test_complexity_laundered_into_a_sibling_is_rejected(): void
    {
        // THE gaming move: drop the headline worst-method in HubA (12 -> 8) but INFLATE the sibling
        // HubB (2 -> 10). Aggregate max falls (12 -> 10) so a headline/per-file judge would pass it,
        // but TOTAL rises (14 -> 18). The `total <= baseline.total` term MUST reject it.
        $this->workspace = $this->repo(12, 2);
        file_put_contents($this->workspace.'/src/HubA.php', $this->klass('HubA', 7));  // cx 8 (headline drop)
        file_put_contents($this->workspace.'/src/HubB.php', $this->klass('HubB', 9));  // cx 10 (inflated)

        $receipt = $this->certify();
        $proof = $receipt['complexity_proof'];

        $this->assertIsArray($proof);
        $this->assertLessThan((int) $proof['baseline_max'], (int) $proof['candidate_max'], 'the headline max DID drop (12 -> 10) — a per-file judge would be fooled');
        $this->assertGreaterThan((int) $proof['baseline_total'], (int) $proof['candidate_total'], 'but total complexity was inflated in the sibling');
        $this->assertFalse((bool) $proof['reduced'], 'laundering complexity into a sibling is REJECTED by the aggregate-total term');
        $this->assertFalse((bool) $receipt['certified'], 'the obra is not certified when total complexity rose');
        $this->assertContains('complexity_gate:complexity_not_reduced', $receipt['reasons']);
    }

    public function test_changed_file_outside_allowed_cluster_is_rejected_by_scope_gate(): void
    {
        // A genuine drop in the allowed files, but the obra ALSO edited a file OUTSIDE the allowed
        // cluster. The diff-scoped measurement alone would not catch it; the scope gate MUST refuse.
        $this->workspace = $this->repo(12, 2);
        file_put_contents($this->workspace.'/src/HubA.php', $this->klass('HubA', 5));      // allowed, drop
        file_put_contents($this->workspace.'/src/Outside.php', $this->klass('Outside', 3)); // NEW, not allowed

        $receipt = $this->certify(); // allowed_files = ['src/HubA.php','src/HubB.php']

        $this->assertFalse((bool) $receipt['certified'], 'editing a file outside the allowed cluster is rejected');
        $this->assertStringContainsString('changed_files_outside_allowed', implode('|', $receipt['reasons']));
    }

    public function test_noop_diff_fails_closed(): void
    {
        // No working-tree change => nothing to stash => the judge cannot prove a drop => fail-closed.
        $this->workspace = $this->repo(12, 2);

        $receipt = $this->certify();

        $this->assertNotTrue((bool) ($receipt['complexity_proof']['reduced'] ?? false), 'a no-op diff can never be certified as a reduction');
        $this->assertFalse((bool) $receipt['certified']);
    }
}
