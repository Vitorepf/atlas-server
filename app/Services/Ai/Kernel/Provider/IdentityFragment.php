<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class IdentityFragment
{
    public function __construct(
        public string $identityId,
        public string $contentHash,
        public string $text,
    ) {}

    public static function fromText(string $identityId, string $text): self
    {
        return new self(
            identityId: trim($identityId) !== '' ? trim($identityId) : 'atlas-ai.identity',
            contentHash: hash('sha256', $text),
            text: $text,
        );
    }
}
