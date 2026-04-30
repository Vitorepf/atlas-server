<?php

namespace App\Services\Ai\Runtime;

use Illuminate\Support\Str;

class RuntimeSession
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $workspace,
        public readonly string $permissionMode,
        public readonly ?string $threadId = null,
        public readonly ?string $profileCacheKey = null,
        public readonly array $metadata = [],
        public readonly ?string $startedAt = null,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function start(string $workspace, string $permissionMode = 'read', ?string $threadId = null, ?string $profileCacheKey = null, array $metadata = []): self
    {
        return new self(
            id: (string) Str::orderedUuid(),
            workspace: realpath($workspace) ?: $workspace,
            permissionMode: $permissionMode,
            threadId: $threadId,
            profileCacheKey: $profileCacheKey,
            metadata: $metadata,
            startedAt: now()->toJSON(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace' => $this->workspace,
            'permission_mode' => $this->permissionMode,
            'thread_id' => $this->threadId,
            'profile_cache_key' => $this->profileCacheKey,
            'metadata' => $this->metadata,
            'started_at' => $this->startedAt,
        ];
    }
}
