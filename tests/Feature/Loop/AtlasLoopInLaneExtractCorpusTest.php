<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE R2 — the IN-LANE extract sequence feeds the SAME decomposition corpus the obra path writes, so the
 * prior-read multiplier (R2-read) can ground the next sequence's ordering. The grinder records one row per
 * extract-sequence grind terminal (certified = a certified winner); flag OFF => no write (byte-identical).
 */
final class AtlasLoopInLaneExtractCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full migration set is Postgres-only; boot ONLY this leap's table (idempotent guard).
        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }
    }

    /** The shape the framework synthesizer emits as payload.extract_sequence_plan (what the grinder records). */
    private function extractPlanNodes(): array
    {
        return [
            ['step_index' => 0, 'target_method' => 'App\\Calc::big', 'cyclomatic' => 19],
            ['step_index' => 1, 'target_method' => 'App\\Calc::mid', 'cyclomatic' => 12],
        ];
    }

    public function test_in_lane_extract_outcome_writes_and_reads_the_shared_corpus(): void
    {
        config(['atlas.loop.decomposition_corpus_enabled' => true]);
        $nodes = $this->extractPlanNodes();
        $recorder = new AtlasLoopDecompositionOutcomeRecorder;

        $recorder->record(['nodes' => $nodes], 'refactor_extract_sequence', true, 'winner');
        $recorder->record(['nodes' => $nodes], 'refactor_extract_sequence', false, 'no_winner');

        $hash = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint(['nodes' => $nodes])['hash'];
        $this->assertSame(['certified' => 1, 'total' => 2], $recorder->history($hash), 'the in-lane extract shape round-trips through the SAME corpus the obra path feeds');
        $this->assertSame(2, AtlasLoopDecompositionOutcome::query()->where('objective_kind', 'refactor_extract_sequence')->count());
    }

    public function test_off_is_byte_identical_no_corpus_write(): void
    {
        config(['atlas.loop.decomposition_corpus_enabled' => false]);
        (new AtlasLoopDecompositionOutcomeRecorder)->record(['nodes' => $this->extractPlanNodes()], 'refactor_extract_sequence', true, 'winner');
        $this->assertSame(0, AtlasLoopDecompositionOutcome::query()->count(), 'corpus flag OFF => no write (byte-identical)');
    }
}
