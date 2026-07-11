<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiTelemetryEvent;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * OPE-05 — read-only aggregation of MCP tool usage telemetry.
 */
final class AtlasOpenBrainToolUsageCommand extends Command
{
    public const SCHEMA_VERSION = 'atlas.open_brain.tool_usage.v1';

    protected $signature = 'atlas:open-brain:tool-usage {--json : Emit canonical JSON}';

    protected $description = 'Aggregate Open Brain MCP per-tool usage from ai_telemetry_events.';

    public function handle(): int
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'event_name' => AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME,
            'available' => DatabaseTableAvailability::has('ai_telemetry_events'),
            'tools' => $this->aggregate(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        foreach ($payload['tools'] as $tool) {
            $this->components->twoColumnDetail(
                (string) $tool['tool_name'],
                sprintf(
                    'count=%d ok=%d error=%d first=%s last=%s',
                    (int) $tool['usage_count'],
                    (int) $tool['ok_count'],
                    (int) $tool['error_count'],
                    (string) ($tool['first_seen_at'] ?? 'n/a'),
                    (string) ($tool['last_seen_at'] ?? 'n/a'),
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function aggregate(): array
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
            return [];
        }

        $aggregated = [];
        AiTelemetryEvent::query()
            ->where('event_name', AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME)
            ->orderBy('received_at')
            ->get()
            ->each(function (AiTelemetryEvent $event) use (&$aggregated): void {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $toolName = trim((string) ($metadata['tool_name'] ?? ''));
                if ($toolName === '') {
                    return;
                }

                $status = trim((string) ($metadata['status'] ?? $event->event_phase ?? 'unknown'));
                $seenAt = $event->received_at?->toIso8601String()
                    ?? $event->created_at?->toIso8601String()
                    ?? now()->toIso8601String();

                if (! isset($aggregated[$toolName])) {
                    $aggregated[$toolName] = [
                        'tool_name' => $toolName,
                        'usage_count' => 0,
                        'ok_count' => 0,
                        'error_count' => 0,
                        'first_seen_at' => $seenAt,
                        'last_seen_at' => $seenAt,
                    ];
                }

                $aggregated[$toolName]['usage_count']++;
                if ($status === 'ok') {
                    $aggregated[$toolName]['ok_count']++;
                } elseif ($status === 'error') {
                    $aggregated[$toolName]['error_count']++;
                }
                $aggregated[$toolName]['last_seen_at'] = $seenAt;
            });

        ksort($aggregated);

        return array_values($aggregated);
    }
}
