<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPatternTransferEvaluator;
use Tests\TestCase;

final class AtlasExternalBrainPatternTransferEvaluatorTest extends TestCase
{
    private function evaluator(): AtlasExternalBrainPatternTransferEvaluator
    {
        return new AtlasExternalBrainPatternTransferEvaluator();
    }

    private function outcome(
        string $class,
        float  $positiveRatio = 0.80,
        float  $giveBackRate  = 0.10,
        float  $duplicateRate = 0.05,
        float  $weakAcceptanceRate = 0.10,
        int    $evidenceCount = 5,
    ): array {
        return [
            'task_class'          => $class,
            'positive_ratio'      => $positiveRatio,
            'give_back_rate'      => $giveBackRate,
            'duplicate_rate'      => $duplicateRate,
            'weak_acceptance_rate' => $weakAcceptanceRate,
            'evidence_count'      => $evidenceCount,
        ];
    }

    private function pattern(string $id, array $outcomes, array $targets = ['class-b']): array
    {
        return [
            'pattern_id'          => $id,
            'description'         => "Pattern {$id}",
            'source_task_classes' => ['class-a'],
            'target_task_classes' => $targets,
            'cross_class_outcomes' => $outcomes,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->evaluator()->evaluate([]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::SCHEMA, $result['schema']);
    }

    public function test_output_has_schema_and_results(): void
    {
        $result = $this->evaluator()->evaluate([]);

        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('results', $result);
    }

    public function test_each_result_has_required_keys(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b')])],
        ]);

