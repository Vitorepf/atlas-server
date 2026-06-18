<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Differential;

use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceGate;
use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceResult;
use App\Services\Ai\Programming\AtlasDev\Differential\DifferentialTestingService;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E4 -- DifferentialTestingService unit tests.
 *
 * Covers the pure comparison logic (agreement vs divergence) and the gate's
 * mode routing (off/advisory/hard). The service is model-irrelevant: it
 * compares candidate diff texts and produces a deterministic verdict.
 *
 * VAL-E4-001 (agreement), VAL-E4-002 (divergence carries diffs),
 * VAL-E4-004 (ctor invariant is exercised at the feature level),
 * VAL-E4-010 (off/advisory/hard mode routing).
 */
final class DifferentialTestingServiceTest extends TestCase
{
    /**
     * VAL-E4-001: N>=2 candidates with identical diffs report agreement.
     */
    public function test_agreeing_candidates_yield_agreement_result(): void
    {
        $service = new DifferentialTestingService;

        $result = $service->compare([
            ['index' => 0, 'candidate_diff_text' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n-return 'before';\n+return 'after';\n"],
            ['index' => 1, 'candidate_diff_text' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n-return 'before';\n+return 'after';\n"],
            ['index' => 2, 'candidate_diff_text' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n-return 'before';\n+return 'after';\n"],
        ]);

        $this->assertTrue($result->agreed, 'VAL-E4-001: identical diffs => agreement');
        $this->assertFalse($result->diverged, 'VAL-E4-001: no divergence on agreement');
        $this->assertSame([], $result->divergentDiffs, 'VAL-E4-001: no divergent diffs on agreement');
    }

    /**
     * VAL-E4-002: N>=2 candidates with differing diffs report divergence
     * carrying the divergent diffs as evidence.
     */
    public function test_divergent_candidates_yield_divergence_with_diffs(): void
    {
        $service = new DifferentialTestingService;

        $diffA = "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n+return 'a';\n";
        $diffB = "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n+return 'b';\n";

        $result = $service->compare([
            ['index' => 0, 'candidate_diff_text' => $diffA],
            ['index' => 1, 'candidate_diff_text' => $diffB],
        ]);

        $this->assertTrue($result->diverged, 'VAL-E4-002: differing diffs => divergence');
        $this->assertFalse($result->agreed, 'VAL-E4-002: no agreement on divergence');

        // VAL-E4-002: divergent diffs are carried as evidence
        $this->assertNotEmpty($result->divergentDiffs, 'VAL-E4-002: divergence carries diffs');
        $this->assertCount(2, $result->divergentDiffs, 'both candidate diffs present');
        // Each entry identifies the candidate index and carries its diff
        $indices = array_column($result->divergentDiffs, 'index');
        $this->assertSame([0, 1], $indices);
    }

    /**
     * Fewer than 2 candidates is a skip (nothing to compare), never a
     * divergence or agreement.
     */
    public function test_single_candidate_yields_skip(): void
    {
        $service = new DifferentialTestingService;

        $result = $service->compare([
            ['index' => 0, 'candidate_diff_text' => 'some diff'],
        ]);

        $this->assertTrue($result->isSkipped, 'fewer than 2 candidates => skipped');
        $this->assertFalse($result->agreed, 'skip is not agreement');
        $this->assertFalse($result->diverged, 'skip is not divergence');
        $this->assertNotEmpty($result->skipReason, 'skip carries a reason');
    }

    /**
     * Empty candidate list is a skip.
     */
    public function test_empty_candidates_yields_skip(): void
    {
        $service = new DifferentialTestingService;

        $result = $service->compare([]);

        $this->assertTrue($result->isSkipped);
        $this->assertNotEmpty($result->skipReason);
    }

    /**
     * VAL-E4-002: three candidates, two identical and one different => divergence.
     * The divergent diffs identify which candidates differed.
     */
    public function test_partial_agreement_still_yields_divergence(): void
    {
        $service = new DifferentialTestingService;

        $diffA = "--- a/app/Foo.php\n+++ b/app/Foo.php\n+return 'a';\n";
        $diffB = "--- a/app/Foo.php\n+++ b/app/Foo.php\n+return 'b';\n";

        $result = $service->compare([
            ['index' => 0, 'candidate_diff_text' => $diffA],
            ['index' => 1, 'candidate_diff_text' => $diffA], // same as c0
            ['index' => 2, 'candidate_diff_text' => $diffB], // different
        ]);

        $this->assertTrue($result->diverged, 'any difference => divergence');
        // All three candidate diffs are carried as evidence
        $this->assertCount(3, $result->divergentDiffs);
    }

    /**
     * Candidates that threw during generation (no diff text) are handled:
     * they count as a divergence from candidates that produced a diff.
     */
    public function test_throwing_candidate_treated_as_divergence(): void
    {
        $service = new DifferentialTestingService;

        $result = $service->compare([
            ['index' => 0, 'candidate_diff_text' => 'some diff'],
            ['index' => 1, 'candidate_diff_text' => null], // threw
        ]);

        $this->assertTrue($result->diverged, 'a throwing candidate diverges from a producing one');
    }

    // -- Gate mode routing (VAL-E4-010) -------------------------------------

    /**
     * VAL-E4-010: off mode => byte-identical no-op (never trips).
     */
    public function test_gate_off_mode_is_noop_even_on_divergence(): void
    {
        $gate = new CandidateDivergenceGate(ElevationConfig::for('e4', ['mode' => 'off']));

        $result = CandidateDivergenceResult::divergence([
            ['index' => 0, 'diff' => 'diff A'],
            ['index' => 1, 'diff' => 'diff B'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'VAL-E4-010: off mode never trips');
        $this->assertFalse($verdict->shouldFailGate, 'off mode never fails the gate');
        $this->assertSame([], $verdict->honestyFlags, 'off mode raises no flag');
        $this->assertTrue($verdict->isNoOp, 'off mode is a no-op');
    }

    /**
     * VAL-E4-010: advisory mode => honesty flag only (no STATUS_FAILED).
     */
    public function test_gate_advisory_mode_appends_flag_on_divergence(): void
    {
        $gate = new CandidateDivergenceGate(ElevationConfig::for('e4', ['mode' => 'advisory']));

        $result = CandidateDivergenceResult::divergence([
            ['index' => 0, 'diff' => 'diff A'],
            ['index' => 1, 'diff' => 'diff B'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'advisory trips on divergence');
        $this->assertFalse($verdict->shouldFailGate, 'advisory never forces STATUS_FAILED');
        $this->assertContains(
            CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE,
            $verdict->honestyFlags,
            'advisory appends the candidate_divergence flag',
        );
    }

    /**
     * VAL-E4-010: hard mode => STATUS_FAILED on divergence.
     */
    public function test_gate_hard_mode_fails_gate_on_divergence(): void
    {
        $gate = new CandidateDivergenceGate(ElevationConfig::for('e4', ['mode' => 'hard']));

        $result = CandidateDivergenceResult::divergence([
            ['index' => 0, 'diff' => 'diff A'],
            ['index' => 1, 'diff' => 'diff B'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'hard trips on divergence');
        $this->assertTrue($verdict->shouldFailGate, 'hard forces STATUS_FAILED');
        $this->assertContains(
            CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE,
            $verdict->honestyFlags,
            'hard retains the flag for auditability',
        );
    }

    /**
     * Agreement never trips regardless of mode.
     */
    public function test_gate_agreement_never_trips_in_any_mode(): void
    {
        foreach (['advisory', 'hard'] as $mode) {
            $gate = new CandidateDivergenceGate(ElevationConfig::for('e4', ['mode' => $mode]));
            $result = CandidateDivergenceResult::agreement(2);
            $verdict = $gate->evaluate($result);

            $this->assertFalse($verdict->tripped, "agreement never trips in {$mode} mode");
            $this->assertSame([], $verdict->honestyFlags, "agreement raises no flag in {$mode} mode");
        }
    }

    /**
     * A skipped result (fewer than 2 candidates) never trips.
     */
    public function test_gate_skip_never_trips(): void
    {
        $gate = new CandidateDivergenceGate(ElevationConfig::for('e4', ['mode' => 'advisory']));
        $result = CandidateDivergenceResult::skipped('fewer than 2 candidates');
        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'skip never trips');
        $this->assertTrue($verdict->isNoOp, 'skip is a no-op');
        $this->assertSame([], $verdict->honestyFlags);
    }
}
