<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CostSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class VerificationReceiptTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_issue_seals_receipt_hash(): void
    {
        $r = $this->validPassedRepair();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->receiptHash);
        $this->assertContractSurface($r);
        $this->assertTrue($r->isPassed());
    }

    public function test_round_trip(): void
    {
        $r = $this->validPassedRepair();
        $rebuilt = VerificationReceipt::fromArray($r->toCanonicalArray());
        $this->assertSame($r->toCanonicalArray(), $rebuilt->toCanonicalArray());
    }

    public function test_hash_excludes_self(): void
    {
        $r = $this->validPassedRepair();
        $expected = CanonicalHasher::hashWithout($r->toCanonicalArray(), 'receipt_hash');
        $this->assertSame($expected, $r->hash());
    }

    public function test_passed_requires_all_required_gates_passed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->buildReceipt(
            completion: new CompletionSummary(CompletionSummary::STATUS_PASSED, [], []),
            gates: [
                new GateOutcome('scope_guard_light', GateOutcome::STATUS_FAILED, true, null, true, null),
            ],
        );
    }

    public function test_passed_with_failing_tests_only_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->buildReceipt(
            completion: new CompletionSummary(CompletionSummary::STATUS_PASSED, [], []),
            tests: [
                new TestRun('vendor/bin/phpunit', false, 1, 100, 'h', null),
            ],
        );
    }

    public function test_escalate_forge_requires_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->buildReceipt(
            completion: new CompletionSummary(CompletionSummary::STATUS_ESCALATE_FORGE, [], []),
            escalation: new EscalationSummary(recommended: false, target: null, reasons: [], decisionRef: null),
            gates: [],
            tests: [],
        );
    }

    public function test_no_patch_reason_satisfies_passed_with_no_passing_tests(): void
    {
        $r = $this->buildReceipt(
            completion: new CompletionSummary(CompletionSummary::STATUS_PASSED, [], []),
            tests: [],
            evidenceRefs: [new EvidenceRef('no_patch_reason', 'storage/atlas-dev/receipts/r1/no_patch.md', 'h')],
        );
        $this->assertTrue($r->isPassed());
    }

    public function test_required_gate_must_be_fresh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->buildReceipt(
            gates: [
                new GateOutcome('scope_guard_light', GateOutcome::STATUS_PASSED, true, null, false, null),
                new GateOutcome('verification_gate', GateOutcome::STATUS_PASSED, true, null, true, null),
            ],
        );
    }

    private function validPassedRepair(): VerificationReceipt
    {
        return $this->buildReceipt();
    }

    /**
     * @param  list<EvidenceRef>|null  $evidenceRefs
     * @param  list<GateOutcome>|null  $gates
     * @param  list<TestRun>|null  $tests
     */
    private function buildReceipt(
        ?CompletionSummary $completion = null,
        ?EscalationSummary $escalation = null,
        ?array $evidenceRefs = null,
        ?array $gates = null,
        ?array $tests = null,
    ): VerificationReceipt {
        return VerificationReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            workspaceHash: 'wh',
            taskKind: 'repair',
            riskLevel: 'R2',
            provider: 'claude_cli',
            model: 'claude-sonnet-4-6',
            contextPackHash: 'cph',
            promptProjectionHash: 'pph',
            scopeGuardReceiptHash: 'sgh',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            fileHashes: ['app/Foo.php' => 'fh'],
            evidenceRefs: $evidenceRefs ?? [
                new EvidenceRef('diff', 'storage/atlas-dev/receipts/r1/diff.patch', 'dh'),
                new EvidenceRef('test_log', 'storage/atlas-dev/receipts/r1/test.log', 'th'),
            ],
            gates: $gates ?? [
                new GateOutcome('scope_guard_light', GateOutcome::STATUS_PASSED, true, null, true, null),
                new GateOutcome('verification_gate', GateOutcome::STATUS_PASSED, true, null, true, null),
            ],
            tests: $tests ?? [
                new TestRun('composer test', true, 0, 1000, 'th', 'storage/atlas-dev/receipts/r1/test.log'),
            ],
            repair: new RepairSummary(0, [], false),
            cost: new CostSummary(1, 100, 50, 0.01, 1000),
            completion: $completion ?? new CompletionSummary(CompletionSummary::STATUS_PASSED, [], []),
            escalation: $escalation ?? new EscalationSummary(false, null, [], null),
        );
    }
}
