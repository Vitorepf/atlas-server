<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Persistence;

use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CostSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenericArtifactPersisterTest extends TestCase
{
    private string $tmpDir;
    private GenericArtifactPersister $persister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-persister-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $this->persister = new GenericArtifactPersister(new ReceiptStorage($this->tmpDir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);
    }

    public function test_scope_guard_write_then_read_round_trip(): void
    {
        $r = $this->scopeGuard('run-1');
        $this->persister->writeScopeGuard($r);
        $read = $this->persister->readScopeGuard('run-1');
        $this->assertNotNull($read);
        $this->assertSame($r->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_verification_receipt_round_trip(): void
    {
        $r = $this->verification('run-1');
        $this->persister->writeVerification($r);
        $read = $this->persister->readVerification('run-1');
        $this->assertNotNull($read);
        $this->assertSame($r->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_escalation_decision_round_trip(): void
    {
        $d = EscalationDecision::issue(
            runId: 'run-1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['scope_explosion'],
            signals: new EscalationSignals(7, 3, ['security'], 8000, 2, 1),
            score: 8,
            riskLevel: 'R2',
            humanActionRequired: true,
        );
        $this->persister->writeEscalationDecision($d);
        $read = $this->persister->readEscalationDecision('run-1');
        $this->assertNotNull($read);
        $this->assertSame($d->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_failure_capsule_filename_includes_attempt_index(): void
    {
        $c = $this->failureCapsule('run-1', 2);
        $result = $this->persister->writeFailureCapsule($c);
        $this->assertSame(2, $result['attempt_index']);
        $this->assertStringContainsString('failure_capsule.2.json', $result['path']);

        $read = $this->persister->readFailureCapsule('run-1', 2);
        $this->assertNotNull($read);
        $this->assertSame($c->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_error_ledger_is_append_only_with_monotonic_versions(): void
    {
        $a = $this->errorEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE);
        $b = $this->errorEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR);
        $rA = $this->persister->appendErrorLedger($a);
        $rB = $this->persister->appendErrorLedger($b);

        $this->assertSame(1, $rA['version']);
        $this->assertSame(2, $rB['version']);

        $read = $this->persister->readErrorLedger('run-1');
        $this->assertCount(2, $read);
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE, $read[0]->actualFailureMode);
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR, $read[1]->actualFailureMode);
    }

    public function test_error_ledger_versions_are_immutable_on_subsequent_appends(): void
    {
        $a = $this->errorEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE);
        $rA = $this->persister->appendErrorLedger($a);
        $before = file_get_contents($rA['path']);

        $b = $this->errorEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR);
        $this->persister->appendErrorLedger($b);

        $after = file_get_contents($rA['path']);
        $this->assertSame($before, $after, 'Existing error ledger entry must not be mutated.');
    }

    public function test_schema_version_mismatch_throws_on_read(): void
    {
        $dir = $this->tmpDir.'/run-1';
        mkdir($dir, 0o755, true);
        file_put_contents(
            $dir.'/scope_guard_receipt.json',
            CanonicalJson::encode([
                'schema_version' => 'atlas.dev.scope_guard_receipt.v0_broken',
                'run_id' => 'run-1',
            ])
        );

        $this->expectException(RuntimeException::class);
        $this->persister->readScopeGuard('run-1');
    }

    private function scopeGuard(string $runId): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: $runId,
            taskContractHash: 'tch',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: new ScopeObserved(
                gitDiffHash: 'dh',
                changedFiles: ['app/Foo.php'],
                changedFilesCount: 1,
                fileDiffs: [new ScopeFileDiff(path: 'app/Foo.php', added: 1, removed: 0, fileHashAfter: 'fh')],
            ),
            scopeContract: new ScopeContractView(['app/Foo.php'], [], [], 3),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'ok',
            userPreExistingChanges: [],
        );
    }

    private function verification(string $runId): VerificationReceipt
    {
        return VerificationReceipt::issue(
            runId: $runId,
            taskContractHash: 'tch',
            workspaceHash: 'wh',
            taskKind: 'patch',
            riskLevel: 'R1',
            provider: 'claude_cli',
            model: 'claude-sonnet-4-6',
            contextPackHash: 'cph',
            promptProjectionHash: 'pph',
            scopeGuardReceiptHash: 'sgh',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            fileHashes: ['app/Foo.php' => 'fh'],
            evidenceRefs: [new EvidenceRef('diff', 'storage/atlas-dev/receipts/'.$runId.'/diff.patch', 'dh')],
            gates: [
                new GateOutcome('scope_guard_light', GateOutcome::STATUS_PASSED, true, null, true, null),
                new GateOutcome('verification_gate', GateOutcome::STATUS_PASSED, true, null, true, null),
            ],
            tests: [new TestRun('composer test', true, 0, 1000, 'th', null)],
            repair: new RepairSummary(0, [], false),
            cost: new CostSummary(1, 100, 50, 0.01, 1000),
            completion: new CompletionSummary(CompletionSummary::STATUS_PASSED, [], []),
            escalation: new EscalationSummary(false, null, [], null),
        );
    }

    private function failureCapsule(string $runId, int $attempt): FailureCapsule
    {
        return FailureCapsule::issue(
            runId: $runId,
            taskContractHash: 'tch',
            attemptIndex: $attempt,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'TypeError',
            fullErrorLogPath: null,
            failingTest: 'tests/Foo::a',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            decision: FailureCapsule::DECISION_RETRY,
        );
    }

    private function errorEntry(string $runId, string $mode): FastPathErrorLedgerEntry
    {
        return FastPathErrorLedgerEntry::issue(
            runId: $runId,
            failureSignature: 'sig:'.$mode,
            completionState: CompletionSummary::STATUS_FAILED,
            actualFailureMode: $mode,
            shouldHaveEscalated: null,
            missingEscalationSignals: [],
            observedSignals: new ObservedSignals(2, 1, [], null, null, null),
            correctionRecommendation: ['x'],
        );
    }

}
