<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalScorer;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalsInput;
use App\Services\Ai\Programming\AtlasDev\Escalation\ForgePromotionPreviewBuilder;
use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureModeClassifier;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairAttemptLimits;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairLoopResult;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairTelemetryRecorder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Repair\RepairFixtureFactory;

/**
 * End-to-end repair + escalation drive without provider/gate code:
 *   - FakeEvaluator scripts the outcomes the orchestrator would have seen;
 *   - RepairOrchestrator runs the loop;
 *   - EscalationDecisionEngine consumes the loop's signals;
 *   - ForgePromotionPreviewBuilder packages the human handoff;
 *   - RepairTelemetryRecorder appends an error-ledger entry.
 *
 * Persistence is staged in a tmp dir; the feature exercises ReceiptStorage,
 * GenericArtifactPersister, ErrorLedgerWriter and the FailureModeClassifier
 * in concert, but never touches Provider/Gate/Pipeline modules.
 */
final class EndToEndRepairDecisionTest extends TestCase
{
    private string $tmpDir;
    private RepairOrchestrator $orchestrator;
    private EscalationDecisionEngine $escalationEngine;
    private ForgePromotionPreviewBuilder $previewBuilder;
    private RepairTelemetryRecorder $telemetryRecorder;
    private GenericArtifactPersister $persister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-e2e-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $this->persister = new GenericArtifactPersister(new ReceiptStorage($this->tmpDir));

        $this->orchestrator = new RepairOrchestrator(
            limits: new RepairAttemptLimits(),
            capsuleBuilder: new FailureCapsuleBuilder(new FailureSignatureHasher()),
            promptComposer: new RepairPromptComposer(),
        );

