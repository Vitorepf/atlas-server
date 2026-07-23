<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use PHPUnit\Framework\TestCase;

final class OutcomeCausalityRankerTest extends TestCase
{
    private OutcomeCausalityRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new OutcomeCausalityRanker();
    }

    public function testSchemaVersionIsExact(): void
    {
        $result = $this->ranker->rank(true, 'succeeded', false, false);

        $this->assertSame('atlas.aaeos.outcome_causality_ranking.v1', $result['schema_version']);
    }

    public function testAssertionAOnlyTestsFailedWhenEvidencePresentAndSucceeded(): void
    {
        $result = $this->ranker->rank(true, 'succeeded', false, false);

        $this->assertSame('tests_failed', $result['primary_cause']);
        $this->assertSame([['cause' => 'tests_failed', 'weight' => 0.85]], $result['candidates']);
        $this->assertSame([], $result['alternative_explanations']);
        $this->assertSame(0.90, $result['attribution_confidence']);
        $this->assertFalse($result['attribution_blocked']);
    }

    public function testAssertionBMissingEvidenceOutranksTestsFailed(): void
    {
        $result = $this->ranker->rank(false, 'succeeded', false, false);

        $this->assertSame('missing_evidence', $result['candidates'][0]['cause']);
        $this->assertSame('tests_failed', $result['candidates'][1]['cause']);
        $this->assertSame('missing_evidence', $result['primary_cause']);
        $this->assertSame(0.90, $result['attribution_confidence']);
        $this->assertTrue($result['attribution_blocked']);
    }

    public function testAssertionCFallbackWhenNoFailureSignals(): void
    {
        $result = $this->ranker->rank(true, 'succeeded', false, true);

        $this->assertSame(
            [['cause' => 'execution_strategy_likely_succeeded', 'weight' => 0.55]],
            $result['candidates'],
        );
        $this->assertSame(0.62, $result['attribution_confidence']);
    }

    public function testAssertionDExecutionFailedOutranksContextAndNullTestsAddNothing(): void
    {
        $result = $this->ranker->rank(true, 'blocked', true, null);

        $this->assertSame('execution_failed_or_blocked', $result['primary_cause']);
        $this->assertSame(
            ['execution_failed_or_blocked', 'context_missing_required_sources'],
            array_map(static fn (array $candidate): string => $candidate['cause'], $result['candidates']),
        );
        $this->assertSame(
            'context_missing_required_sources',
            $result['alternative_explanations'][0]['cause'],
        );
    }

    public function testFailedStatusWithoutTestSignalUsesExecutionFailedConfidence(): void
    {
        $result = $this->ranker->rank(true, 'failed', false, null);

        $this->assertSame(
            [['cause' => 'execution_failed_or_blocked', 'weight' => 0.70]],
            $result['candidates'],
        );
        $this->assertSame('execution_failed_or_blocked', $result['primary_cause']);
        $this->assertSame([], $result['alternative_explanations']);
        $this->assertSame(0.78, $result['attribution_confidence']);
        $this->assertFalse($result['attribution_blocked']);
    }

    public function testAllFourSignalsRankByDescendingWeight(): void
    {
        $result = $this->ranker->rank(false, 'failed', true, false);

        $this->assertSame(
            [
                ['cause' => 'missing_evidence', 'weight' => 0.95],
                ['cause' => 'tests_failed', 'weight' => 0.85],
                ['cause' => 'execution_failed_or_blocked', 'weight' => 0.70],
                ['cause' => 'context_missing_required_sources', 'weight' => 0.65],
            ],
            $result['candidates'],
        );
        $this->assertSame('missing_evidence', $result['primary_cause']);
        $this->assertSame(
            [
                ['cause' => 'tests_failed', 'weight' => 0.85],
                ['cause' => 'execution_failed_or_blocked', 'weight' => 0.70],
                ['cause' => 'context_missing_required_sources', 'weight' => 0.65],
            ],
            $result['alternative_explanations'],
        );
        $this->assertSame(0.90, $result['attribution_confidence']);
        $this->assertTrue($result['attribution_blocked']);
    }

    public function testContextOnlySignalUsesContextConfidence(): void
    {
        $result = $this->ranker->rank(true, 'succeeded', true, true);

        $this->assertSame(
            [['cause' => 'context_missing_required_sources', 'weight' => 0.65]],
            $result['candidates'],
        );
        $this->assertSame('context_missing_required_sources', $result['primary_cause']);
        $this->assertSame(0.78, $result['attribution_confidence']);
        $this->assertFalse($result['attribution_blocked']);
    }

    public function testAttributionBlockedTracksMissingEvidenceRefsIndependentlyOfPrimaryCause(): void
    {
        $blockedFallback = $this->ranker->rank(false, 'succeeded', false, true);

        $this->assertSame('missing_evidence', $blockedFallback['primary_cause']);
        $this->assertTrue($blockedFallback['attribution_blocked']);

        $unblockedFallback = $this->ranker->rank(true, 'succeeded', false, true);

        $this->assertSame('execution_strategy_likely_succeeded', $unblockedFallback['primary_cause']);
        $this->assertFalse($unblockedFallback['attribution_blocked']);
    }

    public function testGiveBackWithInsufficientAllowedFilesOutranksGenericExecutionFailure(): void
    {
        $result = $this->ranker->rankOutcomeEnvelope([
            'outcome' => 'give_back',
            'has_evidence_refs' => true,
            'allowed_files_sufficient' => false,
        ]);

        $this->assertSame('scope_or_contract_mismatch', $result['primary_cause']);
        $this->assertSame(
            ['scope_or_contract_mismatch', 'execution_failed_or_blocked'],
            array_map(static fn (array $candidate): string => $candidate['cause'], $result['candidates']),
        );
    }

    public function testPoisonOutcomeRanksPacketQualityFailureAheadOfMissingContext(): void
    {
        $result = $this->ranker->rankOutcomeEnvelope([
            'outcome' => 'poison',
            'has_evidence_refs' => true,
            'missing_required_sources' => true,
            'packet_quality_failed' => true,
        ]);

        $this->assertSame('packet_quality_failure', $result['primary_cause']);
        $causes = array_map(static fn (array $candidate): string => $candidate['cause'], $result['candidates']);
        $this->assertContains('packet_quality_failure', $causes);
        $this->assertContains('context_missing_required_sources', $causes);
        $this->assertLessThan(
            array_search('context_missing_required_sources', $causes, true),
            array_search('packet_quality_failure', $causes, true),
        );
    }

    public function testSuccessOutcomeWithEvidenceAndGreenTestsKeepsAttributionUnblocked(): void
    {
        $result = $this->ranker->rankOutcomeEnvelope([
            'outcome' => 'success',
            'has_evidence_refs' => true,
            'tests_passed' => true,
        ]);

        $this->assertSame('execution_strategy_likely_succeeded', $result['primary_cause']);
        $this->assertFalse($result['attribution_blocked']);
    }

    public function testOutcomeEnvelopeNormalizesCaseAndWhitespace(): void
    {
        $result = $this->ranker->rankOutcomeEnvelope([
            'outcome' => '  SUCCESS  ',
            'has_evidence_refs' => true,
            'tests_passed' => true,
        ]);

        $this->assertSame('execution_strategy_likely_succeeded', $result['primary_cause']);
        $this->assertFalse($result['attribution_blocked']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $first = $this->ranker->rank(false, 'failed', true, false);
        $second = $this->ranker->rank(false, 'failed', true, false);

        $this->assertSame($first, $second);
    }
}
