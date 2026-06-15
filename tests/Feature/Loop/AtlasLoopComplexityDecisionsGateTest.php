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
}
