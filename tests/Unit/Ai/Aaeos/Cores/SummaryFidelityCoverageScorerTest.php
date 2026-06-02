<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Cores\SummaryFidelityCoverageScorer;
use PHPUnit\Framework\TestCase;

final class SummaryFidelityCoverageScorerTest extends TestCase
{
    private SummaryFidelityCoverageScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new SummaryFidelityCoverageScorer();
    }

    public function testMissingDecisionFailsWithTwoThirdsRetention(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'k-auth-flow', 'kind' => 'must_keep', 'digest' => null],
                ['id' => 'd-ship-postgres', 'kind' => 'decision', 'digest' => 'choose postgres for the ledger'],
                ['id' => 'n-write-tests', 'kind' => 'next_step', 'digest' => null],
            ],
            'We confirmed k-auth-flow and then n-write-tests remained on the plan.',
        );

        $this->assertSame('atlas.aaeos.summary_fidelity_coverage.v1', $result['schema_version']);
        $this->assertSame(round(2 / 3, 4), $result['context_retention_score']);
        $this->assertSame(0.6667, $result['context_retention_score']);
        $this->assertSame(1.0, $result['missed_decision_rate']);
        $this->assertSame('failed', $result['verdict']);
        $this->assertSame(['d-ship-postgres'], $result['missing_item_ids']);
        $this->assertSame(['d-ship-postgres'], $result['missing_decision_ids']);
        $this->assertSame(3, $result['required_total']);
        $this->assertSame(2, $result['present_total']);
        $this->assertSame(1, $result['missing_total']);
        $this->assertSame(1, $result['decision_total']);
    }

    public function testAllPresentPasses(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'k-auth-flow', 'kind' => 'must_keep', 'digest' => null],
                ['id' => 'd-ship-postgres', 'kind' => 'decision', 'digest' => null],
                ['id' => 'b-rate-limit', 'kind' => 'blocker', 'digest' => null],
            ],
            'Notes cover k-auth-flow, d-ship-postgres and b-rate-limit fully.',
        );

        $this->assertSame(1.0, $result['context_retention_score']);
        $this->assertSame(0.0, $result['missed_decision_rate']);
        $this->assertSame('passed', $result['verdict']);
        $this->assertSame(3, $result['present_total']);
        $this->assertSame(0, $result['missing_total']);
        $this->assertSame(['k-auth-flow', 'd-ship-postgres', 'b-rate-limit'], $result['present_item_ids']);
        $this->assertSame([], $result['missing_item_ids']);
        $this->assertSame([], $result['missing_decision_ids']);
    }

    public function testNonDecisionPartialCoverageIsDegraded(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'k-one', 'kind' => 'must_keep', 'digest' => null],
                ['id' => 'k-two', 'kind' => 'open_loop', 'digest' => null],
                ['id' => 'k-three', 'kind' => 'next_step', 'digest' => null],
                ['id' => 'k-four', 'kind' => 'must_keep', 'digest' => null],
            ],
            'Summary references k-one, k-two and k-three but nothing else.',
        );

        $this->assertSame(0.75, $result['context_retention_score']);
        $this->assertSame(0.0, $result['missed_decision_rate']);
        $this->assertSame('degraded', $result['verdict']);
        $this->assertSame(0, $result['decision_total']);
        $this->assertSame(['k-four'], $result['missing_item_ids']);
    }

    public function testRetentionAtPointSixBoundaryWithPresentDecisionIsNotFailed(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'd-keep-local', 'kind' => 'decision', 'digest' => null],
                ['id' => 'k-alpha', 'kind' => 'must_keep', 'digest' => null],
                ['id' => 'k-beta', 'kind' => 'open_loop', 'digest' => null],
                ['id' => 'k-gamma', 'kind' => 'next_step', 'digest' => null],
                ['id' => 'k-delta', 'kind' => 'must_keep', 'digest' => null],
            ],
            'We keep d-keep-local, plus k-alpha and k-beta carried forward.',
        );

        $this->assertSame(0.6, $result['context_retention_score']);
        $this->assertSame(0.0, $result['missed_decision_rate']);
        $this->assertNotSame('failed', $result['verdict']);
        $this->assertSame('degraded', $result['verdict']);
        $this->assertSame(['k-gamma', 'k-delta'], $result['missing_item_ids']);
        $this->assertSame([], $result['missing_decision_ids']);
    }

    public function testItemPresentByDigestSubstringEvenWhenIdAbsent(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'opaque-uuid-9f2', 'kind' => 'must_keep', 'digest' => 'rotate the signing key nightly'],
            ],
            'The crew agreed to rotate the signing key nightly going forward.',
        );

        $this->assertSame(1.0, $result['context_retention_score']);
        $this->assertSame(1, $result['present_total']);
        $this->assertSame(['opaque-uuid-9f2'], $result['present_item_ids']);
        $this->assertSame([], $result['missing_item_ids']);
        $this->assertSame('passed', $result['verdict']);
    }

    public function testUnverifiableItemIsCountedMissingAndListed(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'k-present', 'kind' => 'must_keep', 'digest' => null],
                ['id' => '   ', 'kind' => 'must_keep', 'digest' => ''],
            ],
            'Only k-present is mentioned anywhere in the recap.',
        );

        $this->assertSame(['   '], $result['unverifiable_item_ids']);
        $this->assertContains('   ', $result['missing_item_ids']);
        $this->assertSame(1, $result['missing_total']);
        $this->assertSame(0.5, $result['context_retention_score']);
        $this->assertSame('failed', $result['verdict']);
    }

    public function testCaseInsensitiveIdMatchingCountsPresent(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'D-Ship-Postgres', 'kind' => 'decision', 'digest' => null],
            ],
            'The decision d-ship-postgres is locked in.',
        );

        $this->assertSame(1.0, $result['context_retention_score']);
        $this->assertSame(0.0, $result['missed_decision_rate']);
        $this->assertSame('passed', $result['verdict']);
        $this->assertSame(['D-Ship-Postgres'], $result['present_item_ids']);
    }

    public function testEmptyRequiredItemsIsVacuousPass(): void
    {
        $result = $this->scorer->score([], 'anything at all');

        $this->assertSame(1.0, $result['context_retention_score']);
        $this->assertSame(0.0, $result['missed_decision_rate']);
        $this->assertSame('passed', $result['verdict']);
        $this->assertSame(0, $result['required_total']);
        $this->assertSame(0, $result['present_total']);
        $this->assertSame(0, $result['missing_total']);
        $this->assertSame([], $result['missing_item_ids']);
    }

    public function testDuplicateMissingIdsAreDeduped(): void
    {
        $result = $this->scorer->score(
            [
                ['id' => 'k-dupe', 'kind' => 'must_keep', 'digest' => null],
                ['id' => 'k-dupe', 'kind' => 'open_loop', 'digest' => null],
            ],
            'Nothing relevant is captured in this summary.',
        );

        $this->assertSame(['k-dupe'], $result['missing_item_ids']);
        $this->assertSame(2, $result['required_total']);
        $this->assertSame(2, $result['missing_total']);
    }

    public function testVerdictForFailsWhenDecisionMissed(): void
    {
        $this->assertSame('failed', $this->scorer->verdictFor(1.0, 0.25));
    }

    public function testVerdictForFailsBelowRetentionFloor(): void
    {
        $this->assertSame('failed', $this->scorer->verdictFor(0.5, 0.0));
    }

    public function testVerdictForDegradedInsideBoundaryWithNoMissedDecision(): void
    {
        $this->assertSame('degraded', $this->scorer->verdictFor(0.6, 0.0));
        $this->assertSame('degraded', $this->scorer->verdictFor(0.9, 0.0));
    }

    public function testVerdictForPassesOnlyAtFullRetentionWithoutMissedDecision(): void
    {
        $this->assertSame('passed', $this->scorer->verdictFor(1.0, 0.0));
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $items = [
            ['id' => 'd-ship-postgres', 'kind' => 'decision', 'digest' => null],
            ['id' => 'k-auth-flow', 'kind' => 'must_keep', 'digest' => null],
        ];
        $summary = 'd-ship-postgres confirmed and k-auth-flow retained.';

        $this->assertSame(
            $this->scorer->score($items, $summary),
            $this->scorer->score($items, $summary),
        );
    }
}
