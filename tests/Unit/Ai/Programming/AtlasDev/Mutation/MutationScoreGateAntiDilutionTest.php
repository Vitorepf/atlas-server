<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScope;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\PerFileMutationStats;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E3 — MutationScoreGate per-file anti-dilution check (VAL-E3-013).
 *
 * The aggregate union MSI can mask a weak test file when the scope spans
 * multiple source files: one file at MSI=40 can be diluted by another at
 * MSI=100, yielding an aggregate above threshold. The gate MUST trip if ANY
 * source file's per-file MSI is below threshold, regardless of the aggregate.
 *
 * Red-first tests:
 *   (a) A multi-file scope with aggregate MSI=80 but one file at MSI=40
 *       trips the gate.
 *   (b) All files above threshold passes.
 *   (c) The per-file check is consistent across advisory/hard modes.
 *
 * The anti-dilution check is structural (not an LLM judgment) and applies in
 * both advisory and hard modes. The existing aggregate check is preserved as
 * a fast-path: if the aggregate is already below threshold, the per-file
 * check is redundant.
 */
final class MutationScoreGateAntiDilutionTest extends TestCase
{
    // -- VAL-E3-013 (a): weak file among strong ones trips despite high aggregate --

    /**
     * VAL-E3-013: a multi-file scope where aggregate MSI=80 (above threshold
     * 60) but one file has per-file MSI=40 (below threshold) MUST trip the
     * gate. The weak file must not be masked/diluted by the strong files.
     */
    public function test_val_e3_013_advisory_weak_file_trips_despite_high_aggregate(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        // Aggregate MSI=80 (above threshold), but file A has MSI=40 (below).
        $result = $this->completedResultWithPerFile(
            aggregateMsi: 80.0,
            perFile: [
                new PerFileMutationStats('app/Strong.php', 100.0, 5, 5),
                new PerFileMutationStats('app/Weak.php', 40.0, 2, 5),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertTrue(
            $verdict->tripped,
            'VAL-E3-013: weak file (MSI=40) trips despite aggregate MSI=80',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-013: the honesty flag is appended for the weak file',
        );
        $this->assertFalse(
            $verdict->shouldFailGate,
            'advisory mode: flag only, never STATUS_FAILED for the flag alone',
        );
        $this->assertFalse($verdict->isNoOp, 'a real trip is not a no-op');
        $this->assertStringContainsString(
            '40.00',
            $verdict->reason,
            'the reason cites the weak file MSI',
        );
    }

    /**
     * VAL-E3-013 hard mode: the same dilution scenario trips with
     * STATUS_FAILED (never just downgrades in hard mode).
     */
    public function test_val_e3_013_hard_weak_file_trips_with_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 80.0,
            perFile: [
                new PerFileMutationStats('app/Strong.php', 100.0, 5, 5),
                new PerFileMutationStats('app/Weak.php', 40.0, 2, 5),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'hard mode trips on the weak file');
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E3-013 hard: routes to STATUS_FAILED (never just downgrades)',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'flag retained for auditability in hard mode',
        );
    }

    // -- VAL-E3-013 (b): all files above threshold passes --

    /**
     * VAL-E3-013: when ALL source files are at/above threshold (and the
     * aggregate is also above), the gate passes cleanly — no dilution, no
     * false trip.
     */
    public function test_val_e3_013_all_files_above_threshold_passes_advisory(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 85.0,
            perFile: [
                new PerFileMutationStats('app/A.php', 80.0, 4, 5),
                new PerFileMutationStats('app/B.php', 90.0, 9, 10),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertFalse(
            $verdict->tripped,
            'all files above threshold => no trip',
        );
        $this->assertSame([], $verdict->honestyFlags, 'no flag when all files are robust');
        $this->assertFalse($verdict->shouldFailGate);
    }

    /**
     * VAL-E3-013 hard mode: all files above threshold passes in hard too.
     */
    public function test_val_e3_013_all_files_above_threshold_passes_hard(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 100.0,
            perFile: [
                new PerFileMutationStats('app/A.php', 100.0, 5, 5),
                new PerFileMutationStats('app/B.php', 100.0, 10, 10),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'all files robust => no trip');
        $this->assertFalse($verdict->shouldFailGate, 'hard does not fail a robust multi-file scope');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- VAL-E3-013 (c): per-file check consistent across modes --

    /**
     * VAL-E3-013: the SAME dilution scenario trips in BOTH advisory and hard
     * modes. The per-file anti-dilution check is structural and applies
     * uniformly — only the verdict channel differs (advisory flag vs hard
     * STATUS_FAILED).
     */
    public function test_val_e3_013_per_file_check_consistent_across_advisory_and_hard(): void
    {
        // Build a result with aggregate above threshold but one weak file.
        $result = $this->completedResultWithPerFile(
            aggregateMsi: 75.0,
            perFile: [
                new PerFileMutationStats('app/Strong.php', 100.0, 10, 10),
                new PerFileMutationStats('app/Weak.php', 30.0, 3, 10),
            ],
        );

        // Advisory: trips with flag only.
        $advisoryGate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $advisoryVerdict = $advisoryGate->evaluate($result);

        $this->assertTrue($advisoryVerdict->tripped, 'advisory trips on the weak file');
        $this->assertFalse($advisoryVerdict->shouldFailGate, 'advisory: flag only');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $advisoryVerdict->honestyFlags,
        );

        // Hard: trips with STATUS_FAILED.
        $hardGate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );
        $hardVerdict = $hardGate->evaluate($result);

        $this->assertTrue($hardVerdict->tripped, 'hard trips on the same weak file');
        $this->assertTrue($hardVerdict->shouldFailGate, 'hard: STATUS_FAILED');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $hardVerdict->honestyFlags,
        );

