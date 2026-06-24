<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionAuditReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AutopoieticConstitutionReceipt;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopAutopoieticConstitutionAuditReceiptLedgerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-autopoietic-constitution-receipts-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_append_persists_one_jsonl_line_and_duplicate_is_refused_without_extra_write(): void
    {
        $ledger = $this->ledger();
        $receipt = $this->receipt('01K00000000000000000000001');

        $ledger->append($receipt);
        $this->assertCount(1, $this->lines($ledger));

        try {
            $ledger->append($receipt);
            $this->fail('Expected duplicate receipt refusal.');
        } catch (DomainException $exception) {
            $this->assertSame('constitution_receipt_id_duplicate', $exception->getMessage());
        }

        $this->assertCount(1, $this->lines($ledger));
    }

    public function test_out_of_order_receipt_id_is_refused(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt('01K00000000000000000000010'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('constitution_receipt_id_out_of_order');
        $ledger->append($this->receipt('01K00000000000000000000009'));
    }

    public function test_all_returns_receipts_in_append_order(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt('01K00000000000000000000001'));
        $ledger->append($this->receipt('01K00000000000000000000002'));
        $ledger->append($this->receipt('01K00000000000000000000003'));

        $this->assertSame(
            ['01K00000000000000000000001', '01K00000000000000000000002', '01K00000000000000000000003'],
            array_map(static fn (AutopoieticConstitutionReceipt $receipt): string => $receipt->receiptId, $ledger->all()),
        );
    }

    public function test_no_public_update_delete_truncate_or_reset_methods_exist(): void
    {
        $reflection = new ReflectionClass(AtlasLoopAutopoieticConstitutionAuditReceiptLedger::class);

        foreach ($reflection->getMethods() as $method) {
            if ($method->class !== AtlasLoopAutopoieticConstitutionAuditReceiptLedger::class) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/update|delete|truncate|reset/i', $method->getName());
        }
    }

    public function test_tight_loop_appends_round_trip_as_well_formed_jsonl(): void
    {
        $ledger = $this->ledger();
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $id = sprintf('01K00000000000000000000%03d', $i);
            $ids[] = $id;
            $ledger->append($this->receipt($id));
        }

        $lines = $this->lines($ledger);
        $this->assertCount(20, $lines);
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }

        foreach ($ids as $id) {
            $this->assertSame($id, $ledger->findByReceiptId($id)?->receiptId);
        }
    }

    private function ledger(): AtlasLoopAutopoieticConstitutionAuditReceiptLedger
    {
        return new AtlasLoopAutopoieticConstitutionAuditReceiptLedger($this->tmpDir.'/constitution_receipts.jsonl');
    }

    private function receipt(string $id): AutopoieticConstitutionReceipt
    {
        return new AutopoieticConstitutionReceipt(
            receiptId: $id,
            recordedAt: '2026-06-24T12:00:00Z',
            actionCategory: 'scope_origination_admit',
            targetScope: 'modules/AutopoiesisDemo',
            operatorSignature: 'operator:approved',
            priorFingerprint: str_repeat('a', 64),
            payloadHash: hash('sha256', $id),
        );
    }

    /**
     * @return list<string>
     */
    private function lines(AtlasLoopAutopoieticConstitutionAuditReceiptLedger $ledger): array
    {
        if (! is_file($ledger->path())) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($ledger->path())))));
    }
}
