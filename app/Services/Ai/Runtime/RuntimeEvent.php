<?php

namespace App\Services\Ai\Runtime;

class RuntimeEvent
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly string $content = '',
        public readonly array $metadata = [],
        public readonly ?string $occurredAt = null,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function make(string $type, string $message, string $content = '', array $metadata = []): self
    {
        return new self($type, $message, $content, $metadata, now()->toJSON());
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
