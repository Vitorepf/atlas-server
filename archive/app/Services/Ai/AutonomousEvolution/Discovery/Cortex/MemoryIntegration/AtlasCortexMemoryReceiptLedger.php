<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

final class AtlasCortexMemoryReceiptLedger
{
    public function __construct(
        private readonly string $path = '',
    ) {}

    /**
     * @param  array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalToken?:?string,
     *     outcome:string
     * }  $receipt
     * @return array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalTokenHash:?string,
     *     outcome:string
     * }
     */
    public function append(array $receipt): array
    {
        $normalized = [
            'timestamp' => (string) ($receipt['timestamp'] ?? ''),
            'action' => (string) ($receipt['action'] ?? ''),
            'memoryEntryId' => (string) ($receipt['memoryEntryId'] ?? ''),
            'groundingStatus' => (string) ($receipt['groundingStatus'] ?? ''),
            'approvalTokenHash' => is_string($receipt['approvalToken'] ?? null)
                ? hash('sha256', (string) $receipt['approvalToken'])
                : null,
            'outcome' => (string) ($receipt['outcome'] ?? ''),
        ];

        (new JsonlReceiptStore($this->path()))->append($normalized);

        return $normalized;
    }

    /**
     * @return list<array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalTokenHash:?string,
     *     outcome:string
     * }>
     */
    public function all(): array
    {
        return $this->readAll();
    }

    /**
     * @return list<array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalTokenHash:?string,
     *     outcome:string
     * }>
     */
    public function queryByMemoryEntryId(string $memoryEntryId): array
    {
        return array_values(array_filter(
            $this->readAll(),
            static fn (array $receipt): bool => $receipt['memoryEntryId'] === $memoryEntryId,
        ));
    }

    /**
     * @return list<array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalTokenHash:?string,
     *     outcome:string
     * }>
     */
    public function queryByTimeRange(string $fromIso, string $toIso): array
    {
        $from = strtotime($fromIso);
        $to = strtotime($toIso);

        return array_values(array_filter(
            $this->readAll(),
            static function (array $receipt) use ($from, $to): bool {
                $timestamp = strtotime($receipt['timestamp']);

                return $timestamp !== false
                    && $from !== false
                    && $to !== false
                    && $timestamp >= $from
                    && $timestamp <= $to;
            },
        ));
    }

    private function path(): string
    {
        return $this->path !== ''
            ? $this->path
            : storage_path('app/atlas/cortex-memory-receipts.jsonl');
    }

    /**
     * @return list<array{
     *     timestamp:string,
     *     action:string,
     *     memoryEntryId:string,
     *     groundingStatus:string,
     *     approvalTokenHash:?string,
     *     outcome:string
     * }>
     */
    private function readAll(): array
    {
        $rows = [];
        // replay() skips corrupt/empty/non-array lines and handles a missing file — identical
        // to the prior inline loop; the row normalization stays here.
        foreach ((new JsonlReceiptStore($this->path()))->replay() as $decoded) {
            $rows[] = [
                'timestamp' => (string) ($decoded['timestamp'] ?? ''),
                'action' => (string) ($decoded['action'] ?? ''),
                'memoryEntryId' => (string) ($decoded['memoryEntryId'] ?? ''),
                'groundingStatus' => (string) ($decoded['groundingStatus'] ?? ''),
                'approvalTokenHash' => is_string($decoded['approvalTokenHash'] ?? null) ? $decoded['approvalTokenHash'] : null,
                'outcome' => (string) ($decoded['outcome'] ?? ''),
            ];
        }

        return $rows;
    }
}
