<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use JsonSerializable;
use RuntimeException;

final class AtlasLoopScopeOriginationReceiptLedger
{
    /**
     * @param  list<array{fact_id:string,source:string,snapshot_hash:string}>  $factRefs
     */
    public function __construct(
        private readonly string $path,
        private readonly string $snapshotHash,
        private readonly string $proposalHash,
        private readonly array $factRefs,
        private readonly ?string $timestamp = null,
    ) {
    }

    public static function forStorage(
        string $snapshotHash,
        string $proposalHash,
        array $factRefs,
        ?string $timestamp = null,
    ): self {
        return new self(
            path: storage_path('atlas/autopoiesis/scope-origination/receipts.jsonl'),
            snapshotHash: $snapshotHash,
            proposalHash: $proposalHash,
            factRefs: $factRefs,
            timestamp: $timestamp,
        );
    }

    public function record(ScopeOriginationVerdict $v): ReceiptId
    {
        $resolvedTimestamp = $v->timestamp ?? $this->timestamp;
        $receipt = new ScopeOriginationReceipt(
            receiptId: $this->receiptId($v, $resolvedTimestamp),
            snapshotHash: $this->snapshotHash,
            proposalHash: $this->proposalHash,
            verdict: $v->verdict,
            actor: $v->actor,
            timestamp: $resolvedTimestamp,
            reason: $v->reason,
            factRefs: $this->factRefs,
        );

        $this->append($receipt->toArray());

        return new ReceiptId($receipt->receiptId);
    }

    /**
     * @return list<ScopeOriginationReceipt>
     */
    public function list(?string $since = null): array
    {
        $receipts = [];
        foreach ($this->rows() as $row) {
            $receipt = ScopeOriginationReceipt::fromArray($row);
            if ($since !== null && ($receipt->timestamp ?? '') < $since) {
                continue;
            }

            $receipts[] = $receipt;
        }

        return $receipts;
    }

    public function latest(): ?ScopeOriginationReceipt
    {
        $receipts = $this->list();

        return $receipts === [] ? null : $receipts[count($receipts) - 1];
    }

    public function getByProposalHash(string $proposalHash): ?ScopeOriginationReceipt
    {
        foreach ($this->list() as $receipt) {
            if ($receipt->proposalHash === $proposalHash) {
                return $receipt;
            }
        }

        return null;
    }

    public function mutate(string $receiptId, array $payload): never
    {
        throw new AppendOnlyViolationException('scope_origination_receipts_are_append_only');
    }

    public function delete(string $receiptId): never
    {
        throw new AppendOnlyViolationException('scope_origination_receipts_are_append_only');
    }

    public function replace(string $receiptId, ScopeOriginationReceipt $receipt): never
    {
        throw new AppendOnlyViolationException('scope_origination_receipts_are_append_only');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create scope origination receipt ledger directory: '.$dir);
        }

        $handle = fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open scope origination receipt ledger: '.$this->path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock scope origination receipt ledger: '.$this->path);
            }

            fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $rows = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    private function receiptId(ScopeOriginationVerdict $v, ?string $resolvedTimestamp): string
    {
        $verdictArray = $v->toArray();
        $verdictArray['timestamp'] = $resolvedTimestamp;

        return 'scope-origin:'.substr(hash('sha256', json_encode([
            'snapshot_hash' => $this->snapshotHash,
            'proposal_hash' => $this->proposalHash,
            'verdict' => $verdictArray,
            'fact_refs' => $this->factRefs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);
    }
}

final class ReceiptId implements JsonSerializable
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return array{value:string}
     */
    public function jsonSerialize(): array
    {
        return ['value' => $this->value];
    }
}

final class AppendOnlyViolationException extends RuntimeException
{
}