        // Both modes trip for the SAME structural reason (the per-file
        // anti-dilution check is mode-independent in its detection; only the
        // channel differs).
        $this->assertStringContainsString(
            'anti-dilution',
            $advisoryVerdict->reason,
            'advisory reason cites the anti-dilution check',
        );
        $this->assertStringContainsString(
            'anti-dilution',
            $hardVerdict->reason,
            'hard reason cites the anti-dilution check',
        );
    }

    // -- Boundary: per-file MSI exactly at threshold passes --

    /**
     * A file whose per-file MSI equals the threshold (boundary inclusive)
     * does NOT trip the gate, consistent with the aggregate boundary
     * behavior (MSI >= threshold passes).
     */
    public function test_per_file_msi_at_threshold_boundary_passes(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 60.0,
            perFile: [
                new PerFileMutationStats('app/A.php', 60.0, 3, 5),
                new PerFileMutationStats('app/B.php', 60.0, 6, 10),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'boundary inclusive: per-file MSI == threshold passes');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Fast-path: aggregate below threshold still trips (per-file redundant)

    /**
     * When the aggregate MSI is already below threshold, the gate trips on
     * the aggregate check (fast-path) — the per-file check is redundant. This
     * preserves the existing aggregate behavior.
     */
    public function test_aggregate_below_threshold_trips_via_fast_path_regardless_of_per_file(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        // Aggregate below threshold even though per-file stats are all above
        // (a hypothetical inconsistent state). The aggregate check is the
        // fast-path and trips regardless.
        $result = $this->completedResultWithPerFile(
            aggregateMsi: 30.0,
            perFile: [
                new PerFileMutationStats('app/A.php', 100.0, 5, 5),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'aggregate below threshold trips (fast-path)');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );
    }

    // -- Null perFileStats: aggregate check still works (backward compat) ---

    /**
     * When perFileStats is null (no JSON report produced or unparseable), the
     * gate falls back to the aggregate check only. This preserves backward
     * compatibility for runs where per-file data was not collected.
     */
    public function test_null_per_file_stats_falls_back_to_aggregate_check_only(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        // Aggregate above threshold, no per-file data => passes (no dilution
        // check possible, aggregate is the sole gate).
        $resultNoPerFile = MutationTestingResult::completed(
            msi: 80.0,
            summaryPath: '/tmp/s.json',
            scope: new MutationScope(
                testFiles: ['tests/Unit/FooTest.php'],
                sourceFiles: ['app/Foo.php'],
            ),
            // perFileStats defaults to null.
        );

        $verdict = $gate->evaluate($resultNoPerFile);
        $this->assertFalse($verdict->tripped, 'null perFileStats: aggregate above threshold passes');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Off mode: anti-dilution check is a no-op (byte-identical) ----------

    /**
     * In off mode, the anti-dilution check is never reached — the gate is a
     * documented no-op regardless of per-file stats (byte-identical to
     * pre-E3, VAL-E3-010).
     */
    public function test_off_mode_anti_dilution_check_is_no_op(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 80.0,
            perFile: [
                new PerFileMutationStats('app/Weak.php', 10.0, 1, 10),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'off: never trips');
        $this->assertTrue($verdict->isNoOp, 'off: documented no-op');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Multiple weak files: the first is surfaced --------------------------

    /**
     * When multiple files are below threshold, the gate trips (surfacing the
     * first weak file in the reason for auditability).
     */
    public function test_multiple_weak_files_trip_gates_surfacing_first(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $result = $this->completedResultWithPerFile(
            aggregateMsi: 70.0,
            perFile: [
                new PerFileMutationStats('app/WeakA.php', 40.0, 2, 5),
                new PerFileMutationStats('app/WeakB.php', 20.0, 1, 5),
            ],
        );

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'multiple weak files trip');
        $this->assertStringContainsString(
            'WeakA.php',
            $verdict->reason,
            'the first weak file is surfaced in the reason',
        );
    }

    // -- Helpers --------------------------------------------------------------

    private function completedResultWithPerFile(float $aggregateMsi, array $perFile): MutationTestingResult
    {
        return MutationTestingResult::completed(
            msi: $aggregateMsi,
            summaryPath: '/tmp/e3-anti-dilution-summary.json',
            scope: new MutationScope(
                testFiles: ['tests/Unit/MultiFileTest.php'],
                sourceFiles: array_map(
                    fn (PerFileMutationStats $s) => $s->filePath,
                    $perFile,
                ),
            ),
            perFileStats: $perFile,
        );
    }
}
