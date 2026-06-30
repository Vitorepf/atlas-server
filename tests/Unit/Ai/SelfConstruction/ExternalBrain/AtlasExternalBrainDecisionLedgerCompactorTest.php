<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionLedgerCompactor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionLedgerCompactorTest extends TestCase
{
    private AtlasExternalBrainDecisionLedgerCompactor $compactor;

    protected function setUp(): void
    {
        $this->compactor = new AtlasExternalBrainDecisionLedgerCompactor;
    }

    private function trace(array $overrides = []): array
    {
        return array_merge([
            'trace_id'        => 'trace-'.uniqid(),
            'causes'          => ['queue_empty', 'low_throughput'],
            'outcome'         => 'triggered_origination',
            'evidence_refs'   => ['ref-a'],
            'uncertainty'     => 'low',
            'is_contradictory' => false,
            'is_stale'        => false,
            'reversibility'   => 'reversible',
            'scope'           => 'loop',
            'expires_at'      => null,
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->compactor->compact(['traces' => [$this->trace(['trace_id' => 'T1'])]]);

        foreach (['schema', 'lessons', 'lesson_count', 'compacted_from'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::SCHEMA, $result['schema']);
    }

    public function test_empty_traces_returns_zero_lessons(): void
    {
        $result = $this->compactor->compact(['traces' => []]);

        $this->assertSame(0, $result['lesson_count']);
        $this->assertSame(0, $result['compacted_from']);
        $this->assertSame([], $result['lessons']);
    }

    // ── AC1: lesson fields ────────────────────────────────────────────────────

    public function test_lesson_has_required_fields(): void
    {
        $result = $this->compactor->compact(['traces' => [$this->trace(['trace_id' => 'T1'])]]);

        $lesson = $result['lessons'][0];
        foreach ([
            'lesson_id', 'causes', 'outcome', 'occurrence_count', 'confidence',
            'retained_evidence', 'superseded_trace_ids', 'uncertainty',
            'reversibility', 'scope', 'expires_at',
        ] as $k) {
            $this->assertArrayHasKey($k, $lesson, "Missing field: {$k}");
        }
    }

    public function test_compacted_from_reflects_total_input_traces(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_contradictory' => true]),
        ]]);

        $this->assertSame(3, $result['compacted_from']);
    }

    // ── AC1: merging identical traces ─────────────────────────────────────────

    public function test_three_identical_low_uncertainty_traces_compact_to_one_high_confidence_lesson(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'evidence_refs' => ['ref-a']]),
            $this->trace(['trace_id' => 'T2', 'evidence_refs' => ['ref-b']]),
            $this->trace(['trace_id' => 'T3', 'evidence_refs' => ['ref-a', 'ref-c']]),
        ]]);

        $this->assertSame(1, $result['lesson_count']);
        $lesson = $result['lessons'][0];
        $this->assertSame(3, $lesson['occurrence_count']);
        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_HIGH, $lesson['confidence']);
        $this->assertContains('T1', $lesson['superseded_trace_ids']);
        $this->assertContains('T2', $lesson['superseded_trace_ids']);
        $this->assertContains('T3', $lesson['superseded_trace_ids']);
    }

    public function test_evidence_is_union_deduped_across_merged_traces(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'evidence_refs' => ['ref-a', 'ref-b']]),
            $this->trace(['trace_id' => 'T2', 'evidence_refs' => ['ref-b', 'ref-c']]),
        ]]);

        $retained = $result['lessons'][0]['retained_evidence'];
        sort($retained);
        $this->assertSame(['ref-a', 'ref-b', 'ref-c'], $retained);
    }

    public function test_two_identical_traces_compact_to_medium_confidence(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
        ]]);

        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_MEDIUM, $result['lessons'][0]['confidence']);
    }

    public function test_singleton_trace_gives_low_confidence(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
        ]]);

        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_LOW, $result['lessons'][0]['confidence']);
    }

    // ── AC2: contradictory kept separate ─────────────────────────────────────

    public function test_contradictory_trace_is_not_merged_with_identical_non_contradictory(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_contradictory' => true]),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $reasons = array_column($result['lessons'], 'kept_separate_reason');
        $this->assertContains('contradictory', $reasons);
    }

    public function test_contradictory_lesson_has_occurrence_count_one(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'is_contradictory' => true]),
        ]]);

        $lesson = $result['lessons'][0];
        $this->assertSame(1, $lesson['occurrence_count']);
        $this->assertSame('contradictory', $lesson['kept_separate_reason']);
    }

    // ── AC2: high-uncertainty kept separate ──────────────────────────────────

    public function test_high_uncertainty_trace_is_not_merged(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'uncertainty' => 'high']),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $reasons = array_column($result['lessons'], 'kept_separate_reason');
        $this->assertContains('high_uncertainty', $reasons);
    }

    public function test_high_uncertainty_lesson_confidence_is_low(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'uncertainty' => 'high']),
        ]]);

        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_LOW, $result['lessons'][0]['confidence']);
    }

    // ── AC2: stale trace kept separate ───────────────────────────────────────

    public function test_stale_trace_kept_separate(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_stale' => true]),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $reasons = array_column($result['lessons'], 'kept_separate_reason');
        $this->assertContains('stale', $reasons);
    }

    public function test_stale_lesson_has_occurrence_count_one(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'is_stale' => true]),
        ]]);

        $this->assertSame(1, $result['lessons'][0]['occurrence_count']);
        $this->assertSame('stale', $result['lessons'][0]['kept_separate_reason']);
    }

    // ── AC2: irreversible trace kept separate ─────────────────────────────────

    public function test_irreversible_trace_kept_separate(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'reversibility' => 'irreversible']),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $reasons = array_column($result['lessons'], 'kept_separate_reason');
        $this->assertContains('irreversible', $reasons);
    }

    // ── scope: different scopes not merged ────────────────────────────────────

    public function test_different_scope_not_merged(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'scope' => 'loop']),
            $this->trace(['trace_id' => 'T2', 'scope' => 'brain']),
        ]]);

        // Same causes+outcome but different scope → 2 separate lessons
        $this->assertSame(2, $result['lesson_count']);
    }

    public function test_same_scope_can_merge(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'scope' => 'loop']),
            $this->trace(['trace_id' => 'T2', 'scope' => 'loop']),
        ]]);

        $this->assertSame(1, $result['lesson_count']);
        $this->assertSame(2, $result['lessons'][0]['occurrence_count']);
    }

    // ── Different outcomes → different lessons ────────────────────────────────

    public function test_same_causes_different_outcomes_produce_separate_lessons(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'outcome' => 'outcome_a']),
            $this->trace(['trace_id' => 'T2', 'outcome' => 'outcome_b']),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $this->assertNull($result['lessons'][0]['kept_separate_reason']);
        $this->assertNull($result['lessons'][1]['kept_separate_reason']);
    }

    // ── reversibility, scope, expires_at preserved ───────────────────────────

    public function test_merged_lesson_preserves_scope(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'scope' => 'maestro']),
            $this->trace(['trace_id' => 'T2', 'scope' => 'maestro']),
        ]]);

        $this->assertSame('maestro', $result['lessons'][0]['scope']);
    }

    public function test_merged_lesson_reversibility_is_unknown_if_any_contributor_unknown(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'reversibility' => 'reversible']),
            $this->trace(['trace_id' => 'T2', 'reversibility' => 'unknown']),
        ]]);

        $this->assertSame('unknown', $result['lessons'][0]['reversibility']);
    }

    public function test_merged_lesson_reversibility_is_reversible_when_all_reversible(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'reversibility' => 'reversible']),
            $this->trace(['trace_id' => 'T2', 'reversibility' => 'reversible']),
        ]]);

        $this->assertSame('reversible', $result['lessons'][0]['reversibility']);
    }

    public function test_merged_lesson_expires_at_is_earliest_of_contributors(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'expires_at' => '2026-12-01']),
            $this->trace(['trace_id' => 'T2', 'expires_at' => '2026-09-01']),
        ]]);

        $this->assertSame('2026-09-01', $result['lessons'][0]['expires_at']);
    }

    public function test_merged_lesson_expires_at_is_null_when_no_contributor_has_expiry(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'expires_at' => null]),
            $this->trace(['trace_id' => 'T2', 'expires_at' => null]),
        ]]);

        $this->assertNull($result['lessons'][0]['expires_at']);
    }

    // ── deterministic lesson ID ───────────────────────────────────────────────

    public function test_lesson_id_is_deterministic_for_same_causes_outcome_scope(): void
    {
        $t1 = $this->trace(['trace_id' => 'T1', 'causes' => ['a', 'b'], 'outcome' => 'x', 'scope' => 'loop']);
        $t2 = $this->trace(['trace_id' => 'T2', 'causes' => ['a', 'b'], 'outcome' => 'x', 'scope' => 'loop']);

        $r1 = $this->compactor->compact(['traces' => [$t1]]);
        $r2 = $this->compactor->compact(['traces' => [$t2]]);

        $this->assertSame($r1['lessons'][0]['lesson_id'], $r2['lessons'][0]['lesson_id']);
    }

    // ── lesson_count matches lessons list ─────────────────────────────────────

    public function test_lesson_count_matches_lessons_list_length(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_contradictory' => true]),
            $this->trace(['trace_id' => 'T4', 'uncertainty' => 'high']),
            $this->trace(['trace_id' => 'T5', 'is_stale' => true]),
        ]]);

        $this->assertSame(count($result['lessons']), $result['lesson_count']);
    }
}
