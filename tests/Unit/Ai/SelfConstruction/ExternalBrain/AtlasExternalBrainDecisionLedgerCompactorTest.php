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
        foreach (['lesson_id', 'causes', 'outcome', 'occurrence_count', 'confidence', 'retained_evidence', 'superseded_trace_ids', 'uncertainty'] as $k) {
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

        // T1+T2 merge to 1 lesson; T3 stays separate → 2 lessons total
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

    // ── lesson_count matches lessons list ─────────────────────────────────────

    public function test_lesson_count_matches_lessons_list_length(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_contradictory' => true]),
            $this->trace(['trace_id' => 'T4', 'uncertainty' => 'high']),
        ]]);

        $this->assertSame(count($result['lessons']), $result['lesson_count']);
    }
}