        $this->escalationEngine = new EscalationDecisionEngine(new EscalationSignalScorer());
        $this->previewBuilder = new ForgePromotionPreviewBuilder();
        $this->telemetryRecorder = new RepairTelemetryRecorder(
            writer: new ErrorLedgerWriter($this->persister),
            classifier: new FailureModeClassifier(),
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function test_first_attempt_recovers_emits_no_escalation_no_ledger_entry(): void
    {
        $runId = 'run-e2e-1';
        $contract = RepairFixtureFactory::lightTaskContract(runId: $runId, maxAttempts: 2);
        $prompt = RepairFixtureFactory::providerPromptProjection(runId: $runId, taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(runId: $runId, taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            RepairAttemptOutcome::passed('sha:fix', ['app/Foo.php'], 2),
        ]);

        $loop = $this->orchestrator->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_RECOVERED, $loop->status);

        // Recovered run feeds the escalation engine with low signals → null.
        $signals = new EscalationSignalsInput(
            riskLevel: 'R2',
            fileCount: 1,
            layersTouched: 1,
            riskKeywords: [],
            sameSignatureTwice: false,
            diffGrew: false,
            testCoverageGap: false,
            priorFailureInArea: false,
            contextRequiredChars: 4000,
            threadMessages: 3,
            priorFailureCount: 0,
            loopEscalationSignalDelta: $loop->escalationSignalDelta,
        );
        $decision = $this->escalationEngine->decide($signals, $runId, $contract->taskContractHash, '2026-05-16T10:00:00Z');
        $this->assertNull($decision);

        // No ledger entry for a passed run.
        $entry = $this->telemetryRecorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_PASSED,
            capsule: $loop->lastCapsule ?? $initial,
            signals: new ObservedSignals(1, 1, []),
            attemptCount: $loop->attemptsExecuted,
            escalationSignals: $loop->escalationSignalDelta,
        );
        $this->assertNull($entry);
    }

    public function test_same_signature_twice_escalates_to_forge_and_writes_ledger_entry(): void
    {
        $runId = 'run-e2e-2';
        $contract = RepairFixtureFactory::lightTaskContract(runId: $runId, maxAttempts: 3);
        $prompt = RepairFixtureFactory::providerPromptProjection(runId: $runId, taskContractHash: $contract->taskContractHash);
        // Initial and second attempt wrap the same logical error in
        // different volatile fragments. The hasher must collapse both to
        // the same signature so the loop trips the same-signature stop rule.
        $initial = RepairFixtureFactory::failureCapsule(
            runId: $runId,
            taskContractHash: $contract->taskContractHash,
            primaryError: '[2026-05-16T12:00:00Z] AssertionError: expected 1 got 0 at /tmp/run-1/foo.php:5',
        );

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_FAILED,
                gate: 'verification_gate',
                command: 'composer test',
                exitCode: 1,
                primaryErrorExcerpt: '[2026-05-17T08:34:12Z] AssertionError: expected 1 got 0 at /tmp/run-9/foo.php:99',
                fullErrorLogPath: null,
                failingTest: 'tests/FooTest::test_x',
                diffHash: 'sha:b',
                changedFiles: ['app/Foo.php'],
                diffSizeLines: 3,
            ),
        ]);

        $loop = $this->orchestrator->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R3',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_ESCALATED, $loop->status);
        $this->assertContains(
            FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE,
            $loop->escalationSignalDelta,
        );

        // The escalation engine sees enough signals to reach the obra
        // candidate threshold even with R3 (file_count 1, but layers + loop
        // signal + test gap push the score to 4).
        $signals = new EscalationSignalsInput(
            riskLevel: 'R3',
            fileCount: 1,
            layersTouched: 3,
            riskKeywords: [],
            sameSignatureTwice: true, // +2
            diffGrew: false,
            testCoverageGap: true, // +1
            priorFailureInArea: false,
            contextRequiredChars: 8000,
            threadMessages: 6,
            priorFailureCount: 1,
            loopEscalationSignalDelta: $loop->escalationSignalDelta,
        );
        // +1 layers +2 same_sig +1 test_gap = 4 → obra_candidate
        $decision = $this->escalationEngine->decide($signals, $runId, $contract->taskContractHash, '2026-05-16T10:00:00Z');
        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_OBRA_CANDIDATE, $decision->target);

        // The preview builder packages a human-actionable payload.
        $preview = $this->previewBuilder->build(
            $decision,
            intentSummary: 'Repair same-signature failure on FooTest',
            changedFiles: ['app/Foo.php'],
            contextRefs: ['tests/FooTest.php#sha256:abc'],
            workspaceHash: 'sha256:ws',
        );
        $this->assertSame('obra_candidate', $preview['promotion_tier']);
        $this->assertSame('pending_human_action', $preview['submission_state']);

        // The ledger entry records the bad-repair classification.
        $entry = $this->telemetryRecorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_FAILED,
            capsule: $loop->lastCapsule,
            signals: new ObservedSignals(1, 3, []),
            attemptCount: $loop->attemptsExecuted,
            escalationSignals: $loop->escalationSignalDelta,
            correctionRecommendation: ['review_failing_test', 'consider_forge_promotion'],
        );
        $this->assertNotNull($entry);
        $this->assertSame(1, $entry['version']);
    }

    public function test_r4_returns_not_attempted_and_forces_forge_decision(): void
    {
        $runId = 'run-e2e-3';
        $contract = RepairFixtureFactory::lightTaskContract(runId: $runId, maxAttempts: 5);
        $prompt = RepairFixtureFactory::providerPromptProjection(runId: $runId, taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(runId: $runId, taskContractHash: $contract->taskContractHash);

        $loop = $this->orchestrator->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R4',
            evaluator: RepairFixtureFactory::scriptedEvaluator([]),
        );

        $this->assertSame(RepairLoopResult::STATUS_NOT_ATTEMPTED, $loop->status);
        $this->assertSame(0, $loop->attemptsExecuted);

        $signals = new EscalationSignalsInput(
            riskLevel: 'R4',
            fileCount: 1,
            layersTouched: 1,
            riskKeywords: [],
            sameSignatureTwice: false,
            diffGrew: false,
            testCoverageGap: false,
            priorFailureInArea: false,
            contextRequiredChars: null,
            threadMessages: null,
            priorFailureCount: 0,
            loopEscalationSignalDelta: $loop->escalationSignalDelta,
        );

        $decision = $this->escalationEngine->decide($signals, $runId, $contract->taskContractHash, '2026-05-16T10:00:00Z');
        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_FORGE, $decision->target);
        $this->assertTrue($decision->humanActionRequired);

        // Persistence end-to-end: decision lands on disk via the same canonical
        // persister Claude 7 ships.
        $path = $this->persister->writeEscalationDecision($decision);
        $this->assertFileExists($path);

        $stored = $this->persister->readEscalationDecision($runId);
        $this->assertNotNull($stored);
        $this->assertSame($decision->decisionHash, $stored->decisionHash);

        // Ledger entry for a forced-forge escalate path.
        $entry = $this->telemetryRecorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_ESCALATE_FORGE,
            capsule: $loop->lastCapsule ?? $initial,
            signals: new ObservedSignals(1, 1, []),
            attemptCount: 0,
            escalationSignals: $loop->escalationSignalDelta,
        );
        $this->assertNotNull($entry);
    }

    public function test_full_capsule_chain_persists_across_attempts(): void
    {
        $runId = 'run-e2e-4';
        $contract = RepairFixtureFactory::lightTaskContract(runId: $runId, maxAttempts: 2);
        $prompt = RepairFixtureFactory::providerPromptProjection(runId: $runId, taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(runId: $runId, taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_FAILED,
                gate: 'verification_gate',
                command: 'composer test',
                exitCode: 1,
                primaryErrorExcerpt: 'TypeError: argument 1 must be string, int given',
                fullErrorLogPath: null,
                failingTest: 'tests/FooTest::test_y',
                diffHash: 'sha:b',
                changedFiles: ['app/Foo.php'],
                diffSizeLines: 5,
            ),
            RepairAttemptOutcome::passed('sha:final', ['app/Foo.php'], 6),
        ]);

        $loop = $this->orchestrator->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_RECOVERED, $loop->status);

        // Persist each capsule at the canonical filename, exercising the
        // append-only "one file per attempt_index" contract Claude 7 owns.
        foreach ($loop->capsules as $capsule) {
            $result = $this->persister->writeFailureCapsule($capsule);
            $this->assertSame($capsule->attemptIndex, $result['attempt_index']);
        }

        $read0 = $this->persister->readFailureCapsule($runId, 0);
        $read1 = $this->persister->readFailureCapsule($runId, 1);
        $this->assertInstanceOf(FailureCapsule::class, $read0);
        $this->assertInstanceOf(FailureCapsule::class, $read1);
        $this->assertSame(FailureCapsule::DECISION_RETRY, $read0->decision);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
