<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationCandidateDeduper;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConsolidationCandidateDeduperTest extends TestCase
{
    private function deduper(): AtlasExternalBrainConsolidationCandidateDeduper
    {
        return new AtlasExternalBrainConsolidationCandidateDeduper;
    }

    public function test_schema_present(): void
    {
        $result = $this->deduper()->dedupe([]);

        $this->assertSame(AtlasExternalBrainConsolidationCandidateDeduper::SCHEMA, $result['schema']);
    }

    public function test_empty_input_yields_empty_result(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => []]);

        $this->assertSame([], $result['admitted']);
        $this->assertSame([], $result['rejected']);
    }

    // ── overlap_cluster_dedup_case ───────────────────────────────────────────

    public function test_overlapping_target_files_keep_highest_leverage_reject_rest(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.4],
            ['candidate_id' => 'b', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.9],
            ['candidate_id' => 'c', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.2],
        ]]);

        $this->assertSame(['b'], $result['admitted']);
        $rejectedIds = array_column($result['rejected'], 'candidate_id');
        $this->assertContains('a', $rejectedIds);
        $this->assertContains('c', $rejectedIds);
        foreach ($result['rejected'] as $entry) {
            $this->assertSame('b', $entry['kept_candidate_id']);
        }
    }

    public function test_overlapping_proof_scope_forms_a_cluster(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a', 'proof_scope' => ['tests/Unit/FooTest.php'], 'leverage_score' => 0.3],
            ['candidate_id' => 'b', 'proof_scope' => ['tests/Unit/FooTest.php'], 'leverage_score' => 0.7],
        ]]);

        $this->assertSame(['b'], $result['admitted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('a', $result['rejected'][0]['candidate_id']);
    }

    public function test_overlapping_capability_impact_forms_a_cluster(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a', 'capability_impact' => ['task_serving_receipt'], 'leverage_score' => 0.5],
            ['candidate_id' => 'b', 'capability_impact' => ['task_serving_receipt'], 'leverage_score' => 0.6],
        ]]);

        $this->assertSame(['b'], $result['admitted']);
    }

    public function test_rejected_entry_includes_reason_and_overlaps_with(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.9],
            ['candidate_id' => 'b', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.1],
        ]]);

        $rejected = $result['rejected'][0];
        $this->assertSame('lower_leverage_duplicate_in_overlap_cluster', $rejected['reason']);
        $this->assertContains('a', $rejected['overlaps_with']);
    }

    // ── distinct_candidate_keep_case ─────────────────────────────────────────

    public function test_distinct_candidates_with_no_overlap_all_remain_admitted(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a', 'target_files' => ['app/Foo.php'], 'capability_impact' => ['origination'], 'leverage_score' => 0.2],
            ['candidate_id' => 'b', 'target_files' => ['app/Bar.php'], 'capability_impact' => ['learning'], 'leverage_score' => 0.9],
        ]]);

        $this->assertSame(['a', 'b'], $result['admitted']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_low_leverage_distinct_candidate_is_still_admitted(): void
    {
        // Distinctness alone is enough — low leverage_score must not cause rejection when singleton.
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'solo', 'target_files' => ['app/OnlyMe.php'], 'leverage_score' => 0.05],
        ]]);

        $this->assertSame(['solo'], $result['admitted']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_multiple_independent_clusters_each_keep_their_own_winner(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'a1', 'target_files' => ['app/A.php'], 'leverage_score' => 0.3],
            ['candidate_id' => 'a2', 'target_files' => ['app/A.php'], 'leverage_score' => 0.8],
            ['candidate_id' => 'b1', 'target_files' => ['app/B.php'], 'leverage_score' => 0.9],
            ['candidate_id' => 'b2', 'target_files' => ['app/B.php'], 'leverage_score' => 0.4],
        ]]);

        $this->assertSame(['a2', 'b1'], $result['admitted']);
        $this->assertCount(2, $result['rejected']);
    }

    public function test_tie_leverage_score_breaks_deterministically_by_candidate_id(): void
    {
        $result = $this->deduper()->dedupe(['candidates' => [
            ['candidate_id' => 'z', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.5],
            ['candidate_id' => 'a', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.5],
        ]]);

        $this->assertSame(['a'], $result['admitted']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $deduper = $this->deduper();
        $candidates = ['candidates' => [
            ['candidate_id' => 'a', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.4],
            ['candidate_id' => 'b', 'target_files' => ['app/Foo.php'], 'leverage_score' => 0.9],
        ]];

        $this->assertSame($deduper->dedupe($candidates), $deduper->dedupe($candidates));
    }
}
