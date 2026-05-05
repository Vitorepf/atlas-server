<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class IdentityFragment
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $identityId,
        public string $contentHash,
        public string $text,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function fromText(string $identityId, string $text, array $metadata = []): self
    {
        return new self(
            identityId: trim($identityId) !== '' ? trim($identityId) : 'atlas-ai.identity',
            contentHash: hash('sha256', $text),
            text: $text,
            metadata: $metadata,
        );
    }

    /**
     * @return array{identity_id:string,content_hash:string,text:string,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'identity_id' => $this->identityId,
            'content_hash' => $this->contentHash,
            'text' => $this->text,
            'metadata' => $this->metadata,
        ];
    }
}
