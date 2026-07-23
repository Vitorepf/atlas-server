<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPatternTransferEvaluator;
use Tests\TestCase;

final class AtlasExternalBrainPatternTransferEvaluatorTest extends TestCase
{
    private function evaluator(): AtlasExternalBrainPatternTransferEvaluator
    {
        return new AtlasExternalBrainPatternTransferEvaluator;
    }

    public function test_high_fit_evidence_backed_locally_implementable_pattern_is_accepted(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [
                [
                    'pattern_id' => 'pattern-a',
                    'source_area' => 'domain-x',
                    'destination_area' => 'domain-y',
                    'destination_fit_score' => 0.9,
                    'adaptation_risk' => 0.1,
                    'has_behaviour_contract' => true,
                    'source_evidence_count' => 5,
                    'proof_of_source_success' => 'commit abc123',
                    'required_adaptations' => ['rename_class'],
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-y', 'evidence_count' => 5, 'positive_ratio' => 0.9],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['accepted_transfers']);
        $accepted = $result['accepted_transfers'][0];
        $this->assertSame('pattern-a', $accepted['pattern_id']);
        $this->assertGreaterThan(0.0, $accepted['transfer_score']);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_TRANSFERABLE, $result['results'][0]['transfer_decision']);
    }

    public function test_weak_evidence_no_falsification_no_local_path_and_hype_only_are_rejected_or_downgraded(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [
                [
                    'pattern_id' => 'weak-evidence',
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-y', 'evidence_count' => 1, 'positive_ratio' => 0.9],
                    ],
                ],
                [
                    'pattern_id' => 'no-falsification',
                    'source_evidence_count' => 0,
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-y', 'evidence_count' => 5, 'positive_ratio' => 0.9],
                    ],
                ],
                [
                    'pattern_id' => 'no-behaviour-contract',
                    'has_behaviour_contract' => false,
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-y', 'evidence_count' => 5, 'positive_ratio' => 0.9],
                    ],
                ],
                [
                    'pattern_id' => 'hype-only',
                    'is_hype_only' => true,
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-y', 'evidence_count' => 5, 'positive_ratio' => 0.9],
                    ],
                ],
            ],
        ]);

        $decisions = array_column($result['results'], 'transfer_decision', 'pattern_id');
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_NEEDS_MORE_EVIDENCE, $decisions['weak-evidence']);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $decisions['no-falsification']);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $decisions['no-behaviour-contract']);
        $this->assertSame(AtlasExternalBrainPatternTransferEvaluator::DECISION_REJECTED, $decisions['hype-only']);

        $rejectedByPattern = [];
        foreach ($result['rejected_transfers'] as $r) {
            $rejectedByPattern[$r['pattern_id']] = $r;
        }
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_NO_SOURCE_EVIDENCE,
            $rejectedByPattern['no-falsification']['rejection_reasons'],
        );
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_MISSING_BEHAVIOUR_CONTRACT,
            $rejectedByPattern['no-behaviour-contract']['rejection_reasons'],
        );
        $this->assertContains(
            AtlasExternalBrainPatternTransferEvaluator::REJECTION_HYPE_ONLY,
            $rejectedByPattern['hype-only']['rejection_reasons'],
        );
    }

    public function test_output_includes_deterministic_transfer_score_decision_reasons_and_required_adaptations(): void
    {
        $result = $this->evaluator()->evaluate([
            'patterns' => [
                [
                    'pattern_id' => 'pattern-b',
                    'destination_fit_score' => 0.8,
                    'adaptation_risk' => 0.2,
                    'required_adaptations' => ['adjust_signature'],
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-z', 'evidence_count' => 4, 'positive_ratio' => 0.8],
                    ],
                ],
            ],
        ]);

        $first = $this->evaluator()->evaluate([
            'patterns' => [
                [
                    'pattern_id' => 'pattern-b',
                    'destination_fit_score' => 0.8,
                    'adaptation_risk' => 0.2,
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-z', 'evidence_count' => 4, 'positive_ratio' => 0.8],
                    ],
                ],
            ],
        ]);
        $second = $this->evaluator()->evaluate([
            'patterns' => [
                [
                    'pattern_id' => 'pattern-b',
                    'destination_fit_score' => 0.8,
                    'adaptation_risk' => 0.2,
                    'cross_class_outcomes' => [
                        ['task_class' => 'domain-z', 'evidence_count' => 4, 'positive_ratio' => 0.8],
                    ],
                ],
            ],
        ]);
        $this->assertSame($first['accepted_transfers'][0]['transfer_score'], $second['accepted_transfers'][0]['transfer_score']);

        $entry = $result['results'][0];
        $this->assertArrayHasKey('transfer_decision', $entry);
        $this->assertArrayHasKey('adaptation_requirements', $entry);
        $this->assertSame(['adjust_signature'], $entry['adaptation_requirements']);
        $this->assertArrayHasKey('first_task_spec_hint', $entry);
    }
}
