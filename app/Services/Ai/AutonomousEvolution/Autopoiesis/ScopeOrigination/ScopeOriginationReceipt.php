<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use JsonSerializable;

final class ScopeOriginationReceipt implements JsonSerializable
{
    public const SCHEMA = 'atlas.loop.scope_origination_receipt.v1';

    /**
     * @param  list<array{fact_id:string,source:string,snapshot_hash:string}>  $factRefs
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $snapshotHash,
        public readonly string $proposalHash,
        public readonly string $verdict,
        public readonly string $actor,
        public readonly ?string $timestamp,
        public readonly string $reason,
        public readonly array $factRefs,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            receiptId: (string) ($payload['receipt_id'] ?? ''),
            snapshotHash: (string) ($payload['snapshot_hash'] ?? ''),
            proposalHash: (string) ($payload['proposal_hash'] ?? ''),
            verdict: (string) ($payload['verdict'] ?? ''),
            actor: (string) ($payload['actor'] ?? ''),
            timestamp: isset($payload['timestamp']) ? (string) $payload['timestamp'] : null,
            reason: (string) ($payload['reason'] ?? ''),
            factRefs: self::factRefs((array) ($payload['fact_refs'] ?? [])),
        );
    }

    /**
     * @return array{schema:string,receipt_id:string,snapshot_hash:string,proposal_hash:string,verdict:string,actor:string,timestamp:?string,reason:string,fact_refs:list<array{fact_id:string,source:string,snapshot_hash:string}>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'receipt_id' => $this->receiptId,
            'snapshot_hash' => $this->snapshotHash,
            'proposal_hash' => $this->proposalHash,
            'verdict' => $this->verdict,
            'actor' => $this->actor,
            'timestamp' => $this->timestamp,
            'reason' => $this->reason,
            'fact_refs' => self::factRefs($this->factRefs),
        ];
    }

    /**
     * @return array{schema:string,receipt_id:string,snapshot_hash:string,proposal_hash:string,verdict:string,actor:string,timestamp:?string,reason:string,fact_refs:list<array{fact_id:string,source:string,snapshot_hash:string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<int|string,mixed>  $factRefs
     * @return list<array{fact_id:string,source:string,snapshot_hash:string}>
     */
    private static function factRefs(array $factRefs): array
    {
        $normalized = [];
        foreach ($factRefs as $factRef) {
            if (! is_array($factRef)) {
                continue;
            }

            $normalized[] = [
                'fact_id' => (string) ($factRef['fact_id'] ?? ''),
                'source' => (string) ($factRef['source'] ?? ''),
                'snapshot_hash' => (string) ($factRef['snapshot_hash'] ?? ''),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return [$left['source'], $left['fact_id'], $left['snapshot_hash']]
                <=> [$right['source'], $right['fact_id'], $right['snapshot_hash']];
        });

        return $normalized;
    }
}
