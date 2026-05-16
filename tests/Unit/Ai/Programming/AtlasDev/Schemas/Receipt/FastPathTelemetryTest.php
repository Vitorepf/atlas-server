<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathTelemetry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class FastPathTelemetryTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_issue_and_round_trip(): void
    {
        $t = $this->makeBasic();
        $rebuilt = FastPathTelemetry::fromArray($t->toCanonicalArray());
        $this->assertSame($t->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertContractSurface($t);
    }

    public function test_failed_completion_requires_error_ledger_written(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FastPathTelemetry::issue(
            runId: 'r1',
            workspaceHash: 'wh',
            taskKind: 'patch',
            riskLevel: 'R2',
            promptProjectionHash: 'pph',
            contractCompletenessStatus: 'passed',
            docTiersSelected: [],
            gatesActivated: [],
            provider: 'claude_cli',
            model: 'claude-sonnet-4-6',
            providerCalls: 1,
            repairAttempts: 0,
            costEstimateUsd: 0.01,
            wallTimeMs: 1000,
            completionState: CompletionSummary::STATUS_FAILED,
            escalationTriggered: false,
            receiptPersisted: true,
            errorLedgerWritten: false,
        );
    }

    public function test_post_hoc_review_does_not_invalidate_telemetry_hash(): void
    {
        $t = $this->makeBasic();
        $reviewed = $t->withPostHocEscalationReview(true);
        $this->assertSame($t->telemetryHash, $reviewed->telemetryHash);
        $this->assertSame($t->hash(), $reviewed->hash());
        $this->assertTrue($reviewed->escalationWasCorrect);
    }

    public function test_deterministic_shape(): void
    {
        $a = $this->makeBasic();
        $b = $this->makeBasic();
        $this->assertSame($a->toCanonicalArray(), $b->toCanonicalArray());
        $this->assertSame($a->telemetryHash, $b->telemetryHash);
    }

    private function makeBasic(): FastPathTelemetry
    {
        return FastPathTelemetry::issue(
            runId: 'r1',
            workspaceHash: 'wh',
            taskKind: 'patch',
            riskLevel: 'R2',
            promptProjectionHash: 'pph',
            contractCompletenessStatus: 'passed',
            docTiersSelected: ['tier_a', 'tier_b'],
            gatesActivated: ['scope_guard_light'],
            provider: 'claude_cli',
            model: 'claude-sonnet-4-6',
            providerCalls: 1,
            repairAttempts: 0,
            costEstimateUsd: 0.018,
            wallTimeMs: 8431,
            completionState: CompletionSummary::STATUS_PASSED,
            escalationTriggered: false,
            receiptPersisted: true,
            errorLedgerWritten: false,
        );
    }
}
