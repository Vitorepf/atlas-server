<?php

namespace App\Services\Ai\Search;

use DateTimeInterface;

class SearchResult
{
    public function __construct(
        public readonly string $threadId,
        public readonly string $threadTitle,
        public readonly ?DateTimeInterface $lastMessageAt,
        public readonly string $excerpt,
        public readonly float $rank,
        public readonly ?int $matchPosition = null,
        public readonly string $source = 'ai_messages',
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'thread_title' => $this->threadTitle,
            'last_message_at' => $this->lastMessageAt?->format(DateTimeInterface::ATOM),
            'excerpt' => $this->excerpt,
            'rank' => $this->rank,
            'match_position' => $this->matchPosition,
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }
}

