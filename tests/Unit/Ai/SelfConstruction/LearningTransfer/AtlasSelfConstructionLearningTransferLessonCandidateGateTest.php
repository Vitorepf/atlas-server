<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferLessonCandidateGate;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferLessonCandidateGateTest extends TestCase
{
    private function provenObs(string $outcome = 'failure'): array
    {
        return ['outcome' => $outcome, 'evidence_refs' => ['receipt:'.bin2hex(random_bytes(2))]];
    }

    private function unverifiedObs(string $outcome = 'failure'): array
    {
        return ['outcome' => $outcome, 'evidence_refs' => []];
    }

    public function test_repeated_proven_failure_is_admitted(): void
    {
        $candidate = [
            'class' => 'duplicate_capability',
            'observations' => [
                $this->provenObs(),
                $this->provenObs(),
                $this->provenObs(),
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_ADMIT, $verdict['decision']);
        $this->assertContains('repetition_and_proof_thresholds_met', $verdict['reasons']);
        $this->assertSame([], $verdict['required_next_evidence']);
    }

    public function test_one_off_observation_is_held(): void
    {
        $candidate = [
            'class' => 'scope_gap',
            'observations' => [$this->provenObs()],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_HOLD, $verdict['decision']);
        $this->assertStringContainsString('below_repetition_threshold:1<3', $verdict['reasons'][0]);
        $this->assertContains('accumulate_more_independent_observations', $verdict['required_next_evidence']);
    }

    public function test_unverified_claim_is_rejected(): void
    {
        $candidate = [
            'class' => 'forbidden_target',
            'observations' => [
                $this->unverifiedObs(),
                $this->unverifiedObs(),
                $this->unverifiedObs(),
                $this->unverifiedObs(),
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_REJECT, $verdict['decision']);
        $this->assertStringContainsString('below_proof_threshold:0<2', $verdict['reasons'][0]);
    }

    public function test_conflicting_outcomes_are_rejected(): void
    {
        $candidate = [
            'class' => 'stale_context',
            'observations' => [
                $this->provenObs('failure'),
                $this->provenObs('failure'),
                $this->provenObs('success'),
                $this->provenObs('success'),
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_REJECT, $verdict['decision']);
        $this->assertStringContainsString('conflicting_outcomes', $verdict['reasons'][0]);
        $this->assertNotEmpty($verdict['required_next_evidence'], 'conflicting reject must supply required_next_evidence');
        $this->assertContains('resolve_outcome_conflict_with_specific_disambiguator', $verdict['required_next_evidence']);

        // Deterministic: same input → same JSON
        $svc = new AtlasSelfConstructionLearningTransferLessonCandidateGate;
        $this->assertSame(json_encode($svc->admit($candidate)), json_encode($svc->admit($candidate)));
    }

    public function test_single_source_repeated_observations_do_not_satisfy_source_diversity(): void
    {
        $candidate = [
            'class' => 'known_gap',
            'observations' => [
                ['outcome' => 'failure', 'source' => 'worker-A', 'evidence_refs' => ['r1']],
                ['outcome' => 'failure', 'source' => 'worker-A', 'evidence_refs' => ['r2']],
                ['outcome' => 'failure', 'source' => 'worker-A', 'evidence_refs' => ['r3']],
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_HOLD, $verdict['decision']);
        $this->assertStringContainsString('below_repetition_threshold:1<3', $verdict['reasons'][0]);
        $this->assertContains('accumulate_more_independent_observations', $verdict['required_next_evidence']);
    }

    public function test_negative_result_without_disambiguator_forces_hold(): void
    {
        $candidate = [
            'class' => 'ambiguous_pattern',
            'observations' => [
                ['outcome' => 'negative_result', 'source' => 'src-a', 'evidence_refs' => ['r1']],
                ['outcome' => 'negative_result', 'source' => 'src-b', 'evidence_refs' => ['r2']],
                ['outcome' => 'negative_result', 'source' => 'src-c', 'evidence_refs' => ['r3']],
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_HOLD, $verdict['decision']);
        $this->assertContains('negative_result_requires_disambiguator', $verdict['reasons']);
        $this->assertContains('attach_disambiguator_field_to_resolve_negative_result', $verdict['required_next_evidence']);

        // With disambiguator it should admit
        $candidate['disambiguator'] = 'always_fails_on_empty_scope';
        $with = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_ADMIT, $with['decision']);
    }

    public function test_admitted_lesson_includes_evidence_refs_from_proven_observations(): void
    {
        $candidate = [
            'class' => 'scoped_commit_violation',
            'observations' => [
                ['outcome' => 'failure', 'source' => 'src-a', 'evidence_refs' => ['evh-aaa']],
                ['outcome' => 'failure', 'source' => 'src-b', 'evidence_refs' => ['evh-bbb']],
                ['outcome' => 'failure', 'source' => 'src-c', 'evidence_refs' => ['evh-ccc']],
            ],
        ];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_ADMIT, $verdict['decision']);
        $this->assertCount(3, $verdict['evidence_refs']);
        $this->assertContains('evh-aaa', $verdict['evidence_refs']);
        $this->assertContains('evh-bbb', $verdict['evidence_refs']);
        $this->assertContains('evh-ccc', $verdict['evidence_refs']);
    }

    public function test_broad_narrative_without_class_is_rejected(): void
    {
        $candidate = ['observations' => [$this->provenObs(), $this->provenObs(), $this->provenObs()]];

        $verdict = (new AtlasSelfConstructionLearningTransferLessonCandidateGate)->admit($candidate);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('broad_narrative_no_class', $verdict['reasons']);
    }

    public function test_threshold_overrides_are_honored_and_deterministic(): void
    {
        $candidate = [
            'class' => 'missing_dependency',
            'observations' => [$this->provenObs(), $this->provenObs()],
        ];
        // With min_repetitions=2 and min_proven=2, this candidate IS admittable.
        $svc = new AtlasSelfConstructionLearningTransferLessonCandidateGate;
        $verdict = $svc->admit($candidate, ['min_repetitions' => 2, 'min_proven' => 2]);
        $this->assertSame(AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_ADMIT, $verdict['decision']);

        // Deterministic — repeated calls produce identical JSON.
        $this->assertSame(json_encode($verdict), json_encode($svc->admit($candidate, ['min_repetitions' => 2, 'min_proven' => 2])));
    }
}
