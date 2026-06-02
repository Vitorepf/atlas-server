<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8LocalDistillationTaskClassMiner;
use PHPUnit\Framework\TestCase;

final class L8LocalDistillationTaskClassMinerTest extends TestCase
{
    private L8LocalDistillationTaskClassMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new L8LocalDistillationTaskClassMiner();
    }

    public function testRecurringClassReturnsAllRequiredFields(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'doc reconcile', 'quality' => 0.92, 'privacy_class' => 'normal', 'evidence_refs' => ['run:a']],
                ['task_class' => 'doc reconcile', 'quality' => 0.88, 'privacy_class' => 'normal', 'evidence_refs' => ['run:b']],
                ['task_class' => 'doc reconcile', 'quality' => 0.95, 'privacy_class' => 'normal', 'evidence_refs' => ['run:c']],
            ],
        ]);

        $this->assertSame('atlas.aaeos.l8.local_distillation.task_class_candidates.v1', $result['schema_version']);
        $this->assertCount(1, $result['candidates']);

        $candidate = $result['candidates'][0];

        $this->assertSame('doc_reconcile', $candidate['task_class_id']);
        $this->assertSame(3, $candidate['recurrence_count']);
        $this->assertEqualsWithDelta(0.88, $candidate['quality_floor'], 0.0001);
        $this->assertSame('normal', $candidate['privacy_class']);
        $this->assertFalse($candidate['local_first_required']);
        $this->assertSame(['run:a', 'run:b', 'run:c'], $candidate['evidence_refs']);
    }

    public function testRecurrenceBelowThresholdBlocksAndIsNotACandidate(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'rare_op', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'rare_op', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]);

        $this->assertSame([], $result['candidates']);
        $this->assertCount(1, $result['blocked_task_classes']);

        $blocked = $result['blocked_task_classes'][0];

        $this->assertSame('rare_op', $blocked['task_class_id']);
        $this->assertSame(2, $blocked['recurrence_count']);
        $this->assertSame('recurrence_below_threshold', $blocked['block_reason']);
        $this->assertSame(3, $result['recurrence_threshold']);
    }

    public function testSensitiveClassMarksLocalFirstRequired(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'secret_audit', 'quality' => 0.8, 'privacy_class' => 'sensitive'],
                ['task_class' => 'secret_audit', 'quality' => 0.8, 'privacy_class' => 'sensitive'],
                ['task_class' => 'secret_audit', 'quality' => 0.8, 'privacy_class' => 'sensitive'],
            ],
        ]);

        $this->assertCount(1, $result['candidates']);
        $this->assertSame('sensitive', $result['candidates'][0]['privacy_class']);
        $this->assertTrue($result['candidates'][0]['local_first_required']);
    }

    public function testSecretClassMarksLocalFirstRequired(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'key_rotation', 'quality' => 0.7, 'privacy_class' => 'secret'],
                ['task_class' => 'key_rotation', 'quality' => 0.7, 'privacy_class' => 'secret'],
                ['task_class' => 'key_rotation', 'quality' => 0.7, 'privacy_class' => 'secret'],
            ],
        ]);

        $this->assertCount(1, $result['candidates']);
        $this->assertSame('secret', $result['candidates'][0]['privacy_class']);
        $this->assertTrue($result['candidates'][0]['local_first_required']);
    }

    public function testNoTrainingOccurs(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]);

        $this->assertFalse($result['training_performed']);
    }

    public function testQualityFloorIsWorstCaseAndClampedToOne(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'index_code', 'quality' => 1.4, 'privacy_class' => 'normal'],
                ['task_class' => 'index_code', 'quality' => 0.55, 'privacy_class' => 'normal'],
                ['task_class' => 'index_code', 'quality' => 0.73, 'privacy_class' => 'normal'],
                ['task_class' => 'index_code', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]);

        $candidate = $result['candidates'][0];

        // Worst-case sample (0.55) is the floor; the 1.4 input is clamped to 1.0
        // and therefore never wins the minimum, never exceeds the 0..1 bound.
        $this->assertEqualsWithDelta(0.55, $candidate['quality_floor'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $candidate['quality_floor']);
        $this->assertSame(4, $candidate['recurrence_count']);
    }

    public function testNonFiniteQualityIsNeutralisedToSafeFloorNotLeakedPastBound(): void
    {
        // A NaN quality is the incomparable-clamp trap: max(0.0, min(1.0, NaN))
        // does NOT clamp NaN (every comparison with NaN is false), so a naive
        // clamp leaks a non-finite value (or, by min/max argument order, the
        // optimistic 1.0) straight past the [0,1] bound — and as the FIRST sample
        // it would seed and lock the class floor, since no later real quality can
        // satisfy `real < NaN`. The floor must instead stay finite, inside the
        // 0..1 bound, and honest worst-case (0.0), never a perfect 1.0.
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'flaky_metric', 'quality' => NAN, 'privacy_class' => 'normal'],
                ['task_class' => 'flaky_metric', 'quality' => 0.4, 'privacy_class' => 'normal'],
                ['task_class' => 'flaky_metric', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]);

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];

        $this->assertTrue(is_finite($candidate['quality_floor']), 'quality_floor must be finite');
        $this->assertGreaterThanOrEqual(0.0, $candidate['quality_floor']);
        $this->assertLessThanOrEqual(1.0, $candidate['quality_floor']);
        $this->assertEqualsWithDelta(0.0, $candidate['quality_floor'], 0.0001);

        // The result must remain deterministic and JSON-serialisable: a NaN would
        // break === idempotency (NaN !== NaN) and json_encode determinism.
        $this->assertSame($result, $this->miner->mine([
            'samples' => [
                ['task_class' => 'flaky_metric', 'quality' => NAN, 'privacy_class' => 'normal'],
                ['task_class' => 'flaky_metric', 'quality' => 0.4, 'privacy_class' => 'normal'],
                ['task_class' => 'flaky_metric', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]));
    }

    public function testMostRestrictivePrivacyWinsAcrossSamples(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'threat_triage', 'quality' => 0.8, 'privacy_class' => 'normal'],
                ['task_class' => 'threat_triage', 'quality' => 0.8, 'privacy_class' => 'cyber'],
                ['task_class' => 'threat_triage', 'quality' => 0.8, 'privacy_class' => 'internal'],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame('cyber', $candidate['privacy_class']);
        $this->assertTrue($candidate['local_first_required']);
    }

    public function testPublicClassIsNotLocalFirstAndRefsDeduplicate(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'changelog', 'quality' => 0.6, 'privacy_class' => 'public', 'evidence_refs' => ['ref:1', 'ref:2']],
                ['task_class' => 'changelog', 'quality' => 0.6, 'privacy_class' => 'public', 'evidence_refs' => ['ref:2', 'ref:3']],
                ['task_class' => 'changelog', 'quality' => 0.6, 'privacy_class' => 'public', 'evidence_refs' => ['ref:3']],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame('public', $candidate['privacy_class']);
        $this->assertFalse($candidate['local_first_required']);
        $this->assertSame(['ref:1', 'ref:2', 'ref:3'], $candidate['evidence_refs']);
    }

    public function testGenuineRefsAreNeverContaminatedBySyntheticPlaceholder(): void
    {
        // A class carrying real governed refs must surface exactly those refs.
        // When only SOME samples omit evidence_refs, the synthetic
        // "task_class:<id>" provenance placeholder must NOT leak into the real
        // ref set (and must never be interleaved between real refs).
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal', 'evidence_refs' => ['real:1', 'real:2']],
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal'], // no refs on this sample
                ['task_class' => 'doc_reconcile', 'quality' => 0.9, 'privacy_class' => 'normal', 'evidence_refs' => ['real:3']],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame(['real:1', 'real:2', 'real:3'], $candidate['evidence_refs']);
        $this->assertNotContains('task_class:doc_reconcile', $candidate['evidence_refs']);
    }

    public function testSyntheticRefIsTheClassLevelFallbackOnlyWhenNoRealRefsExist(): void
    {
        // When NO sample of a class carries real refs, the candidate falls back to
        // a single synthetic provenance ref derived from the task class id.
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'index_code', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'index_code', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'index_code', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame(['task_class:index_code'], $candidate['evidence_refs']);
    }

    public function testMixedEvidenceSplitsCandidatesFromBlockedAndCountsClasses(): void
    {
        $result = $this->miner->mine([
            'samples' => [
                ['task_class' => 'alpha', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'alpha', 'quality' => 0.7, 'privacy_class' => 'normal'],
                ['task_class' => 'alpha', 'quality' => 0.8, 'privacy_class' => 'normal'],
                ['task_class' => 'beta', 'quality' => 0.5, 'privacy_class' => 'secret'],
            ],
        ]);

        $this->assertSame(2, $result['observed_class_count']);

        $this->assertCount(1, $result['candidates']);
        $this->assertSame('alpha', $result['candidates'][0]['task_class_id']);
        $this->assertEqualsWithDelta(0.7, $result['candidates'][0]['quality_floor'], 0.0001);

        $this->assertCount(1, $result['blocked_task_classes']);
        $this->assertSame('beta', $result['blocked_task_classes'][0]['task_class_id']);
        $this->assertSame(1, $result['blocked_task_classes'][0]['recurrence_count']);
    }

    public function testCandidatesAreSortedDeterministicallyAndStableAcrossCalls(): void
    {
        $evidence = [
            'samples' => [
                ['task_class' => 'zeta', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'zeta', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'zeta', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'alpha', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'alpha', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => 'alpha', 'quality' => 0.9, 'privacy_class' => 'normal'],
            ],
        ];

        $first = $this->miner->mine($evidence);
        $second = $this->miner->mine($evidence);

        $this->assertSame(['alpha', 'zeta'], array_column($first['candidates'], 'task_class_id'));
        $this->assertSame($first, $second);
    }

    public function testEmptyEvidenceYieldsNoCandidatesNoTrainingAndExposesThreshold(): void
    {
        $result = $this->miner->mine([]);

        $this->assertSame([], $result['candidates']);
        $this->assertSame([], $result['blocked_task_classes']);
        $this->assertSame(0, $result['observed_class_count']);
        $this->assertSame(3, $result['recurrence_threshold']);
        $this->assertFalse($result['training_performed']);
    }

    public function testAllDigitTaskClassIdStaysAStringInBothArms(): void
    {
        // A task class that slugs to an all-digit string ("123", "42") is the
        // classic numeric-string-key trap: PHP would coerce the grouped key back
        // to int and emit task_class_id as an int, breaking the string contract.
        $result = $this->miner->mine([
            'samples' => [
                // Recurring all-digit class -> candidate arm.
                ['task_class' => '123', 'quality' => 0.9, 'privacy_class' => 'normal'],
                ['task_class' => '123', 'quality' => 0.8, 'privacy_class' => 'normal'],
                ['task_class' => '123', 'quality' => 0.7, 'privacy_class' => 'normal'],
                // Single all-digit class -> blocked arm.
                ['task_class' => '42', 'quality' => 0.6, 'privacy_class' => 'normal'],
            ],
        ]);

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];
        $this->assertSame('123', $candidate['task_class_id']);
        $this->assertIsString($candidate['task_class_id']);

        $this->assertCount(1, $result['blocked_task_classes']);
        $blocked = $result['blocked_task_classes'][0];
        $this->assertSame('42', $blocked['task_class_id']);
        $this->assertIsString($blocked['task_class_id']);
    }
}
