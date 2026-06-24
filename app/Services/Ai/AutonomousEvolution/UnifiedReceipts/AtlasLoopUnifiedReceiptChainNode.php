<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;

use JsonSerializable;

final readonly class AtlasLoopUnifiedReceiptChainNode implements JsonSerializable
{
    public function __construct(
        public string $node_id,
        public string $source_ledger,
        public string $source_receipt_id,
        public string $source_facts_json,
        public string $prev_hash,
        public string $payload_hash,
        public string $node_hash,
        public int $recorded_at,
        public int $seq,
    ) {}

    /**
     * @return array{
     *   node_hash:string,
     *   node_id:string,
     *   payload_hash:string,
     *   prev_hash:string,
     *   recorded_at:int,
     *   seq:int,
     *   source_facts_json:string,
     *   source_ledger:string,
     *   source_receipt_id:string
     * }
     */
    public function toArray(): array
    {
        return [
            'node_hash' => $this->node_hash,
            'node_id' => $this->node_id,
            'payload_hash' => $this->payload_hash,
            'prev_hash' => $this->prev_hash,
            'recorded_at' => $this->recorded_at,
            'seq' => $this->seq,
            'source_facts_json' => $this->source_facts_json,
            'source_ledger' => $this->source_ledger,
            'source_receipt_id' => $this->source_receipt_id,
        ];
    }

    /**
     * @return array{
     *   node_hash:string,
     *   node_id:string,
     *   payload_hash:string,
     *   prev_hash:string,
     *   recorded_at:int,
     *   seq:int,
     *   source_facts_json:string,
     *   source_ledger:string,
     *   source_receipt_id:string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
