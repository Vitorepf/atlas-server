<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Promotion;

use App\Services\Ai\Foundry\Frontier\Promotion\FoundryPromotedBacklogReaderService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;

/**
 * Finding 25 fix: the AP-D promoted backlog was write-only. This proves the
 * read-only seam returns un-consumed promoted_parent_finding records for the
 * EXISTING FindingSlicePlannerService, while preserving I4 (no-self-
 * canonization: zero writes, zero synthesized lines), plan-delivery cert
 * (terminal state read from the AP-E outcomes tracker, never asserted by the
 * reader), and real-or-blocked (absent/empty ledger => []).
 */
final class FoundryPromotedBacklogReaderTest extends TestCase
{
    private string $dir;

    private string $areaId = 'agentic_engineering_os';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas_promoted_backlog_reader_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function reader(): FoundryPromotedBacklogReaderService
    {
        $reader = new FoundryPromotedBacklogReaderService();
        $reader->setStorageDirForTesting($this->dir);

        return $reader;
    }

    private function scopeDir(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.$this->areaId;
    }

    /** @param list<array<string,mixed>> $records */
    private function writeJsonl(string $file, array $records): void
    {
        $dir = $this->scopeDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lines = '';
        foreach ($records as $record) {
            $lines .= json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL;
        }
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, $lines);
    }

    /** @return array<string,mixed> */
    private function backlogRecord(string $promotedHash, string $parentFindingId): array
    {
        return [
            'origin' => 'operator_promoted_inbox_survivor',
            'area_id' => $this->areaId,
            'focus' => 'dev_forge',
            'promoted_finding_hash' => $promotedHash,
            'promoted_parent_finding' => [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
                'finding_id' => $parentFindingId,
                'finding_hash' => $promotedHash,
                'area_id' => $this->areaId,
                'focus' => 'dev_forge',
                'title' => 'Operator-promoted AFEF survivor',
                'detail' => 'detail',
                'severity' => 'medium',
                'owner_candidate' => 'atlas_dev',
                'spec_seed' => ['success_metric' => ['property' => 'p', 'measure_cmd' => 'echo 1']],
            ],
            'first_packet_finding' => [],
            'decision_id' => 'dec-1',
            'source_finding_hash' => 'sha256:src',
        ];
    }

    public function test_absent_ledger_returns_empty(): void
    {
        self::assertSame([], $this->reader()->unconsumedPromotedFindings($this->areaId));
    }

    public function test_empty_ledger_returns_empty(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, []);

        self::assertSame([], $this->reader()->unconsumedPromotedFindings($this->areaId));
    }

    public function test_unconsumed_promoted_finding_is_returned_ready_for_planner(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
        ]);
        // No outcomes ledger at all => nothing terminal.

        $out = $this->reader()->unconsumedPromotedFindings($this->areaId);

        self::assertCount(1, $out);
        // Shape the EXISTING FindingSlicePlannerService consumes: finding_hash +
        // finding_id + spec_seed present.
        self::assertSame('afef_promoted_aaaa', $out[0]['finding_id']);
        self::assertSame('sha256:promoted-A', $out[0]['finding_hash']);
        self::assertArrayHasKey('spec_seed', $out[0]);
    }

    public function test_finding_with_terminal_outcome_is_excluded(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
        ]);
        // AP-E outcome whose packet finding_id resolves to the parent, action terminal.
        $this->writeJsonl(FoundryPromotedBacklogReaderService::OUTCOMES_FILE, [
            ['action' => 'consolidate', 'finding_id' => 'afef_promoted_aaaa::packet::1'],
        ]);

        self::assertSame([], $this->reader()->unconsumedPromotedFindings($this->areaId));
    }

    public function test_reverted_is_also_terminal(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
        ]);
        $this->writeJsonl(FoundryPromotedBacklogReaderService::OUTCOMES_FILE, [
            ['action' => 'reverted', 'finding_id' => 'afef_promoted_aaaa::packet::1'],
        ]);

        self::assertSame([], $this->reader()->unconsumedPromotedFindings($this->areaId));
    }

    public function test_non_terminal_outcome_does_not_consume(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
        ]);
        // An in-flight / non-terminal outcome action must NOT exclude.
        $this->writeJsonl(FoundryPromotedBacklogReaderService::OUTCOMES_FILE, [
            ['action' => 'measuring', 'finding_id' => 'afef_promoted_aaaa::packet::1'],
        ]);

        self::assertCount(1, $this->reader()->unconsumedPromotedFindings($this->areaId));
    }

    public function test_mixed_consumed_and_unconsumed(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
            $this->backlogRecord('sha256:promoted-B', 'afef_promoted_bbbb'),
        ]);
        $this->writeJsonl(FoundryPromotedBacklogReaderService::OUTCOMES_FILE, [
            ['action' => 'consolidate', 'finding_id' => 'afef_promoted_aaaa::packet::1'],
        ]);

        $out = $this->reader()->unconsumedPromotedFindings($this->areaId);

        self::assertCount(1, $out);
        self::assertSame('afef_promoted_bbbb', $out[0]['finding_id']);
    }

    public function test_reader_performs_no_writes_and_synthesizes_no_line(): void
    {
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [
            $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa'),
        ]);
        $backlogPath = $this->scopeDir().DIRECTORY_SEPARATOR.FoundryPromotedBacklogReaderService::BACKLOG_FILE;
        $before = file_get_contents($backlogPath);
        $beforeDirListing = scandir($this->scopeDir());

        // Multiple reads.
        $this->reader()->unconsumedPromotedFindings($this->areaId);
        $this->reader()->unconsumedPromotedFindings($this->areaId);

        // I4: read-only — ledger byte-identical, no new files (no outcomes
        // ledger synthesized, no extra backlog line).
        self::assertSame($before, file_get_contents($backlogPath));
        self::assertSame($beforeDirListing, scandir($this->scopeDir()));
    }

    public function test_returned_records_are_compiler_written_bodies_not_fabricated(): void
    {
        // real-or-blocked: every returned record is a verbatim echo of a
        // compiler-written promoted_parent_finding; the reader adds no field.
        $record = $this->backlogRecord('sha256:promoted-A', 'afef_promoted_aaaa');
        $this->writeJsonl(FoundryPromotedBacklogReaderService::BACKLOG_FILE, [$record]);

        $out = $this->reader()->unconsumedPromotedFindings($this->areaId);

        self::assertSame($record['promoted_parent_finding'], $out[0]);
    }

}
