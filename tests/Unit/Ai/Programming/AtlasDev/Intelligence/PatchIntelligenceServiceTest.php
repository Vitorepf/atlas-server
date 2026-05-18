<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;
use Tests\TestCase;

class PatchIntelligenceServiceTest extends TestCase
{
    public function test_low_risk_small_focused_patch_with_no_unexpected_files(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-1',
            taskContractHash: 'task-hash-1',
            expectedFiles: ['app/Services/Foo.php'],
            changedFiles: [
                $this->diff('app/Services/Foo.php', added: 5, removed: 2),
            ],
        ));

        $this->assertSame(PatchIntelligenceReceipt::RISK_LOW, $receipt->riskLevel);
        $this->assertSame([], $receipt->unexpectedFiles);
        $this->assertSame([], $receipt->missingExpectedFiles);
        $this->assertSame(1, $receipt->blastRadius->fileCount);
        $this->assertSame(5, $receipt->blastRadius->linesAddedTotal);
        $this->assertSame(2, $receipt->blastRadius->linesRemovedTotal);
        $this->assertNotEmpty($receipt->rollbackHint);
        $this->assertNotSame('pending', $receipt->receiptHash);
    }

    public function test_medium_risk_when_unexpected_files_appear_below_high_threshold(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-2',
            taskContractHash: 'task-hash-2',
            expectedFiles: [], // no expectation -> avoids HIGH escalation from unexpected
            changedFiles: [
                $this->diff('app/Services/A.php', added: 10),
                $this->diff('app/Services/B.php', added: 15),
                $this->diff('app/Services/C.php', added: 20),
            ],
        ));

        $this->assertSame(PatchIntelligenceReceipt::RISK_MEDIUM, $receipt->riskLevel);
        $this->assertSame(3, $receipt->blastRadius->fileCount);
    }

    public function test_high_risk_when_many_files_or_high_risk_dir_or_unexpected_files(): void
    {
        $receiptDir = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-3a',
            taskContractHash: 'task-hash-3a',
            expectedFiles: ['database/migrations/2026_05_18_create_foo.php'],
            changedFiles: [
                $this->diff('database/migrations/2026_05_18_create_foo.php', added: 30),
            ],
        ));
        $this->assertSame(
            PatchIntelligenceReceipt::RISK_HIGH,
            $receiptDir->riskLevel,
            'touching database/migrations escalates to HIGH'
        );

        $receiptUnexpected = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-3b',
            taskContractHash: 'task-hash-3b',
            expectedFiles: ['app/Services/A.php'],
            changedFiles: [
                $this->diff('app/Services/A.php', added: 5),
                $this->diff('app/Services/B.php', added: 5),
            ],
        ));
        $this->assertSame(PatchIntelligenceReceipt::RISK_HIGH, $receiptUnexpected->riskLevel);
        $this->assertSame(['app/Services/B.php'], $receiptUnexpected->unexpectedFiles);

        $files = [];
        for ($i = 0; $i < 8; $i++) {
            $files[] = $this->diff("app/Services/F{$i}.php", added: 5);
        }
        $receiptMany = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-3c',
            taskContractHash: 'task-hash-3c',
            changedFiles: $files,
        ));
        $this->assertSame(PatchIntelligenceReceipt::RISK_HIGH, $receiptMany->riskLevel);
    }

    public function test_critical_risk_when_secret_file_touched(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-4',
            taskContractHash: 'task-hash-4',
            changedFiles: [
                $this->diff('.env', added: 1),
            ],
            evidenceRefs: [$this->evidenceRef()],
        ));

        $this->assertSame(PatchIntelligenceReceipt::RISK_CRITICAL, $receipt->riskLevel);
        $this->assertStringContainsString('CRITICAL', $receipt->rollbackHint);
    }

    public function test_critical_risk_when_user_pre_existing_change_was_overwritten(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-5',
            taskContractHash: 'task-hash-5',
            changedFiles: [
                $this->diff('app/Services/UserEdited.php', added: 4),
            ],
            userPreExistingChanges: [
                new ScopePreExistingChange(path: 'app/Services/UserEdited.php', preserved: false),
            ],
            evidenceRefs: [$this->evidenceRef()],
        ));

        $this->assertSame(PatchIntelligenceReceipt::RISK_CRITICAL, $receipt->riskLevel);
        $this->assertNotEmpty($receipt->userChangePreservationNotes);
        $this->assertStringContainsString(
            'overwritten by this patch',
            $receipt->userChangePreservationNotes[0]
        );
    }

    public function test_user_change_preservation_note_for_untouched_files(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-6',
            taskContractHash: 'task-hash-6',
            changedFiles: [
                $this->diff('app/Services/A.php', added: 3),
            ],
            userPreExistingChanges: [
                new ScopePreExistingChange(path: 'app/Services/OtherUserFile.php', preserved: true),
            ],
        ));

        $this->assertSame(PatchIntelligenceReceipt::RISK_LOW, $receipt->riskLevel);
        $this->assertCount(1, $receipt->userChangePreservationNotes);
        $this->assertStringContainsString('left untouched', $receipt->userChangePreservationNotes[0]);
    }

    public function test_rollback_hint_summarizes_when_many_files(): void
    {
        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = $this->diff("app/Services/X{$i}.php", added: 4);
        }
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-7',
            taskContractHash: 'task-hash-7',
            changedFiles: $files,
        ));

        $this->assertStringContainsString('4 of 6 shown', $receipt->rollbackHint);
    }

    public function test_critical_receipt_requires_evidence_refs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_refs must not be empty when risk_level is critical');

        $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-8',
            taskContractHash: 'task-hash-8',
            changedFiles: [$this->diff('.env', added: 1)],
            // no evidence refs -> the receipt constructor enforces invariant
        ));
    }

    public function test_receipt_hash_is_deterministic_and_canonical(): void
    {
        $input = new PatchIntelligenceInput(
            runId: 'run-9',
            taskContractHash: 'task-hash-9',
            expectedFiles: ['app/Services/A.php'],
            changedFiles: [$this->diff('app/Services/A.php', added: 3)],
        );

        $first = $this->service()->analyze($input);
        $second = $this->service()->analyze($input);

        $this->assertSame($first->receiptHash, $second->receiptHash);
        $this->assertSame($first->toJson(), $second->toJson());
    }

    public function test_blast_radius_collects_top_level_dirs(): void
    {
        $receipt = $this->service()->analyze(new PatchIntelligenceInput(
            runId: 'run-10',
            taskContractHash: 'task-hash-10',
            changedFiles: [
                $this->diff('app/Services/A.php', added: 3),
                $this->diff('app/Models/B.php', added: 4),
                $this->diff('tests/Unit/CTest.php', added: 5),
            ],
        ));

        $this->assertSame(
            ['app/Models', 'app/Services', 'tests/Unit'],
            $receipt->blastRadius->touchedDirs
        );
    }

    private function service(): PatchIntelligenceService
    {
        return new PatchIntelligenceService;
    }

    private function diff(string $path, int $added = 0, int $removed = 0): ScopeFileDiff
    {
        return new ScopeFileDiff(
            path: $path,
            added: $added,
            removed: $removed,
            fileHashAfter: 'sha256:'.hash('sha256', $path.':'.$added.':'.$removed),
        );
    }

    private function evidenceRef(): EvidenceRef
    {
        return new EvidenceRef(
            kind: 'manual_review',
            path: 'storage/atlas-dev/receipts/run/evidence.json',
            hash: 'sha256:test',
        );
    }
}