        foreach ($result['results'] as $r) {
            foreach (['pattern_id', 'transfer_decision', 'source_task_classes',
                      'target_task_classes', 'evidence_counts', 'injection_rule'] as $k) {
                $this->assertArrayHasKey($k, $r);
            }
        }
    }

    public function test_empty_patterns_yields_empty_results(): void
    {
        $result = $this->evaluator()->evaluate(['patterns' => []]);

        $this->assertSame([], $result['results']);
    }

    // ── transferable ──────────────────────────────────────────────────────────

    public function test_transferable_when_all_outcomes_above_positive_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $result['results'][0]['transfer_decision']);
    }

    public function test_transferable_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80)])],
        ]);

        $this->assertStringContainsString('inject into all target_task_classes', $result['results'][0]['injection_rule']);
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function test_retire_when_give_back_rate_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_when_duplicate_rate_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.25)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_when_weak_acceptance_above_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.10, 0.10, 0.45)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    public function test_retire_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
        ]);

        $this->assertStringContainsString('do not inject', $result['results'][0]['injection_rule']);
    }

    public function test_retire_takes_priority_over_other_decisions(): void
    {
        // Give back in one class but low evidence in another — retire must win.
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80, 0.35, 0.05, 0.05, 1), // harmful + low evidence
            ])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    // ── needs_more_evidence ───────────────────────────────────────────────────

    public function test_needs_more_evidence_when_evidence_count_below_minimum(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.10, 2)])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    public function test_needs_more_evidence_injection_rule_mentions_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.10, 2)])],
        ]);

        $this->assertStringContainsString('withhold', $result['results'][0]['injection_rule']);
    }

    public function test_needs_more_evidence_when_no_outcomes(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    // ── local_only ────────────────────────────────────────────────────────────

    public function test_local_only_when_one_target_class_below_positive_threshold(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80), // good
                $this->outcome('class-c', 0.50), // below 0.70 threshold
            ], ['class-b', 'class-c'])],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_LOCAL_ONLY, $result['results'][0]['transfer_decision']);
    }

    public function test_local_only_injection_rule(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', 0.80),
                $this->outcome('class-c', 0.50),
            ], ['class-b', 'class-c'])],
        ]);

        $this->assertStringContainsString('inject only into source_task_classes', $result['results'][0]['injection_rule']);
    }

    // ── evidence_counts ───────────────────────────────────────────────────────

    public function test_evidence_counts_indexed_by_task_class(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [$this->pattern('p1', [
                $this->outcome('class-b', evidenceCount: 7),
                $this->outcome('class-c', evidenceCount: 4),
            ], ['class-b', 'class-c'])],
        ]);

        $counts = $result['results'][0]['evidence_counts'];
        $this->assertSame(7, $counts['class-b']);
        $this->assertSame(4, $counts['class-c']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_min_evidence_count_respected(): void
    {
        // Default min is 3; override to 10 — evidence_count=5 should now be insufficient.
        $result = $this->evaluator()->evaluate([
            'patterns'   => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.05, 0.05, 0.05, 5)])],
            'thresholds' => ['min_evidence_count' => 10],
        ]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $result['results'][0]['transfer_decision']);
    }

    public function test_custom_give_back_threshold_respected(): void
    {
        // Raise give_back_threshold to 0.50 — rate of 0.35 should no longer trigger retire.
        $result = $this->evaluator()->evaluate([
            'patterns'   => [$this->pattern('p1', [$this->outcome('class-b', 0.80, 0.35)])],
            'thresholds' => ['give_back_threshold' => 0.50],
        ]);

        $this->assertNotSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_RETIRE, $result['results'][0]['transfer_decision']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'patterns' => [$this->pattern('p1', [$this->outcome('class-b')])],
        ];

        $this->assertSame($this->evaluator()->evaluate($input), $this->evaluator()->evaluate($input));
    }

    // ── AC2: new rejection reasons ────────────────────────────────────────────

    private function transferablePattern(string $id = 'P1'): array
    {
        return [
            'pattern_id'         => $id,
            'source_area'        => 'autonomy',
            'destination_area'   => 'evidence',
            'destination_fit_score' => 0.80,
            'adaptation_risk'    => 0.10,
            'rollback_path'      => 'revert_commit',
            'cross_class_outcomes' => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ];
    }

    public function test_no_source_evidence_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['source_evidence_count' => 0]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_NO_SOURCE_EVIDENCE, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_missing_behaviour_contract_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['has_behaviour_contract' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_MISSING_BEHAVIOUR_CONTRACT, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_low_destination_fit_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['destination_fit_score' => 0.40]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_LOW_DESTINATION_FIT, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_high_adaptation_risk_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['adaptation_risk' => 0.80]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_HIGH_ADAPTATION_RISK, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_multiple_rejection_reasons_combined(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id'           => 'multi',
            'source_evidence_count' => 0,
            'has_behaviour_contract' => false,
            'destination_fit_score'  => 0.20,
        ]]]);

        $this->assertCount(4, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    // ── AC3: accepted_transfers fields ────────────────────────────────────────

    public function test_direct_safe_transfer_goes_to_accepted_transfers(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [$this->transferablePattern('PAT')]]);

        $this->assertCount(1, $r['accepted_transfers']);
        $at = $r['accepted_transfers'][0];
        $this->assertSame('PAT', $at['pattern_id']);
        $this->assertSame('autonomy', $at['source_area']);
        $this->assertSame('evidence', $at['destination_area']);
        $this->assertArrayHasKey('transfer_score', $at);
        $this->assertArrayHasKey('required_adaptations', $at);
        $this->assertArrayHasKey('proof_of_source_success', $at);
    }

    public function test_transfer_score_computed_from_fit_and_risk(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id'           => 'X',
            'destination_fit_score' => 0.80,
            'adaptation_risk'       => 0.20,
            'rollback_path'        => 'revert_commit',
            'cross_class_outcomes'  => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ]]]);

        // 0.80 * (1 - 0.20) = 0.64
        $this->assertSame(0.64, $r['accepted_transfers'][0]['transfer_score']);
    }

    public function test_adaptation_required_transfer_accepted_with_required_adaptations(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern(),
            ['required_adaptations' => ['adjust-threshold', 'remap-context']],
        )]]);

        $this->assertCount(1, $r['accepted_transfers']);
        $this->assertSame(['adjust-threshold', 'remap-context'], $r['accepted_transfers'][0]['required_adaptations']);
    }

    // ── AC4: deterministic ranking ────────────────────────────────────────────

    public function test_accepted_transfers_sorted_by_transfer_score_descending(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern('LOW'),  ['destination_fit_score' => 0.61, 'adaptation_risk' => 0.0]),
            array_merge($this->transferablePattern('HIGH'), ['destination_fit_score' => 0.95, 'adaptation_risk' => 0.0]),
        ]]);

        $this->assertSame('HIGH', $r['accepted_transfers'][0]['pattern_id']);
        $this->assertSame('LOW',  $r['accepted_transfers'][1]['pattern_id']);
    }

    // ── new output keys always present ────────────────────────────────────────

    public function test_accepted_and_rejected_transfers_keys_always_present(): void
    {
        $r = $this->evaluator()->evaluate([]);

        $this->assertArrayHasKey('accepted_transfers', $r);
        $this->assertArrayHasKey('rejected_transfers', $r);
        $this->assertSame([], $r['accepted_transfers']);
        $this->assertSame([], $r['rejected_transfers']);
    }

    // ── AC2/AC3: blind copy / hype-only / dependency-heavy rejections ──────────

    public function test_blind_copy_pattern_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['is_blind_copy' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_BLIND_COPY, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_hype_only_pattern_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['is_hype_only' => true]),
        ]]);

        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_HYPE_ONLY, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_dependency_heavy_pattern_rejects(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), ['is_dependency_heavy' => true]),
        ]]);

        $this->assertContains(AtlasExternalBrainPatternTransferEvaluator::REJECTION_DEPENDENCY_HEAVY, $r['rejected_transfers'][0]['rejection_reasons']);
    }

    public function test_false_flags_do_not_reject(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern(), [
                'is_blind_copy' => false,
                'is_hype_only' => false,
                'is_dependency_heavy' => false,
            ]),
        ]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $r['results'][0]['transfer_decision']);
    }

    // ── AC1: expected_structural_leverage blends into transfer_score ───────────

    public function test_expected_structural_leverage_blends_into_transfer_score(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id'                    => 'X',
            'destination_fit_score'         => 0.80,
            'adaptation_risk'               => 0.20,
            'expected_structural_leverage'  => 0.90,
            'rollback_path'                 => 'revert_commit',
            'cross_class_outcomes'          => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ]]]);

        // fit_risk = 0.80 * (1 - 0.20) = 0.64; blended = (0.64 + 0.90) / 2 = 0.77
        $this->assertSame(0.77, $r['accepted_transfers'][0]['transfer_score']);
    }

    public function test_transfer_score_unchanged_when_structural_leverage_absent(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id'             => 'X',
            'destination_fit_score'  => 0.80,
            'adaptation_risk'        => 0.20,
            'rollback_path'          => 'revert_commit',
            'cross_class_outcomes'   => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ]]]);

        $this->assertSame(0.64, $r['accepted_transfers'][0]['transfer_score']);
    }

    // ── AC4: adaptation_requirements / first_task_spec_hint ────────────────────

    public function test_results_emit_adaptation_requirements_and_first_task_spec_hint(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern('PAT'), ['required_adaptations' => ['adjust-threshold']]),
        ]]);

        $entry = $r['results'][0];
        $this->assertArrayHasKey('adaptation_requirements', $entry);
        $this->assertSame(['adjust-threshold'], $entry['adaptation_requirements']);
        $this->assertSame('implement_adaptation:PAT->evidence:adjust-threshold', $entry['first_task_spec_hint']);
    }

    public function test_first_task_spec_hint_for_rejected_pattern_references_rejection_reason(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [
            array_merge($this->transferablePattern('PAT'), ['is_blind_copy' => true]),
        ]]);

        $this->assertSame('address_rejection:PAT:blind_copy', $r['results'][0]['first_task_spec_hint']);
    }

    public function test_first_task_spec_hint_for_needs_more_evidence(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id'           => 'PAT',
            'cross_class_outcomes' => [
                ['task_class' => 'A', 'evidence_count' => 1, 'positive_ratio' => 0.90],
            ],
        ]]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $r['results'][0]['transfer_decision']);
        $this->assertSame('collect_more_evidence:PAT', $r['results'][0]['first_task_spec_hint']);
    }

    // ── destination_safety_floor / negative_outcome_summary ─────────────────────

    public function test_accepted_transfer_includes_destination_safety_floor(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [$this->transferablePattern('PAT')]]);

        $at = $r['accepted_transfers'][0];
        $this->assertArrayHasKey('destination_safety_floor', $at);
        $this->assertSame(0.30, $at['destination_safety_floor']['give_back_threshold']);
        $this->assertSame(0.20, $at['destination_safety_floor']['duplicate_rate_threshold']);
        $this->assertSame(0.40, $at['destination_safety_floor']['weak_acceptance_threshold']);
    }

    public function test_accepted_transfer_includes_negative_outcome_summary(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('PAT'),
            ['cross_class_outcomes' => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90, 'give_back_rate' => 0.12, 'duplicate_rate' => 0.05, 'weak_acceptance_rate' => 0.08],
            ]],
        )]]);

        $summary = $r['accepted_transfers'][0]['negative_outcome_summary'];
        $this->assertSame(0.12, $summary['max_give_back_rate']);
        $this->assertSame(0.05, $summary['max_duplicate_rate']);
        $this->assertSame(0.08, $summary['max_weak_acceptance_rate']);
    }

    public function test_destination_safety_floor_reflects_custom_thresholds(): void
    {
        $r = $this->evaluator()->evaluate([
            'patterns'   => [$this->transferablePattern('PAT')],
            'thresholds' => ['give_back_threshold' => 0.50],
        ]);

        $this->assertSame(0.50, $r['accepted_transfers'][0]['destination_safety_floor']['give_back_threshold']);
    }

    // ── AC: rollback/guardrail floor ───────────────────────────────────────────

    public function test_high_source_success_but_missing_destination_fit_or_high_risk_is_rejected(): void
    {
        $missingFit = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('MF'),
            ['destination_fit_score' => 0.20],
        )]]);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $missingFit['results'][0]['transfer_decision']);

        $highRisk = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('HR'),
            ['adaptation_risk' => 0.90],
        )]]);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $highRisk['results'][0]['transfer_decision']);
    }

    public function test_pattern_without_rollback_or_guardrail_is_rejected_despite_good_fit(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id' => 'NO-ROLLBACK',
            'destination_fit_score' => 0.90,
            'adaptation_risk' => 0.05,
            'cross_class_outcomes' => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ]]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $r['results'][0]['transfer_decision']);
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_MISSING_ROLLBACK_OR_GUARDRAIL,
            $r['rejected_transfers'][0]['rejection_reasons'],
        );
    }

    public function test_guardrail_hints_alone_satisfy_rollback_requirement(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [[
            'pattern_id' => 'GUARDRAIL',
            'destination_fit_score' => 0.90,
            'adaptation_risk' => 0.05,
            'guardrail_hints' => ['run under sandbox worktree before merge'],
            'cross_class_outcomes' => [
                ['task_class' => 'A', 'evidence_count' => 5, 'positive_ratio' => 0.90],
            ],
        ]]]);

        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $r['results'][0]['transfer_decision']);
    }

    public function test_accepted_transfer_output_includes_task_spec_hint_and_injection_rule_matching_decision(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [$this->transferablePattern('MATCH')]]);

        $result = $r['results'][0];
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $result['transfer_decision']);
        $this->assertStringContainsString('MATCH', $result['first_task_spec_hint']);
        $this->assertSame('inject into all target_task_classes runbooks', $result['injection_rule']);
    }

    // ── AC: hype patterns without Atlas fit are rejected ────────────────────────

    public function test_hype_only_pattern_without_atlas_fit_is_rejected(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern(),
            ['is_hype_only' => true, 'destination_fit_score' => 0.10],
        )]]);

        $this->assertCount(0, $r['accepted_transfers']);
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_HYPE_ONLY,
            $r['rejected_transfers'][0]['rejection_reasons'],
        );
    }

    // ── AC: simple high-fit patterns become transfer_candidate with lane + proof floor ──

    public function test_simple_high_fit_pattern_becomes_transfer_candidate_with_lane_and_proof_floor(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [$this->transferablePattern('SIMPLE')]]);

        $accepted = $r['accepted_transfers'][0];
        $this->assertTrue($accepted['transfer_candidate']);
        $this->assertSame('direct_transfer_lane', $accepted['implementation_lane']);
        $this->assertArrayHasKey('proof_floor', $accepted);
        $this->assertArrayHasKey('min_evidence_count', $accepted['proof_floor']);
        $this->assertArrayHasKey('positive_outcomes_threshold', $accepted['proof_floor']);
    }

    public function test_pattern_requiring_adaptation_gets_adapted_transfer_lane(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('ADAPT'),
            ['required_adaptations' => ['rename_symbol']],
        )]]);

        $accepted = $r['accepted_transfers'][0];
        $this->assertSame('adapted_transfer_lane', $accepted['implementation_lane']);
    }

    public function test_proof_floor_reflects_custom_thresholds(): void
    {
        $r = $this->evaluator()->evaluate([
            'patterns' => [array_merge($this->transferablePattern('CUSTOM'), [
                'cross_class_outcomes' => [
                    ['task_class' => 'A', 'evidence_count' => 10, 'positive_ratio' => 0.90],
                ],
            ])],
            'thresholds' => ['min_evidence_count' => 7, 'positive_outcomes_threshold' => 0.85],
        ]);

        $accepted = $r['accepted_transfers'][0];
        $this->assertSame(7, $accepted['proof_floor']['min_evidence_count']);
        $this->assertSame(0.85, $accepted['proof_floor']['positive_outcomes_threshold']);
    }

    // ── AC: over-engineered patterns are downgraded even when popular externally ──

    public function test_over_engineered_pattern_is_rejected_even_when_popular_externally(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('OVERENG'),
            ['is_over_engineered' => true, 'is_popular_externally' => true],
        )]]);

        $this->assertCount(0, $r['accepted_transfers']);
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_OVER_ENGINEERED,
            $r['rejected_transfers'][0]['rejection_reasons'],
        );
    }

    public function test_non_over_engineered_pattern_still_transfers_despite_popularity_flag(): void
    {
        $r = $this->evaluator()->evaluate(['patterns' => [array_merge(
            $this->transferablePattern('SANE'),
            ['is_over_engineered' => false, 'is_popular_externally' => true],
        )]]);

        $this->assertCount(1, $r['accepted_transfers']);
    }
}
