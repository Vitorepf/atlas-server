<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OBRA (heavy work) — frozen ratchet for the MULTI-FILE certification JUDGE, the gate that makes
 * "park a multi-file obra for operator review" TRUSTWORTHY. The judge re-measures aggregate AST
 * cyclomatic across ALL changed files via a real git-stash before/after, and certifies a drop ONLY
 * when `candidate.max_per_method < baseline.max_per_method AND candidate.total <= baseline.total`.
 *
 * The `total <= baseline.total` term is the ungameability lock: an obra cannot launder a headline
 * worst-method drop in one file while INFLATING total complexity in a sibling — the classic
 * multi-file refactor game. This test pins both directions so a future edit to the (pétreo)
 * certifier can never silently weaken the aggregate judge to a per-file or headline-only check.
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

    public function test_noop_diff_fails_closed(): void
    {
        // No working-tree change => nothing to stash => the judge cannot prove a drop => fail-closed.
        $this->workspace = $this->repo(12, 2);

        $receipt = $this->certify();

        $this->assertNotTrue((bool) ($receipt['complexity_proof']['reduced'] ?? false), 'a no-op diff can never be certified as a reduction');
        $this->assertFalse((bool) $receipt['certified']);
    }
}
