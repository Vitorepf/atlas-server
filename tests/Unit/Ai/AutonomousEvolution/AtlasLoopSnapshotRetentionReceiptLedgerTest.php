<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionReceiptLedger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AtlasLoopSnapshotRetentionReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-loop-retention-ledger-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function advisory(string $scannedAt): array
    {
        return [
            'schema_version' => 'atlas.loop.snapshot_retention_gc_advisory.v1',
            'kept_ids' => ['s-3', 's-2', 's-1'],
            'prune_candidate_ids' => ['s-0'],
            'policy_fingerprint' => 'policy_xyz',
            'scanned_at' => $scannedAt,
            'totals' => ['kept' => 3, 'prune_candidate' => 1],
        ];
    }

    public function test_record_appends_two_distinct_lines_without_overwriting_prior_line(): void
    {
        $ledger = new AtlasLoopSnapshotRetentionReceiptLedger($this->root);

        $r1 = $ledger->record($this->advisory('2026-06-25T00:00:00Z'));
        $file = $this->root.'/2026-06-25.jsonl';
        self::assertFileExists($file);
        $sizeAfterFirst = filesize($file);
        $bytesAfterFirst = file_get_contents($file);

        $r2 = $ledger->record($this->advisory('2026-06-25T00:00:01Z'));

        self::assertNotSame($r1['receipt_id'], $r2['receipt_id']);
        $sizeAfterSecond = filesize($file);
        self::assertGreaterThan($sizeAfterFirst, $sizeAfterSecond, 'file size must strictly grow');

        $bytesAfterSecond = (string) file_get_contents($file);
        self::assertSame(
            $bytesAfterFirst,
            substr($bytesAfterSecond, 0, strlen((string) $bytesAfterFirst)),
            'first line must be byte-identical after the second record()',
        );
        self::assertCount(2, $ledger->readAll());
    }

    public function test_class_exposes_only_append_shaped_public_methods(): void
    {
        $reflection = new ReflectionClass(AtlasLoopSnapshotRetentionReceiptLedger::class);
        $publicNames = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== AtlasLoopSnapshotRetentionReceiptLedger::class) {
                continue;
            }
            $publicNames[] = $method->getName();
        }
        sort($publicNames);

        self::assertSame(['fingerprint', 'readAll', 'readByDay', 'record'], $publicNames);
        foreach (['update', 'delete', 'truncate', 'clear'] as $forbidden) {
            self::assertNotContains($forbidden, $publicNames, "ledger must not expose {$forbidden}()");
        }
    }

    public function test_read_by_day_returns_only_that_day(): void
    {
        $ledger = new AtlasLoopSnapshotRetentionReceiptLedger($this->root);
        $ledger->record($this->advisory('2026-06-24T12:00:00Z'));
        $ledger->record($this->advisory('2026-06-25T01:00:00Z'));
        $ledger->record($this->advisory('2026-06-25T02:00:00Z'));

        self::assertCount(1, $ledger->readByDay('2026-06-24'));
        self::assertCount(2, $ledger->readByDay('2026-06-25'));
        self::assertCount(0, $ledger->readByDay('2026-06-30'));
    }

    public function test_receipt_carries_decision_receipt_v2_fields(): void
    {
        $ledger = new AtlasLoopSnapshotRetentionReceiptLedger($this->root);
        $receipt = $ledger->record($this->advisory('2026-06-25T00:00:00Z'));

        foreach (['receipt_id', 'scanned_at', 'policy_fingerprint', 'kept_count', 'prune_candidate_count', 'prune_candidate_ids', 'advisory_only'] as $field) {
            self::assertArrayHasKey($field, $receipt);
        }
        self::assertTrue($receipt['advisory_only']);
        self::assertSame(3, $receipt['kept_count']);
        self::assertSame(1, $receipt['prune_candidate_count']);
        self::assertSame(['s-0'], $receipt['prune_candidate_ids']);
    }

    public function test_ledger_fingerprint_is_stable_and_changes_after_append(): void
    {
        $ledger = new AtlasLoopSnapshotRetentionReceiptLedger($this->root);
        $f0 = $ledger->fingerprint();
        $ledger->record($this->advisory('2026-06-25T00:00:00Z'));
        $f1 = $ledger->fingerprint();
        $ledger->record($this->advisory('2026-06-25T00:00:01Z'));
        $f2 = $ledger->fingerprint();

        self::assertNotSame($f0, $f1);
        self::assertNotSame($f1, $f2);
    }
}
