<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * APPEND-ONLY JSONL ledger of operator-intent FACTS + the downstream DECISION keyed to each. Tamper-evident:
 * receipt_id = sha256(fact_id|recorded_at|decision). Concurrent writers are serialized by the kernel
 * {@see JsonlReceiptStore} exclusive-lock append.
 *
 * Path: storage_path('atlas/quaternity/intent-receipts.jsonl'). Injectable via the ctor for tests.
 */
final class AtlasLoopOperatorIntentReceiptLedger
{
    private const RELATIVE_PATH = 'atlas/quaternity/intent-receipts.jsonl';

    private ?Closure $clock = null;

    public function __construct(private readonly ?string $path = null) {}

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function path(): string
    {
        return $this->path ?? storage_path(self::RELATIVE_PATH);
    }

    /**
     * Append one receipt for a fact + decision. Returns the persisted receipt VO.
     */
    public function record(string $factId, string $decision, string $reason, ?string $downstreamRef = null): IntentReceipt
    {
        $decision = trim($decision);
        if (! in_array($decision, IntentReceiptDecision::ALL, true)) {
            throw new InvalidArgumentException('unknown decision: '.$decision);
        }

        $recordedAt = $this->now()->toIso8601String();
        $receiptId = hash('sha256', $factId.'|'.$recordedAt.'|'.$decision);

        $receipt = new IntentReceipt(
            $receiptId,
            trim($factId),
            IntentReceipt::SCHEMA,
            $recordedAt,
            $decision,
            trim($reason),
            $downstreamRef !== null ? trim($downstreamRef) : null,
        );

        try {
            (new JsonlReceiptStore($this->path()))->append($receipt->toArray());
        } catch (Throwable) {
            // best-effort append preserved from the legacy @file_put_contents path
        }

        return $receipt;
    }

    /**
     * History in insertion order. When $factId is null, returns ALL receipts in the file.
     *
     * @return list<IntentReceipt>
     */
    public function history(?string $factId = null): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (! is_array($decoded)) {
                continue;
            }
            if ($factId !== null && (string) ($decoded['fact_id'] ?? '') !== $factId) {
                continue;
            }
            $rows[] = new IntentReceipt(
                (string) ($decoded['receipt_id'] ?? ''),
                (string) ($decoded['fact_id'] ?? ''),
                (string) ($decoded['schema'] ?? IntentReceipt::SCHEMA),
                (string) ($decoded['recorded_at'] ?? ''),
                (string) ($decoded['decision'] ?? ''),
                (string) ($decoded['decision_reason'] ?? ''),
                isset($decoded['downstream_ref']) ? (string) $decoded['downstream_ref'] : null,
            );
        }

        return $rows;
    }

    private function now(): CarbonImmutable
    {
        if ($this->clock !== null) {
            return CarbonImmutable::instance(($this->clock)())->utc();
        }

        return CarbonImmutable::now('UTC');
    }
}
