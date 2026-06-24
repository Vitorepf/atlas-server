<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * Immutable value object for ONE operator intent message ingested from the append-only intent stream. The id is
 * the sha256 of the raw JSONL line, so it is stable and byte-deterministic across re-reads.
 */
final class OperatorIntentMessage
{
    public function __construct(
        public readonly string $id,
        public readonly int $ts,
        public readonly string $author,
        public readonly string $rawText,
        public readonly string $source,
    ) {}

    /**
     * @return array{id:string, ts:int, author:string, raw_text:string, source:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ts' => $this->ts,
            'author' => $this->author,
            'raw_text' => $this->rawText,
            'source' => $this->source,
        ];
    }
}
