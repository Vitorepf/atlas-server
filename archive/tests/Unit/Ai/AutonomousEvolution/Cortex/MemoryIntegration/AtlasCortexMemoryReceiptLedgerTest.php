<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MemoryIntegration;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryReceiptLedger;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class AtlasCortexMemoryReceiptLedgerTest extends TestCase
{
    #[Test]
    public function it_reads_back_the_same_receipts_in_append_order(): void
    {
        $path = $this->ledgerPath();
        $ledger = new AtlasCortexMemoryReceiptLedger($path);

        $first = $ledger->append($this->receipt('2026-06-24T06:00:00+00:00', 'read', 'mem-1', null, 'seen'));
        $second = $ledger->append($this->receipt('2026-06-24T06:01:00+00:00', 'ground', 'mem-2', 'token-2', 'grounded'));

        $reloaded = new AtlasCortexMemoryReceiptLedger($path);

        self::assertSame([$first, $second], $reloaded->all());
    }

    #[Test]
    public function it_exposes_no_public_update_or_delete_api(): void
    {
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(AtlasCortexMemoryReceiptLedger::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertNotContains('update', $publicMethods);
        self::assertNotContains('delete', $publicMethods);
        self::assertNotContains('remove', $publicMethods);
    }

    #[Test]
    public function it_queries_by_memory_entry_id_and_time_range_in_timestamp_order(): void
    {
        $ledger = new AtlasCortexMemoryReceiptLedger($this->ledgerPath());
        $ledger->append($this->receipt('2026-06-24T06:00:00+00:00', 'read', 'mem-1', null, 'seen'));
        $ledger->append($this->receipt('2026-06-24T06:01:00+00:00', 'ground', 'mem-2', 'token-2', 'grounded'));
        $ledger->append($this->receipt('2026-06-24T06:02:00+00:00', 'propose', 'mem-1', 'token-3', 'queued'));

        $byMemory = $ledger->queryByMemoryEntryId('mem-1');
        $byTime = $ledger->queryByTimeRange('2026-06-24T06:01:00+00:00', '2026-06-24T06:02:00+00:00');

        self::assertSame(['mem-1', 'mem-1'], array_column($byMemory, 'memoryEntryId'));
        self::assertSame(['2026-06-24T06:01:00+00:00', '2026-06-24T06:02:00+00:00'], array_column($byTime, 'timestamp'));
    }

    /**
     * @return array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalToken:?string,
     *     outcome:string
     * }
     */
    private function receipt(string $timestamp, string $action, string $memoryEntryId, ?string $approvalToken, string $outcome): array
    {
        return [
            'timestamp' => $timestamp,
            'action' => $action,
            'memoryEntryId' => $memoryEntryId,
            'groundingStatus' => 'grounded',
            'approvalToken' => $approvalToken,
            'outcome' => $outcome,
        ];
    }

    private function ledgerPath(): string
    {
        $dir = sys_get_temp_dir().'/atlas-cortex-receipts-'.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir.'/receipts.jsonl';
    }
}
