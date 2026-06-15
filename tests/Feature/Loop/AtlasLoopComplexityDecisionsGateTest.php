<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins the DECISION-POINTS complexity gate (commit 66d47cca).
 *
 * The bug it fixes: the cert required `candidate_total <= baseline_total`, but cyclomatic is
 * `1 + decisions` PER METHOD, so extract-method — the only way to reduce max-per-method — ALWAYS
 * raises `total` by the number of new helper methods. So every legitimate refactor that cut the
 * worst method was rejected as `complexity_not_reduced` (proven live: a cx19 method refactored to
 * cx4 via 10 extracted helpers, total 29->38, real decisions 21->20). The fix compares DECISION
 * POINTS (`total - methods`), which is extract-method-neutral; anti-gaming holds via the max-gate.
 */
final class AtlasLoopComplexityDecisionsGateTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tmp(string $code): string
    {
        $p = sys_get_temp_dir().'/atlas-decgate-'.bin2hex(random_bytes(5)).'.php';
        File::put($p, $code);
        $this->files[] = $p;

        return $p;
    }

    public function test_extraction_drops_max_and_raises_total_but_keeps_decisions_flat(): void
    {
        // BASELINE: one method, 4 ifs + 1 foreach => cyclomatic 6 (1 base + 5 decisions).
        $baselineSrc = <<<'PHP'
<?php
final class DecGateBaseline {
    public function run(int $n): int {
        if ($n === 1) { $n += 1; }
        if ($n === 2) { $n += 2; }
        if ($n === 3) { $n += 3; }
        if ($n === 4) { $n += 4; }
        foreach (range(1, $n) as $i) { $n += $i; }
        return $n;
    }
}
PHP;
        // CANDIDATE: SAME branches, PURE extraction into 2 helpers (no new decisions added).
        // run() -> cx 1, adjust() -> cx 5, accumulate() -> cx 2. max=5, total=8, methods=3.
        $candidateSrc = <<<'PHP'
<?php
final class DecGateCandidate {
    public function run(int $n): int {
        $n = $this->adjust($n);
        $n = $this->accumulate($n);
        return $n;
    }
    private function adjust(int $n): int {
        if ($n === 1) { $n += 1; }
        if ($n === 2) { $n += 2; }
        if ($n === 3) { $n += 3; }
        if ($n === 4) { $n += 4; }
        return $n;
    }
    private function accumulate(int $n): int {
        foreach (range(1, $n) as $i) { $n += $i; }
        return $n;
    }
}
PHP;
        $analyzer = new AtlasLoopSignalAnalyzer();
        $baseline = $analyzer->aggregateComplexity([$this->tmp($baselineSrc)]);
        $candidate = $analyzer->aggregateComplexity([$this->tmp($candidateSrc)]);

        // aggregateComplexity now exposes the summed method count.
        $this->assertSame(1, $baseline['methods']);
        $this->assertSame(3, $candidate['methods']);

        // The refactor genuinely cut the worst method.
        $this->assertLessThan($baseline['max_per_method'], $candidate['max_per_method'], 'extraction lowers the worst-method cyclomatic');

        // THE BUG: raw total RISES (the +1-per-new-method base), so the old gate falsely rejected.
        $this->assertGreaterThan($baseline['total'], $candidate['total'], 'extraction raises raw total -> old total-gate would reject this good refactor');

        // THE FIX: decision points (total - methods) do NOT rise -> the decisions-gate certifies it.
        $baselineDecisions = $baseline['total'] - $baseline['methods'];
        $candidateDecisions = $candidate['total'] - $candidate['methods'];
        $this->assertLessThanOrEqual($baselineDecisions, $candidateDecisions, 'decision points are extraction-neutral -> legitimate refactor certifies');
    }

    public function test_adding_real_complexity_raises_decisions_and_is_rejected(): void
    {
        // Anti-gaming: a candidate that ADDS branches raises decision points -> gate rejects.
        $baselineSrc = <<<'PHP'
<?php
final class DecGateBase2 {
    public function run(int $n): int {
        if ($n === 1) { return 1; }
        return $n;
    }
}
PHP;
        $bloatedSrc = <<<'PHP'
<?php
final class DecGateBloated {
    public function run(int $n): int { return $this->a($n); }
    private function a(int $n): int {
        if ($n === 1) { return 1; }
        if ($n === 2) { return 2; }
        if ($n === 3) { return 3; }
        foreach (range(1, $n) as $i) { $n += $i; }
        return $n;
    }
}
PHP;
        $analyzer = new AtlasLoopSignalAnalyzer();
        $baseline = $analyzer->aggregateComplexity([$this->tmp($baselineSrc)]);
        $bloated = $analyzer->aggregateComplexity([$this->tmp($bloatedSrc)]);

        $baselineDecisions = $baseline['total'] - $baseline['methods'];
        $bloatedDecisions = $bloated['total'] - $bloated['methods'];
        $this->assertGreaterThan($baselineDecisions, $bloatedDecisions, 'real added complexity raises decision points -> rejected even though split into methods');
    }

    /**
     * CERT-INTEGRITY LOCK (rank-2, prerequisite for a structural extract-to-new-file lane):
     * a god method (cx20) relocated INTACT into a NEW file + a cosmetic 1-pt drop on the hub file
     * is ZERO net simplification, yet the old `?? $candMax` (treat net-new file as no-change) let it
     * certify. The fail-closed lock makes any net-new candidate file refute the complexity verdict.
     */
    public function test_new_file_lock_rejects_god_method_relocated_intact_to_new_file(): void
    {
        // hub.php had the god method (max 20); candidate moved it to new.php (still 20) and only
        // cosmetically dropped the hub's own worst method to 6 — aggregate decisions did NOT rise.
        $baseline = ['total' => 27, 'methods' => 2, 'max_per_method' => 20, 'per_file_max' => ['src/Hub.php' => 20]];
        $candidate = ['total' => 26, 'methods' => 2, 'max_per_method' => 20, 'per_file_max' => ['src/Hub.php' => 6, 'src/New.php' => 20]];

        config(['atlas.loop.complexity_new_file_fail_closed' => true]);
        $this->assertFalse(
            AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, true, true),
            'a net-new file with no baseline worst-method must fail the complexity verdict closed'
        );
    }

    public function test_new_file_lock_off_restores_legacy_treat_as_no_change(): void
    {
        $baseline = ['total' => 27, 'methods' => 2, 'max_per_method' => 20, 'per_file_max' => ['src/Hub.php' => 20]];
        $candidate = ['total' => 26, 'methods' => 2, 'max_per_method' => 20, 'per_file_max' => ['src/Hub.php' => 6, 'src/New.php' => 20]];

        config(['atlas.loop.complexity_new_file_fail_closed' => false]);
        $this->assertTrue(
            AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, true, true),
            'flag OFF restores the legacy treat-net-new-as-no-change behaviour (the hole), proving the lock is what closes it'
        );
    }

    public function test_new_file_lock_does_not_affect_single_file_extract(): void
    {
        // The proven cx19->cx4 single-file extract (helpers added, NO net-new file) must stay certified
        // with the lock ON — byte-identical, the lock only bites net-new files.
        $baseline = ['total' => 20, 'methods' => 1, 'max_per_method' => 19, 'per_file_max' => ['src/A.php' => 19]];
        $candidate = ['total' => 22, 'methods' => 4, 'max_per_method' => 4, 'per_file_max' => ['src/A.php' => 4]];

        config(['atlas.loop.complexity_new_file_fail_closed' => true]);
        $this->assertTrue(
            AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, true, true),
            'a genuine single-file extract (no net-new file) is unaffected by the new-file lock'
        );
    }

    public function test_file_complexity_emits_per_method_census_without_changing_aggregates(): void
    {
        // Slice 1 of the structural-refactor lane: an ADDITIVE per-method census keyed by qualified
        // identity, so a method RELOCATED into another class is a DISTINCT identity (A::run != B::run) —
        // the anti-relocation foundation for a future structural complexity-drop gate. The existing flat
        // aggregates must stay byte-identical.
        $src = <<<'PHP'
<?php
final class A { public function run(int $n){ if($n>0){return 1;} return 0; } }
final class B { public function run(int $n){ return 2; } }
function foo(int $n){ if($n===1){return 1;} return 0; }
$x = new class { public function ghost(){ if(true){return 1;} return 0; } };
PHP;
        $r = (new AtlasLoopSignalAnalyzer())->fileComplexity($src);

        // Qualified identities: same bare name in different classes are DISTINCT (relocation trap closed).
        $this->assertSame(2, $r['per_method']['A::run']);
        $this->assertSame(1, $r['per_method']['B::run']);
        $this->assertSame(2, $r['per_method']['\\foo']);
        // Anonymous-class method has no stable identity -> excluded from per_method...
        $this->assertCount(3, $r['per_method']);
        // ...but still counted in the byte-identical flat aggregates (4 methods incl. ghost; total 7).
        $this->assertSame(4, $r['methods']);
        $this->assertSame(7, $r['total']);
        $this->assertSame(2, $r['max_per_method']);
    }

    public function test_aggregate_complexity_unions_per_method_across_files(): void
    {
        // Slice 2 of the structural lane: aggregateComplexity unions the per-method census ACROSS the
        // cluster, keyed by qualified identity, so a future structural gate can compare a SPECIFIC
        // method baseline-vs-candidate. Same bare name in different classes stays DISTINCT (HubA::run
        // != HubB::run) — the anti-relocation foundation. Existing flat aggregates stay byte-identical.
        $fileA = "<?php\nfinal class HubA { public function run(int \$n){ if(\$n>0){return 1;} return 0; } public function tiny(){ return 1; } }\n";
        $fileB = "<?php\nfinal class HubB { public function run(int \$n){ if(\$n===1){return 1;} if(\$n===2){return 2;} return 0; } }\n";

        $agg = (new AtlasLoopSignalAnalyzer())->aggregateComplexity([$this->tmp($fileA), $this->tmp($fileB)]);

        // Unioned across both files; qualified identities are distinct (no collapse of the two run()s).
        $this->assertSame(2, $agg['per_method']['HubA::run']);
        $this->assertSame(1, $agg['per_method']['HubA::tiny']);
        $this->assertSame(3, $agg['per_method']['HubB::run']);
        $this->assertCount(3, $agg['per_method']);
        // Byte-identical flat aggregates: max=worst(HubB::run=3), total=2+1+3, methods=3, files=2.
        $this->assertSame(3, $agg['max_per_method']);
        $this->assertSame(6, $agg['total']);
        $this->assertSame(3, $agg['methods']);
        $this->assertSame(2, $agg['files']);
    }

    public function test_structural_gate_certifies_a_genuine_extract_class(): void
    {
        // A::run (cx20) -> A::run thin (cx5, delegates) + a NEW class B with two SMALLER helpers.
        // The kept identity A::run strictly dropped IN PLACE; new helpers are below the old worst;
        // decisions did not rise (branches relocated, not added). => structural win.
        $baseline = ['per_method' => ['A::run' => 20], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];
        $candidate = ['per_method' => ['A::run' => 5, 'B::doX' => 8, 'B::doY' => 7], 'max_per_method' => 8, 'total' => 20, 'methods' => 3];

        $this->assertTrue(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_rejects_pure_relocation_moved_and_renamed(): void
    {
        // THE cardinal case: A::run (cx20) moved INTACT to B::run (cx20). No kept identity dropped,
        // and a new identity sits AT the baseline worst => relocation, certifies nothing.
        $baseline = ['per_method' => ['A::run' => 20], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];
        $candidate = ['per_method' => ['B::run' => 20], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_rejects_within_scope_relocation_to_sibling_class(): void
    {
        // The hole the design named: a method relocated to a sibling class WITHIN the allowed file set
        // (no net-new file, so the new-file lock never fires). Distinct identities, nothing dropped in
        // place => still a relocation => rejected by the per-method-identity gate.
        $baseline = ['per_method' => ['Hub::heavy' => 14, 'Hub::tiny' => 1], 'max_per_method' => 14, 'total' => 15, 'methods' => 2];
        $candidate = ['per_method' => ['Sibling::heavy' => 14, 'Hub::tiny' => 1], 'max_per_method' => 14, 'total' => 15, 'methods' => 2];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_rejects_added_branches_balloon(): void
    {
        // A::run dropped in place BUT the extraction ADDED net branches (decisions rose) => rejected.
        $baseline = ['per_method' => ['A::run' => 20], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];
        $candidate = ['per_method' => ['A::run' => 5, 'B::x' => 18], 'max_per_method' => 18, 'total' => 23, 'methods' => 2];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_rejects_a_kept_method_regression(): void
    {
        // A::run dropped, but a kept sibling A::other got WORSE => laundering complexity => rejected.
        $baseline = ['per_method' => ['A::run' => 12, 'A::other' => 5], 'max_per_method' => 12, 'total' => 17, 'methods' => 2];
        $candidate = ['per_method' => ['A::run' => 6, 'A::other' => 9], 'max_per_method' => 9, 'total' => 15, 'methods' => 2];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_rejects_new_method_at_baseline_worst_complexity(): void
    {
        // A::run dropped in place, but the extracted NEW method is AS COMPLEX as the old worst (the god
        // method merely re-homed behind a thin wrapper) => rejected by the anti-relocation clause.
        $baseline = ['per_method' => ['A::run' => 20], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];
        $candidate = ['per_method' => ['A::run' => 2, 'B::big' => 20], 'max_per_method' => 20, 'total' => 22, 'methods' => 2];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }

    public function test_structural_gate_fails_closed_without_a_per_method_census(): void
    {
        $baseline = ['per_method' => [], 'max_per_method' => 20, 'total' => 20, 'methods' => 1];
        $candidate = ['per_method' => [], 'max_per_method' => 8, 'total' => 20, 'methods' => 3];

        $this->assertFalse(AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate));
    }
}
