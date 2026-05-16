<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureModeClassifier;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairTelemetryRecorder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;
use PHPUnit\Framework\TestCase;

final class RepairTelemetryRecorderTest extends TestCase
{
    private string $tmpDir;
    private RepairTelemetryRecorder $recorder;
    private ErrorLedgerWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-repair-telemetry-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $persister = new GenericArtifactPersister(new ReceiptStorage($this->tmpDir));
        $this->writer = new ErrorLedgerWriter($persister);
        $this->recorder = new RepairTelemetryRecorder(
            writer: $this->writer,
            classifier: new FailureModeClassifier(),
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function test_record_skips_healthy_runs(): void
    {
        $capsule = $this->capsule();
        $signals = new ObservedSignals(1, 1, ['auth']);

        $this->assertNull(
            $this->recorder->recordIfWorthRecording(
                completionState: CompletionSummary::STATUS_PASSED,
                capsule: $capsule,
                signals: $signals,
                attemptCount: 1,
            ),
        );
    }

    public function test_record_persists_failed_run_with_classified_mode(): void
    {
        $capsule = $this->capsule(gate: 'verification_gate', failingTest: 'tests/FooTest::test_x');
        $signals = new ObservedSignals(1, 1, ['auth']);

        $result = $this->recorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_FAILED,
            capsule: $capsule,
            signals: $signals,
            attemptCount: 1,
            correctionRecommendation: ['inspect_failing_test'],
        );

        $this->assertNotNull($result);
        $this->assertSame(1, $result['version']);
        $entries = $this->writer->read($capsule->runId);
        $this->assertCount(1, $entries);
        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST,
            $entries[0]->actualFailureMode,
        );
        $this->assertSame(['inspect_failing_test'], $entries[0]->correctionRecommendation);
    }

    public function test_record_uses_same_signature_signal_to_classify_bad_repair(): void
    {
        $capsule = $this->capsule(gate: 'verification_gate');
        $signals = new ObservedSignals(2, 1, []);

        $result = $this->recorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_NEEDS_REVIEW,
            capsule: $capsule,
            signals: $signals,
            attemptCount: 2,
            escalationSignals: [FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE],
        );

        $this->assertNotNull($result);
        $entries = $this->writer->read($capsule->runId);
        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR,
            $entries[0]->actualFailureMode,
        );
    }

    public function test_record_is_append_only_with_monotonic_versions(): void
    {
        $capsule = $this->capsule(gate: 'verification_gate');
        $signals = new ObservedSignals(1, 1, []);

        $first = $this->recorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_FAILED,
            capsule: $capsule,
            signals: $signals,
            attemptCount: 1,
        );
        $second = $this->recorder->recordIfWorthRecording(
            completionState: CompletionSummary::STATUS_FAILED,
            capsule: $capsule,
            signals: $signals,
            attemptCount: 2,
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $first['version']);
        $this->assertSame(2, $second['version']);
        $this->assertCount(2, $this->writer->read($capsule->runId));
    }

    private function capsule(
        string $gate = 'verification_gate',
        ?string $failingTest = null,
    ): FailureCapsule {
        return FailureCapsule::issue(
            runId: 'run-tel-1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: $gate,
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'AssertionError: expected 1 got 0',
            fullErrorLogPath: null,
            failingTest: $failingTest,
            diffHash: null,
            changedFiles: ['app/Foo.php'],
            decision: FailureCapsule::DECISION_RETRY,
        );
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
