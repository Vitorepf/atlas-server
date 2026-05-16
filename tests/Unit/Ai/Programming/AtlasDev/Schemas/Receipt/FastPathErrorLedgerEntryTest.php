<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class FastPathErrorLedgerEntryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $e = $this->makeBasic();
        $rebuilt = FastPathErrorLedgerEntry::fromArray($e->toCanonicalArray());
        $this->assertSame($e->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertContractSurface($e);
    }

    public function test_missed_escalation_requires_should_have_escalated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FastPathErrorLedgerEntry::issue(
            runId: 'r1',
            failureSignature: 'sig',
            completionState: CompletionSummary::STATUS_FAILED,
            actualFailureMode: FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_ESCALATION,
            shouldHaveEscalated: null,
            missingEscalationSignals: ['scope_explosion'],
            observedSignals: $this->signals(),
            correctionRecommendation: ['raise threshold'],
        );
    }

    public function test_missed_escalation_requires_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FastPathErrorLedgerEntry::issue(
            runId: 'r1',
            failureSignature: 'sig',
            completionState: CompletionSummary::STATUS_FAILED,
            actualFailureMode: FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_ESCALATION,
            shouldHaveEscalated: true,
            missingEscalationSignals: [],
            observedSignals: $this->signals(),
            correctionRecommendation: ['x'],
        );
    }

    public function test_reviewer_signed_requires_reviewer_and_timestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FastPathErrorLedgerEntry::issue(
            runId: 'r1',
            failureSignature: 'sig',
            completionState: CompletionSummary::STATUS_FAILED,
            actualFailureMode: FastPathErrorLedgerEntry::FAILURE_MODE_OTHER,
            shouldHaveEscalated: null,
            missingEscalationSignals: [],
            observedSignals: $this->signals(),
            correctionRecommendation: [],
            reviewerSigned: true,
            reviewer: null,
            reviewedAt: null,
        );
    }

    private function makeBasic(): FastPathErrorLedgerEntry
    {
        return FastPathErrorLedgerEntry::issue(
            runId: 'r1',
            failureSignature: 'sig',
            completionState: CompletionSummary::STATUS_FAILED,
            actualFailureMode: FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE,
            shouldHaveEscalated: null,
            missingEscalationSignals: [],
            observedSignals: $this->signals(),
            correctionRecommendation: ['tighten scope'],
        );
    }

    private function signals(): ObservedSignals
    {
        return new ObservedSignals(
            fileCount: 4,
            layersTouched: 2,
            riskKeywords: [],
            contextRequiredChars: 6000,
            priorFailureInArea: false,
            testCoverageGap: false,
        );
    }
}
