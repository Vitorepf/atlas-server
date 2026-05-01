<?php

namespace App\Services\Ai\Cli;

use App\Models\AiTrace;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasCliTelemetry
{
    public function __construct(private readonly AiTelemetryCollector $collector)
    {
    }

    public function correlationId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string,mixed>  $event
     */
    public function record(string $eventName, array $event = []): void
    {
        if (! (bool) config('atlas.ai_metrics.enabled', true)) {
            return;
        }

        $correlationId = $this->uuid($event['correlation_id'] ?? null) ?? $this->correlationId();
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        $payload = array_merge($event, [
            'event_key' => $this->eventKey($eventName, $correlationId),
            'correlation_id' => $correlationId,
            'surface' => 'cli',
            'runtime' => 'mac_cli',
            'cli_version' => config('atlas.version'),
            'event_name' => $eventName,
            'occurred_at_client' => now()->toJSON(),
            'metadata' => $metadata,
            'schema_version' => 1,
        ]);

        unset($payload['eventName']);

        $this->persist($payload);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function interactionSubmitted(
        string $correlationId,
        string $input,
        string $workspace,
        ?string $threadId,
        ?string $provider,
        ?string $agentSlug,
        string $mode,
        string $permissionMode,
        bool $stream,
        bool $newThread,
        array $metadata = [],
    ): void {
        $this->record('cli_message_submitted', [
            'correlation_id' => $correlationId,
            'thread_id' => $threadId,
            'provider' => $provider,
            'agent_slug' => $agentSlug,
            'numeric_value' => strlen($input),
            'unit' => 'chars',
            'metadata' => array_merge($this->workspaceMetadata($workspace), $metadata, [
                'mode' => $mode,
                'permission_mode' => $permissionMode,
                'stream' => $stream,
                'new_thread' => $newThread,
                'input_chars' => strlen($input),
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function interactionEnqueued(string $correlationId, AiTrace $trace, int $durationMs, array $metadata = []): void
    {
        $this->record('cli_interaction_enqueued', [
            'correlation_id' => $correlationId,
            'trace_id' => $trace->id,
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'provider' => $trace->provider,
            'agent_slug' => $trace->agent_slug,
            'duration_ms' => $durationMs,
            'metadata' => array_merge($metadata, [
                'trace_status' => $trace->status,
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function interactionCompleted(string $correlationId, AiTrace $trace, int $durationMs, array $metadata = []): void
    {
        $this->record('cli_interaction_completed', [
            'correlation_id' => $correlationId,
            'trace_id' => $trace->id,
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'provider' => $trace->provider,
            'agent_slug' => $trace->agent_slug,
            'duration_ms' => $durationMs,
            'metadata' => array_merge($metadata, [
                'trace_status' => $trace->status,
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function interactionFailed(string $correlationId, \Throwable $exception, int $durationMs, array $metadata = []): void
    {
        $this->record('cli_interaction_failed_before_enqueue', [
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
            'metadata' => array_merge($metadata, [
                'error_type' => $exception::class,
                'error_message' => Str::limit($exception->getMessage(), 240, '...'),
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function persist(array $event): void
    {
        try {
            if (Schema::hasTable('ai_telemetry_events')) {
                $this->collector->record($event);

                return;
            }
        } catch (\Throwable) {
            // Fall through to the local spool so CLI telemetry never breaks the CLI.
        }

        $this->spool($event);
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function spool(array $event): void
    {
        try {
            $path = $this->spoolPath();
            File::ensureDirectoryExists(dirname($path));
            File::append($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        } catch (\Throwable) {
            // Best effort only.
        }
    }

    private function spoolPath(): string
    {
        return storage_path('app/atlas/ai-telemetry/cli-events.jsonl');
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceMetadata(string $workspace): array
    {
        $normalized = realpath($workspace) ?: $workspace;

        return [
            'workspace_basename' => basename($normalized),
            'workspace_hash' => hash('sha256', $normalized),
        ];
    }

    private function eventKey(string $eventName, string $correlationId): string
    {
        $eventSlug = Str::of($eventName)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.-]+/', '_')
            ->limit(60, '')
            ->value();

        return implode(':', [
            'cli',
            $eventSlug,
            $correlationId,
            now()->format('YmdHisv'),
            Str::random(10),
        ]);
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
