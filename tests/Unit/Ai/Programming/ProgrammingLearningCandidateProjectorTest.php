<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingLearningCandidateProjector;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ProgrammingLearningCandidateProjectorTest extends TestCase
{
    private ProgrammingLearningCandidateProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = new ProgrammingLearningCandidateProjector();
    }

    public function testProjectReturnsCorrectSchemaVersion(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('atlas.programming.learning_candidate.v1', $projected['schema_version']);
    }

    public function testProjectReturnsCorrectPromotionAllowed(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertFalse($projected['promotion_allowed']);
    }

    public function testProjectReturnsCorrectReviewRequired(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertTrue($projected['review_required']);
    }

    public function testProjectReturnsSourceStatus(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('passed', $projected['source_status']);
    }

    public function testProjectReturnsCorrectCandidateHash(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $expectedHash = hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame($expectedHash, $projected['candidate_hash']);
    }

    public function testProjectReturnsExpiresAtInFuture(): void
    {
        $before = Carbon::now()->addDays(30)->second(0)->millisecond(0);
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);
        $after = Carbon::now()->addDays(30)->second(59)->millisecond(999);

        $expiresAt = Carbon::parse($projected['expires_at']);
        $this->assertTrue($expiresAt->greaterThanOrEqualTo($before));
        $this->assertTrue($expiresAt->lessThanOrEqualTo($after));
    }

    public function testProjectReturnsRollbackWithCorrectStructure(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertTrue($projected['rollback']['available']);
        $this->assertSame('reject_candidate_without_memory_promotion', $projected['rollback']['strategy']);
    }

    public function testProjectReturnsCorrectProhibitedActions(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame([
            'auto_promote_to_memory',
            'send_raw_private_code_to_provider_memory',
        ], $projected['prohibited_actions']);
    }

    public function testProjectReturnsInsufficientEvidenceWhenEvidenceRefsEmpty(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => []];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
    }

    public function testProjectReturnsCandidateReadyForReviewWhenEvidenceRefsNotEmptyAndStatusPassed(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('candidate_ready_for_review', $projected['status']);
    }

    public function testProjectReturnsCandidateReadyForReviewWhenEvidenceRefsNotEmptyAndStatusBlocked(): void
    {
        $result = ['status' => 'blocked', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('candidate_ready_for_review', $projected['status']);
    }

    public function testProjectReturnsCandidateReadyForReviewWhenEvidenceRefsNotEmptyAndStatusFailed(): void
    {
        $result = ['status' => 'failed', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('candidate_ready_for_review', $projected['status']);
    }

    public function testProjectReturnsInsufficientEvidenceWhenEvidenceRefsNotEmptyButStatusInvalid(): void
    {
        $result = ['status' => 'pending', 'evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
    }

    public function testProjectReturnsInsufficientEvidenceWhenEvidenceRefsEmptyAndStatusPassed(): void
    {
        $result = ['status' => 'passed', 'evidence_refs' => []];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
    }

    public function testProjectHandlesMissingStatusKey(): void
    {
        $result = ['evidence_refs' => ['ref1']];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
        $this->assertNull($projected['source_status']);
    }

    public function testProjectHandlesMissingEvidenceRefsKey(): void
    {
        $result = ['status' => 'passed'];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
        $this->assertSame([], $projected['evidence_refs']);
    }

    public function testProjectHandlesEmptyResult(): void
    {
        $result = [];
        $projected = $this->projector->project($result);

        $this->assertSame('atlas.programming.learning_candidate.v1', $projected['schema_version']);
        $this->assertSame('insufficient_evidence', $projected['status']);
        $this->assertFalse($projected['promotion_allowed']);
        $this->assertTrue($projected['review_required']);
        $this->assertSame([], $projected['evidence_refs']);
        $this->assertNull($projected['source_status']);
    }

    public function testProjectHandlesEvidenceRefsAsAssociativeArray(): void
    {
        $result = [
            'status' => 'passed',
            'evidence_refs' => [
                'key1' => 'ref1',
                'key2' => 'ref2',
            ],
        ];
        $projected = $this->projector->project($result);

        $this->assertSame('candidate_ready_for_review', $projected['status']);
        $this->assertSame(['ref1', 'ref2'], $projected['evidence_refs']);
    }

    public function testProjectHandlesEvidenceRefsAsIndexedArray(): void
    {
        $result = [
            'status' => 'failed',
            'evidence_refs' => ['ref1', 'ref2', 'ref3'],
        ];
        $projected = $this->projector->project($result);

        $this->assertSame('candidate_ready_for_review', $projected['status']);
        $this->assertSame(['ref1', 'ref2', 'ref3'], $projected['evidence_refs']);
    }

    public function testProjectHandlesNumericStatus(): void
    {
        $result = [
            'status' => 1,
            'evidence_refs' => ['ref1'],
        ];
        $projected = $this->projector->project($result);

        $this->assertSame('insufficient_evidence', $projected['status']);
    }

    public function testProjectProducesDeterministicHash(): void
    {
        $result = [
            'status' => 'passed',
            'evidence_refs' => ['ref1', 'ref2'],
        ];

        $projected1 = $this->projector->project($result);
        $projected2 = $this->projector->project($result);

        $this->assertSame($projected1['candidate_hash'], $projected2['candidate_hash']);
    }

    public function testProjectHashDiffersForDifferentInputs(): void
    {
        $result1 = [
            'status' => 'passed',
            'evidence_refs' => ['ref1'],
        ];
        $result2 = [
            'status' => 'passed',
            'evidence_refs' => ['ref2'],
        ];

        $projected1 = $this->projector->project($result1);
        $projected2 = $this->projector->project($result2);

        $this->assertNotSame($projected1['candidate_hash'], $projected2['candidate_hash']);
    }
}