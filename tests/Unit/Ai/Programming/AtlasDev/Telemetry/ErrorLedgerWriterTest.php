<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Telemetry;

use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;
use PHPUnit\Framework\TestCase;

final class ErrorLedgerWriterTest extends TestCase
{
    private string $tmpDir;
    private ErrorLedgerWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-error-ledger-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $persister = new GenericArtifactPersister(new ReceiptStorage($this->tmpDir));
        $this->writer = new ErrorLedgerWriter($persister);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function test_should_record_only_for_relevant_states(): void
    {
        $this->assertFalse($this->writer->shouldRecord(CompletionSummary::STATUS_PASSED));
        $this->assertFalse($this->writer->shouldRecord(CompletionSummary::STATUS_NO_PATCH_NEEDED));
        $this->assertTrue($this->writer->shouldRecord(CompletionSummary::STATUS_FAILED));
        $this->assertTrue($this->writer->shouldRecord(CompletionSummary::STATUS_NEEDS_REVIEW));
        $this->assertTrue($this->writer->shouldRecord(CompletionSummary::STATUS_BLOCKED));
        $this->assertTrue($this->writer->shouldRecord(CompletionSummary::STATUS_ESCALATE_FORGE));
    }

    public function test_append_only_assigns_monotonic_versions(): void
    {
        $a = $this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE);
        $b = $this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR);

        $rA = $this->writer->append($a);
        $rB = $this->writer->append($b);

        $this->assertSame(1, $rA['version']);
        $this->assertSame(2, $rB['version']);
        $this->assertSame(2, $this->writer->entryCountFor('run-1'));
    }

    public function test_read_returns_entries_in_version_order(): void
    {
        $a = $this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE);
        $b = $this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR);
        $c = $this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_OTHER);
        $this->writer->append($a);
        $this->writer->append($b);
        $this->writer->append($c);

        $read = $this->writer->read('run-1');
        $this->assertCount(3, $read);
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE, $read[0]->actualFailureMode);
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR, $read[1]->actualFailureMode);
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_OTHER, $read[2]->actualFailureMode);
    }

    public function test_runs_are_isolated(): void
    {
        $this->writer->append($this->makeEntry('run-1', FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE));
        $this->writer->append($this->makeEntry('run-2', FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR));

        $this->assertSame(1, $this->writer->entryCountFor('run-1'));
        $this->assertSame(1, $this->writer->entryCountFor('run-2'));
    }

    private function makeEntry(string $runId, string $mode): FastPathErrorLedgerEntry
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

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
