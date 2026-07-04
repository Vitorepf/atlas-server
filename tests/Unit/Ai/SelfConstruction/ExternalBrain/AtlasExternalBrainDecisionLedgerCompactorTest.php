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

    // ── AC2: expired trace kept separate ─────────────────────────────────────

    public function test_expired_trace_kept_separate(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3', 'is_expired' => true]),
        ]]);

        $this->assertSame(2, $result['lesson_count']);
        $reasons = array_column($result['lessons'], 'kept_separate_reason');
        $this->assertContains('expired', $reasons);
    }

    public function test_expired_lesson_has_occurrence_count_one(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'is_expired' => true]),
        ]]);

        $this->assertSame(1, $result['lessons'][0]['occurrence_count']);
        $this->assertSame('expired', $result['lessons'][0]['kept_separate_reason']);
    }

    // ── AC3: compaction_savings ───────────────────────────────────────────────

    public function test_compaction_savings_counts_repeated_traces_removed(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3']),
        ]]);

        $this->assertArrayHasKey('compaction_savings', $result);
        $this->assertSame(2, $result['compaction_savings']);
    }

    public function test_compaction_savings_zero_when_nothing_merges(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'is_contradictory' => true]),
        ]]);

        $this->assertSame(0, $result['compaction_savings']);
    }

    // ── contributor_ids preserved on merged lessons ──────────────────────────

    public function test_merged_lesson_preserves_contributor_ids(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
        ]]);

        $this->assertArrayHasKey('contributor_ids', $result['lessons'][0]);
        $this->assertContains('T1', $result['lessons'][0]['contributor_ids']);
        $this->assertContains('T2', $result['lessons'][0]['contributor_ids']);
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

    // ── compactDecisions() ──────────────────────────────────────────────────────

    public function test_compact_decisions_has_required_keys(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => []]);

        foreach (['compact_summary', 'dropped_count', 'preserved_count', 'revalidate_count'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_compact_decisions_preserves_durable_decision(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['preserved_count']);
        $this->assertSame(0, $result['dropped_count']);
    }

    public function test_compact_decisions_preserves_active_constraint(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'C1', 'type' => 'active_constraint', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['preserved_count']);
    }

    public function test_compact_decisions_preserves_failed_pattern_negative_result_rule(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'F1', 'type' => 'failed_pattern', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['preserved_count']);
    }

    public function test_compact_decisions_drops_superseded_entry(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'superseded'],
        ]]);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame(0, $result['preserved_count']);
    }

    public function test_compact_decisions_drops_duplicate_entry(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'duplicate'],
        ]]);

        $this->assertSame(1, $result['dropped_count']);
    }

    public function test_compact_decisions_marks_conflict_for_revalidation_instead_of_dropping(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'active', 'conflicts_with' => ['D2']],
        ]]);

        $this->assertSame(1, $result['revalidate_count']);
        $this->assertSame(0, $result['dropped_count']);
        $this->assertSame(0, $result['preserved_count']);
    }

    public function test_compact_decisions_conflict_overrides_superseded_status(): void
    {
        // Even a "superseded" entry must not be silently dropped if it conflicts —
        // conflicts always require revalidation, never silent newest-wins resolution.
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'superseded', 'conflicts_with' => ['D2']],
        ]]);

        $this->assertSame(1, $result['revalidate_count']);
        $this->assertSame(0, $result['dropped_count']);
    }

    public function test_compact_decisions_summary_mentions_counts(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'D1', 'type' => 'durable_decision', 'status' => 'active'],
            ['decision_id' => 'D2', 'type' => 'durable_decision', 'status' => 'duplicate'],
        ]]);

        $this->assertStringContainsString('preserved 1', $result['compact_summary']);
        $this->assertStringContainsString('dropped 1', $result['compact_summary']);
    }

    public function test_compact_decisions_preserves_poison_pattern(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'P1', 'type' => 'poison_pattern', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['preserved_count']);
        $this->assertSame(0, $result['dropped_count']);
    }

    public function test_compact_decisions_preserves_next_action_receipt(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'N1', 'type' => 'next_action_receipt', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['preserved_count']);
        $this->assertSame(0, $result['dropped_count']);
    }

    public function test_compact_decisions_drops_status_narration_with_reason(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'S1', 'type' => 'status_narration', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame('repeated_status_narration', $result['dropped'][0]['reason']);
    }

    public function test_compact_decisions_drops_queue_snapshot_with_reason(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'Q1', 'type' => 'queue_snapshot', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame('stale_queue_snapshot', $result['dropped'][0]['reason']);
    }

    public function test_compact_decisions_drops_speculation_with_reason(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            ['decision_id' => 'SP1', 'type' => 'speculation', 'status' => 'active'],
        ]]);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame('non_actionable_speculation', $result['dropped'][0]['reason']);
    }

    public function test_compact_decisions_never_leaks_raw_provider_internals(): void
    {
        $result = $this->compactor->compactDecisions(['decisions' => [
            [
                'decision_id' => 'D1',
                'type' => 'durable_decision',
                'status' => 'active',
                'raw_transcript' => 'SECRET_PROVIDER_TRANSCRIPT',
                'provider_prompt' => 'SECRET_PROVIDER_PROMPT',
            ],
            [
                'decision_id' => 'S1',
                'type' => 'status_narration',
                'status' => 'active',
                'raw_transcript' => 'SECRET_PROVIDER_TRANSCRIPT',
                'provider_prompt' => 'SECRET_PROVIDER_PROMPT',
            ],
        ]]);

        $encoded = json_encode($result);

        $this->assertStringNotContainsString('SECRET_PROVIDER_TRANSCRIPT', $encoded);
        $this->assertStringNotContainsString('SECRET_PROVIDER_PROMPT', $encoded);
    }

    // ── compaction_metrics ─────────────────────────────────────────────────────

    public function test_compaction_metrics_has_required_fields(): void
    {
        $result = $this->compactor->compact(['traces' => [$this->trace(['trace_id' => 'T1'])]]);

        $metrics = $result['compaction_metrics'];
        foreach (['input_trace_count', 'output_lesson_count', 'compaction_ratio', 'kept_separate_count', 'retained_evidence_ref_count', 'lost_evidence_ref_count'] as $k) {
            $this->assertArrayHasKey($k, $metrics);
        }
    }

    public function test_lost_evidence_ref_count_is_zero_when_merging(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'evidence_refs' => ['ref-a']]),
            $this->trace(['trace_id' => 'T2', 'evidence_refs' => ['ref-b']]),
        ]]);

        $this->assertSame(0, $result['compaction_metrics']['lost_evidence_ref_count']);
        $this->assertSame(2, $result['compaction_metrics']['retained_evidence_ref_count']);
    }

    public function test_kept_separate_count_includes_all_five_isolation_reasons(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1', 'is_contradictory' => true]),
            $this->trace(['trace_id' => 'T2', 'uncertainty' => 'high']),
            $this->trace(['trace_id' => 'T3', 'is_stale' => true]),
            $this->trace(['trace_id' => 'T4', 'reversibility' => 'irreversible']),
            $this->trace(['trace_id' => 'T5', 'is_expired' => true]),
        ]]);

        $this->assertSame(5, $result['compaction_metrics']['kept_separate_count']);
    }

    public function test_compaction_metrics_input_and_output_counts(): void
    {
        $result = $this->compactor->compact(['traces' => [
            $this->trace(['trace_id' => 'T1']),
            $this->trace(['trace_id' => 'T2']),
            $this->trace(['trace_id' => 'T3']),
        ]]);

        $metrics = $result['compaction_metrics'];
        $this->assertSame(3, $metrics['input_trace_count']);
        $this->assertSame($result['lesson_count'], $metrics['output_lesson_count']);
    }

    // AC: conflict_index includes all five isolation reasons
    public function test_conflict_index_groups_by_kept_separate_reason(): void
    {
        $compactor = new AtlasExternalBrainDecisionLedgerCompactor();
        $result = $compactor->compact(['traces' => [
            ['trace_id' => 'c1', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x', 'is_contradictory' => true],
            ['trace_id' => 'c2', 'causes' => ['c'], 'outcome' => 'd', 'scope' => 'x', 'is_contradictory' => true],
            ['trace_id' => 'u1', 'causes' => ['e'], 'outcome' => 'f', 'scope' => 'x', 'uncertainty' => 'high'],
            ['trace_id' => 's1', 'causes' => ['g'], 'outcome' => 'h', 'scope' => 'x', 'is_stale' => true],
            ['trace_id' => 'e1', 'causes' => ['i'], 'outcome' => 'j', 'scope' => 'x', 'is_expired' => true],
            ['trace_id' => 'ir1', 'causes' => ['k'], 'outcome' => 'l', 'scope' => 'x', 'reversibility' => 'irreversible'],
        ]]);

        $this->assertIsArray($result['conflict_index']);
        $this->assertSame(2, $result['conflict_index']['contradictory']);
        $this->assertSame(1, $result['conflict_index']['high_uncertainty']);
        $this->assertSame(1, $result['conflict_index']['stale']);
        $this->assertSame(1, $result['conflict_index']['expired']);
        $this->assertSame(1, $result['conflict_index']['irreversible']);
    }

    // AC: stale_or_expired_summary
    public function test_stale_or_expired_summary(): void
    {
        $compactor = new AtlasExternalBrainDecisionLedgerCompactor();
        $result = $compactor->compact(['traces' => [
            ['trace_id' => 's1', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x', 'is_stale' => true],
            ['trace_id' => 's2', 'causes' => ['c'], 'outcome' => 'd', 'scope' => 'x', 'is_stale' => true],
            ['trace_id' => 'e1', 'causes' => ['e'], 'outcome' => 'f', 'scope' => 'x', 'is_expired' => true],
        ]]);

        $summary = $result['stale_or_expired_summary'];
        $this->assertSame(2, $summary['stale_count']);
        $this->assertSame(1, $summary['expired_count']);
        $this->assertSame(3, $summary['total_quarantined']);
        $this->assertTrue($summary['needs_revalidation']);
    }

    // AC: stale_or_expired_summary empty when no stale or expired
    public function test_stale_or_expired_summary_empty_when_clean(): void
    {
        $compactor = new AtlasExternalBrainDecisionLedgerCompactor();
        $result = $compactor->compact(['traces' => [
            ['trace_id' => 'c1', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x', 'is_contradictory' => true],
            ['trace_id' => 'u1', 'causes' => ['c'], 'outcome' => 'd', 'scope' => 'x', 'uncertainty' => 'high'],
        ]]);

        $summary = $result['stale_or_expired_summary'];
        $this->assertSame(0, $summary['stale_count']);
        $this->assertSame(0, $summary['expired_count']);
        $this->assertSame(0, $summary['total_quarantined']);
        $this->assertFalse($summary['needs_revalidation']);
    }

    // AC: conflict_index empty when nothing kept separate
    public function test_conflict_index_empty_when_all_merge(): void
    {
        $compactor = new AtlasExternalBrainDecisionLedgerCompactor();
        $result = $compactor->compact(['traces' => [
            ['trace_id' => 't1', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x'],
            ['trace_id' => 't2', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x'],
            ['trace_id' => 't3', 'causes' => ['a'], 'outcome' => 'b', 'scope' => 'x'],
        ]]);

        $this->assertSame([], $result['conflict_index']);
    }
}
