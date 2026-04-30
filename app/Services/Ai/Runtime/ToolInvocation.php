<?php

namespace App\Services\Ai\Runtime;

use Illuminate\Support\Str;

class ToolInvocation
{
    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly string $workspace,
        public readonly array $arguments = [],
        public readonly string $permissionMode = 'read',
        public readonly bool $dryRun = false,
        public readonly string $source = 'atlas_cli',
        public readonly ?string $sessionId = null,
        public readonly array $metadata = [],
        public readonly ?string $requestedAt = null,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $options
     */
    public static function make(string $tool, string $workspace, array $arguments = [], array $options = []): self
    {
        $resolved = realpath($workspace);

        return new self(
            id: (string) ($options['id'] ?? Str::orderedUuid()),
            tool: Str::of($tool)->lower()->trim()->value(),
            workspace: $resolved && is_dir($resolved) ? $resolved : $workspace,
            arguments: $arguments,
            permissionMode: self::normalizePermissionMode((string) ($options['permission_mode'] ?? 'read')),
            dryRun: (bool) ($options['dry_run'] ?? false),
            source: (string) ($options['source'] ?? 'atlas_cli'),
            sessionId: is_string($options['session_id'] ?? null) ? $options['session_id'] : null,
            metadata: is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
            requestedAt: (string) ($options['requested_at'] ?? now()->toJSON()),
        );
    }

    public function argument(string $key, mixed $default = null): mixed
    {
        return data_get($this->arguments, $key, $default);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            id: $this->id,
            tool: $this->tool,
            workspace: $this->workspace,
            arguments: $this->arguments,
            permissionMode: $this->permissionMode,
            dryRun: $this->dryRun,
            source: $this->source,
            sessionId: $this->sessionId,
            metadata: array_merge($this->metadata, $metadata),
            requestedAt: $this->requestedAt,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'workspace' => $this->workspace,
            'arguments' => $this->arguments,
            'permission_mode' => $this->permissionMode,
            'dry_run' => $this->dryRun,
            'source' => $this->source,
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
            'requested_at' => $this->requestedAt,
        ];
    }

    private static function normalizePermissionMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->trim()->value();

        return match ($mode) {
            'readonly', 'read-only', 'ro' => 'read',
            'workspace-write', 'write-scoped', 'edit' => 'write',
            'danger-full-access', 'full', 'all' => 'danger',
            default => in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read',
        };
    }
}
