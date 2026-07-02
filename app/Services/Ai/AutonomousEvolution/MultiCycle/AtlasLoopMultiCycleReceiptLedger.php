<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\MultiCycle;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Closure;

final class AtlasLoopMultiCycleReceiptLedger
{
    /**
     * @param  ?Closure():string  $clock
     * @param  ?Closure():string  $receiptIdGenerator
     */
    public function __construct(
        private readonly ?string $ledgerPath = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $receiptIdGenerator = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(string $eventType, string $cycleId, string $subScopeHash, array $payload): string
    {
        $receiptId = $this->receiptId();
        $row = [
            'schema_version' => 'atlas.decision_receipt.v2',
            'receipt_id' => $receiptId,
            'event_type' => $eventType,
            'cycle_id' => $cycleId,
            'sub_scope_hash' => $subScopeHash,
            'ts' => $this->now(),
            'payload' => $payload,
        ];

        (new JsonlReceiptStore($this->ledgerPath()))->append($row);

        return $receiptId;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    public function history(?string $cycleId = null): iterable
    {
        $rows = [];
        foreach ((new JsonlReceiptStore($this->ledgerPath()))->replay() as $decoded) {
            if ($cycleId !== null && (string) ($decoded['cycle_id'] ?? '') !== $cycleId) {
                continue;
            }

            $rows[] = $decoded;
        }

        return $rows;
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath ?? storage_path('atlas/loop/multicycle/receipts.ndjson');
    }

    private function receiptId(): string
    {
        if ($this->receiptIdGenerator !== null) {
            return ($this->receiptIdGenerator)();
        }

        return bin2hex(random_bytes(16));
    }

    private function now(): string
    {
        return $this->clock !== null
            ? ($this->clock)()
            : date(DATE_ATOM);
    }
}
