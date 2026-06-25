<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

/**
 * Append-only ledger of task-class lifecycle events. Each receipt is tamper-evident via a
 * content-hash chain (prev_hash → this_hash). Sequence numbers are monotonic.
 *
 * Provider-safe by construction: never persists provider id, prompt, or trace fields supplied in
 * payloads — they are stripped before write.
 */
final class AtlasLoopTaskClassReceiptLedger
{
    public const PROVIDER_UNSAFE_KEYS = ['provider_id', 'prompt', 'trace', 'tokens', 'cost_usd'];

    /** @var list<AtlasLoopTaskClassReceipt> */
    private array $receipts = [];

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $evidenceIds
     */
    public function append(string $eventKind, array $payload, array $evidenceIds = [], ?string $classId = null): AtlasLoopTaskClassReceipt
    {
        $sanitized = $this->stripProviderUnsafe($payload);
        $seq = count($this->receipts) + 1;
        $prevHash = $this->receipts === [] ? str_repeat('0', 64) : $this->receipts[count($this->receipts) - 1]->thisHash;
        $body = [
            'class_id' => $classId,
            'event_kind' => $eventKind,
            'evidence_ids' => array_values($evidenceIds),
            'payload' => $sanitized,
            'prev_hash' => $prevHash,
            'seq' => $seq,
        ];
        $thisHash = $this->bodyHash($body);
        $receipt = new AtlasLoopTaskClassReceipt(
            receiptId: $thisHash,
            seq: $seq,
            eventKind: $eventKind,
            classId: $classId,
            payload: $sanitized,
            evidenceIds: array_values($evidenceIds),
            prevHash: $prevHash,
            thisHash: $thisHash,
            recordedAtUnix: time(),
        );
        $this->receipts[] = $receipt;

        return $receipt;
    }

    /**
     * @return iterable<AtlasLoopTaskClassReceipt>
     */
    public function history(string $classId): iterable
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->classId === $classId) {
                yield $receipt;
            }
        }
    }

    public function verifyChain(): bool
    {
        $prevHash = str_repeat('0', 64);
        foreach ($this->receipts as $i => $receipt) {
            $body = [
                'class_id' => $receipt->classId,
                'event_kind' => $receipt->eventKind,
                'evidence_ids' => $receipt->evidenceIds,
                'payload' => $receipt->payload,
                'prev_hash' => $receipt->prevHash,
                'seq' => $receipt->seq,
            ];
            if ($receipt->thisHash !== $this->bodyHash($body)) {
                return false;
            }
            if ($receipt->prevHash !== $prevHash) {
                return false;
            }
            if ($receipt->seq !== $i + 1) {
                return false;
            }
            $prevHash = $receipt->thisHash;
        }

        return true;
    }

    /**
     * @return list<AtlasLoopTaskClassReceipt>
     */
    public function all(): array
    {
        return $this->receipts;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stripProviderUnsafe(array $payload): array
    {
        foreach (self::PROVIDER_UNSAFE_KEYS as $key) {
            unset($payload[$key]);
        }
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = $this->stripProviderUnsafe($v);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function bodyHash(array $body): string
    {
        $canonical = $body;
        ksort($canonical);
        foreach ($canonical as &$v) {
            if (is_array($v)) {
                ksort($v);
            }
        }
        unset($v);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
