<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosHttpPathClosureVerifier;
use PHPUnit\Framework\TestCase;

final class AaeosHttpPathClosureVerifierTest extends TestCase
{
    private AaeosHttpPathClosureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AaeosHttpPathClosureVerifier();
    }

    /**
     * Aceite #1: verify(envelopes) returns phases_seen[0..16], skipped_phases,
     * evidence_hashes and legacy_fallback_rate on a fully traversed path.
     */
    public function testFullPathReturnsPhasesSeenSkippedEvidenceAndFallbackRate(): void
    {
        $result = $this->verifier->verify($this->fullPathEnvelopes());

        $this->assertSame('atlas.aaeos.http_path_closure_verification.v1', $result['schema_version']);
        $this->assertSame(range(0, 16), $result['phases_seen']);
        $this->assertSame([], $result['skipped_phases']);
        $this->assertSame(
            [
                'sha256:'.str_repeat('a', 64),
                'sha256:'.str_repeat('b', 64),
            ],
            $result['evidence_hashes'],
        );
        $this->assertSame(0.0, $result['legacy_fallback_rate']);
        $this->assertTrue($result['closed']);
        $this->assertTrue($result['full_17_phase_path']);
        $this->assertSame([], $result['blockers']);
    }

    public function testPhasesSeenStayWithinZeroToSixteenAndDropUnknownPhaseIds(): void
    {
        $envelopes = $this->fullPathEnvelopes();
        $envelopes[] = ['phase_id' => 'P17', 'status' => 'ok'];
        $envelopes[] = ['phase_id' => 'P99', 'status' => 'ok'];
        $envelopes[] = ['phase_id' => 'garbage', 'status' => 'ok'];

        $result = $this->verifier->verify($envelopes);

        $this->assertSame(range(0, 16), $result['phases_seen']);
        foreach ($result['phases_seen'] as $phase) {
            $this->assertGreaterThanOrEqual(0, $phase);
            $this->assertLessThanOrEqual(16, $phase);
        }
    }

    /**
     * Aceite #2: a non-canonical skip populates skipped_phases and blocks closure.
     */
    public function testNonCanonicalSkippedPhaseBlocksClosure(): void
    {
        $envelopes = $this->corePathEnvelopes();
        $envelopes[6]['status'] = 'skipped'; // P6 routing skipped, no canonical marker

        $result = $this->verifier->verify($envelopes);

        $this->assertSame([6], $result['skipped_phases']);
        $this->assertFalse($result['closed']);
        $this->assertContains('phase_skipped:P6', $result['blockers']);
    }

    /**
     * Aceite #3: P10-P16 absent blocks when post_execution_phase_emit=true.
     */
    public function testPostExecutionPhasesAbsentBlockWhenEmitFlagOn(): void
    {
        $result = $this->verifier->verify(
            $this->corePathEnvelopes(),
            ['post_execution_phase_emit' => true],
        );

        $this->assertSame([10, 11, 12, 13, 14, 15, 16], $result['skipped_phases']);
        $this->assertTrue($result['post_execution_required']);
        $this->assertFalse($result['closed']);
        $this->assertContains('post_execution_phases_missing', $result['blockers']);
        $this->assertContains('phase_skipped:P16', $result['blockers']);
    }

    public function testCorePathClosesWhenPostExecutionEmitFlagOff(): void
    {
        $result = $this->verifier->verify(
            $this->corePathEnvelopes(),
            ['post_execution_phase_emit' => false],
        );

        $this->assertSame(range(0, 9), $result['phases_seen']);
        $this->assertSame([], $result['skipped_phases']);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], $result['required_phases']);
        $this->assertFalse($result['post_execution_required']);
        $this->assertTrue($result['closed']);
    }

    /**
     * Aceite #4: a fixture missing P3 fails (does not close).
     */
    public function testFixtureMissingP3Fails(): void
    {
        $envelopes = array_values(array_filter(
            $this->fullPathEnvelopes(),
            static fn (array $envelope): bool => ($envelope['phase_id'] ?? null) !== 'P3',
        ));

        $result = $this->verifier->verify($envelopes);

        $this->assertNotContains(3, $result['phases_seen']);
        $this->assertSame([3], $result['skipped_phases']);
        $this->assertFalse($result['closed']);
        $this->assertContains('phase_skipped:P3', $result['blockers']);
    }

    /**
     * DoD: incomplete trace is not a 17-phase path; a complete one is.
     */
    public function testIncompleteTraceIsNotFullSeventeenPhasePath(): void
    {
        $envelopes = array_values(array_filter(
            $this->fullPathEnvelopes(),
            static fn (array $envelope): bool => ($envelope['phase_id'] ?? null) !== 'P12',
        ));

        $result = $this->verifier->verify($envelopes);

        $this->assertFalse($result['full_17_phase_path']);
        $this->assertTrue($this->verifier->verify($this->fullPathEnvelopes())['full_17_phase_path']);
    }

    /**
     * DoD: the verifier never claims L3/L4 autonomy, even on a fully closed path.
     */
    public function testNeverClaimsL3OrL4Autonomy(): void
    {
        $closed = $this->verifier->verify($this->fullPathEnvelopes());
        $open = $this->verifier->verify($this->corePathEnvelopes(), ['post_execution_phase_emit' => true]);

        $this->assertFalse($closed['claims_l3_l4_autonomy']);
        $this->assertFalse($open['claims_l3_l4_autonomy']);
    }

    public function testLegacyFallbackRateIsRealFractionAndBlocks(): void
    {
        $envelopes = $this->corePathEnvelopes();
        // P2 and P6 served by the legacy fallback path => 2 of 10 phases.
        $envelopes[2]['legacy_fallback'] = true;
        $envelopes[6]['legacy_fallback'] = true;

        $result = $this->verifier->verify($envelopes);

        $this->assertEqualsWithDelta(0.2, $result['legacy_fallback_rate'], 0.0001);
        $this->assertFalse($result['closed']);
        $this->assertContains('legacy_fallback_path_used', $result['blockers']);
    }

    public function testLegacyFallbackRateNeverExceedsOne(): void
    {
        $envelopes = $this->corePathEnvelopes();
        foreach ($envelopes as $index => $_envelope) {
            $envelopes[$index]['legacy_fallback'] = true;
        }

        $result = $this->verifier->verify($envelopes);

        $this->assertSame(1.0, $result['legacy_fallback_rate']);
        $this->assertLessThanOrEqual(1.0, $result['legacy_fallback_rate']);
    }

    public function testEmptyEnvelopesYieldZeroFallbackRateAndAllPhasesSkipped(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertSame([], $result['phases_seen']);
        $this->assertSame(0.0, $result['legacy_fallback_rate']);
        $this->assertSame(range(0, 9), $result['skipped_phases']);
        $this->assertFalse($result['closed']);
    }

    public function testCanonicalSkipDoesNotBlockClosure(): void
    {
        $envelopes = $this->fullPathEnvelopes();
        // P14 human_review canonically skipped for a non-mission request.
        $envelopes[14] = [
            'phase_id' => 'P14',
            'phase_name' => 'human_review',
            'status' => 'skipped',
            'canonical_skip' => true,
        ];

        $result = $this->verifier->verify($envelopes, ['post_execution_phase_emit' => true]);

        $this->assertSame([], $result['skipped_phases']);
        $this->assertSame([14], $result['canonical_skips']);
        $this->assertTrue($result['closed']);
        $this->assertTrue($result['full_17_phase_path']);
    }

    public function testBlockedPhaseBlocksAndIsReported(): void
    {
        $envelopes = $this->corePathEnvelopes();
        $envelopes[4]['status'] = 'blocked'; // P4 policy_gate denied

        $result = $this->verifier->verify($envelopes);

        $this->assertSame([4], $result['blocked_phases']);
        $this->assertSame([], $result['skipped_phases']);
        $this->assertFalse($result['closed']);
        $this->assertContains('phase_blocked:P4', $result['blockers']);
    }

    public function testEvidenceHashesIgnoreNonSha256Values(): void
    {
        $envelopes = $this->corePathEnvelopes();
        $envelopes[0]['evidence_hash'] = 'sha256:'.str_repeat('c', 64);
        $envelopes[1]['evidence_hash'] = 'not-a-hash';
        $envelopes[2]['evidence_hash'] = 'sha256:';

        $result = $this->verifier->verify($envelopes);

        $this->assertSame(['sha256:'.str_repeat('c', 64)], $result['evidence_hashes']);
    }

    public function testPhaseNumberFallbackWorksWithoutPhaseId(): void
    {
        $envelopes = [];
        foreach (range(0, 9) as $phase) {
            $envelopes[] = ['phase_number' => $phase, 'status' => 'ok'];
        }

        $result = $this->verifier->verify($envelopes);

        $this->assertSame(range(0, 9), $result['phases_seen']);
        $this->assertTrue($result['closed']);
    }

    public function testMismatchedPhaseNameIsNotCounted(): void
    {
        $envelopes = $this->corePathEnvelopes();
        $envelopes[5]['phase_name'] = 'placement'; // P5 should be topology

        $result = $this->verifier->verify($envelopes);

        $this->assertNotContains(5, $result['phases_seen']);
        $this->assertSame([5], $result['skipped_phases']);
        $this->assertFalse($result['closed']);
    }

    public function testOutputIsDeterministicAndPure(): void
    {
        $envelopes = $this->fullPathEnvelopes();

        $this->assertSame(
            $this->verifier->verify($envelopes),
            $this->verifier->verify($envelopes),
        );
    }

    /**
     * A blocked governance phase must be sticky: re-emitting the same phase as
     * `ok` later in the trace cannot mask the block and reopen closure. Without
     * sticky-worst merging the second envelope overwrote the first and the
     * request closed despite a denied phase.
     */
    public function testLaterOkEnvelopeCannotMaskAnEarlierBlockedPhase(): void
    {
        $envelopes = $this->corePathEnvelopes();
        $envelopes[4]['status'] = 'blocked'; // P4 policy_gate denied
        // A duplicate P4 envelope claiming success arrives afterwards.
        $envelopes[] = ['phase_id' => 'P4', 'phase_name' => 'policy_gate', 'status' => 'ok'];

        $result = $this->verifier->verify($envelopes);

        $this->assertSame([4], $result['blocked_phases']);
        $this->assertFalse($result['closed']);
        $this->assertFalse($result['full_17_phase_path']);
        $this->assertContains('phase_blocked:P4', $result['blockers']);
    }

    /**
     * The closure verdict must not depend on the order duplicate envelopes for
     * the same phase arrive in: {blocked, ok} for P4 closes the same way no
     * matter which is seen first.
     */
    public function testDuplicatePhaseStatusVerdictIsOrderIndependent(): void
    {
        $okFirst = $this->corePathEnvelopes();
        $okFirst[] = ['phase_id' => 'P4', 'phase_name' => 'policy_gate', 'status' => 'blocked'];

        $blockedFirst = $this->corePathEnvelopes();
        $blockedFirst[4]['status'] = 'blocked';
        $blockedFirst[] = ['phase_id' => 'P4', 'phase_name' => 'policy_gate', 'status' => 'ok'];

        $okResult = $this->verifier->verify($okFirst);
        $blockedResult = $this->verifier->verify($blockedFirst);

        $this->assertFalse($okResult['closed']);
        $this->assertFalse($blockedResult['closed']);
        $this->assertSame($okResult['closed'], $blockedResult['closed']);
        $this->assertSame($okResult['blocked_phases'], $blockedResult['blocked_phases']);
    }

    /**
     * A non-canonical skip declaration must demote a phase even if another
     * envelope for the same phase carries the canonical_skip marker — an
     * unauthorized skip cannot be laundered into a canonical one.
     */
    public function testUnmarkedSkipDemotesACanonicalSkipForTheSamePhase(): void
    {
        $envelopes = $this->fullPathEnvelopes();
        // P14 declared as a canonical skip once...
        $envelopes[14] = [
            'phase_id' => 'P14',
            'phase_name' => 'human_review',
            'status' => 'skipped',
            'canonical_skip' => true,
        ];
        // ...but also skipped with no canonical marker elsewhere in the trace.
        $envelopes[] = [
            'phase_id' => 'P14',
            'phase_name' => 'human_review',
            'status' => 'skipped',
        ];

        $result = $this->verifier->verify($envelopes, ['post_execution_phase_emit' => true]);

        $this->assertContains(14, $result['skipped_phases']);
        $this->assertNotContains(14, $result['canonical_skips']);
        $this->assertFalse($result['closed']);
        $this->assertFalse($result['full_17_phase_path']);
    }

    /**
     * P0..P16 happy path with two evidence hashes (P12 and P16).
     *
     * @return list<array<string,mixed>>
     */
    private function fullPathEnvelopes(): array
    {
        $names = [
            'intent_capture', 'disambiguation', 'placement', 'classification',
            'policy_gate', 'topology', 'routing', 'spec', 'tasks', 'receipt',
            'execution', 'gates', 'evidence', 'delivery', 'human_review',
            'certification', 'learning',
        ];

        $envelopes = [];
        foreach ($names as $phase => $name) {
            $envelope = [
                'phase_id' => 'P'.$phase,
                'phase_name' => $name,
                'status' => 'ok',
            ];

            if ($phase === 12) {
                $envelope['evidence_hash'] = 'sha256:'.str_repeat('a', 64);
            }
            if ($phase === 16) {
                $envelope['evidence_hash'] = 'sha256:'.str_repeat('b', 64);
            }

            $envelopes[] = $envelope;
        }

        return $envelopes;
    }

    /**
     * P0..P9 governance core only (no post-execution phases). Indexed by phase
     * number so individual phases can be mutated by number in tests.
     *
     * @return list<array<string,mixed>>
     */
    private function corePathEnvelopes(): array
    {
        return array_slice($this->fullPathEnvelopes(), 0, 10);
    }
}
