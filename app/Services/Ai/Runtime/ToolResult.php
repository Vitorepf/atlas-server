<?php

namespace App\Services\Ai\Runtime;

class ToolResult
{
    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<int,RuntimeEvent>  $events
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $invocationId,
        public readonly string $tool,
        public readonly string $summary,
        public readonly string $output = '',
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly ?int $exitCode = null,
        public readonly int $durationMs = 0,
        public readonly array $changedFiles = [],
        public readonly ?string $diff = null,
        public readonly ?string $checkpointPath = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $events = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function failure(ToolInvocation $invocation, string $errorCode, string $errorMessage, array $metadata = []): self
    {
        return new self(
            ok: false,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: $errorMessage,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'invocation_id' => $this->invocationId,
            'tool' => $this->tool,
            'summary' => $this->summary,
            'output' => $this->output,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'changed_files' => $this->changedFiles,
            'diff' => $this->diff,
            'checkpoint_path' => $this->checkpointPath,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'events' => array_map(fn (RuntimeEvent $event): array => $event->toArray(), $this->events),
            'metadata' => $this->metadata,
        ];
    }
}
