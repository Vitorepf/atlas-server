<?php

namespace App\Console\Commands;

use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AtlasAiLedgerBackfillTracesCommand extends Command
{
    protected $signature = 'atlas:ai:ledger-backfill-traces
        {--hours=720 : Backfill traces created in the last N hours}
        {--limit=100 : Maximum traces to inspect}
        {--dry-run : Report what would be backfilled without writing ledger events}
        {--json : Print machine-readable JSON}';

    protected $description = 'Backfill provider performance ledger events from existing ai_traces without raw prompt or response text.';

    public function handle(AtlasEvidenceLedger $ledger): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        if (! Schema::hasTable('ai_traces') || ! Schema::hasTable('atlas_ledger_events')) {
            return $this->render([
                'status' => 'unavailable',
                'schema_version' => 'atlas.ledger_trace_backfill.v1',
                'available' => false,
                'missing_tables' => array_values(array_filter([
                    Schema::hasTable('ai_traces') ? null : 'ai_traces',
                    Schema::hasTable('atlas_ledger_events') ? null : 'atlas_ledger_events',
                ])),
                'dry_run' => $dryRun,
                'hours' => $hours,
                'limit' => $limit,
                'candidate_count' => 0,
                'backfilled_count' => 0,
                'skipped_count' => 0,
                'events' => [],
            ], self::FAILURE);
        }

        $traces = AiTrace::query()
            ->whereNotNull('provider')
            ->whereNotNull('model')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $events = [];
        $backfilled = 0;
        $skipped = 0;

        foreach ($traces as $trace) {
            $eventId = $this->eventId($trace);
            $exists = AtlasLedgerEvent::query()->whereKey($eventId)->exists();

            if ($exists) {
                $skipped++;
                $events[] = $this->eventSummary($trace, $eventId, 'skipped_existing');
                continue;
            }

            if ($dryRun) {
                $events[] = $this->eventSummary($trace, $eventId, 'would_backfill');
                continue;
            }

            $event = $ledger->record(LedgerEventType::ProviderReturned, $this->payload($trace), [
                'event_id' => $eventId,
                'tenant_id' => (string) data_get($trace->metadata ?? [], 'decision_receipt.metadata.tenant_id', 'default'),
                'operator_id' => (string) data_get($trace->metadata ?? [], 'decision_receipt.metadata.operator_id', 'system'),
                'envelope_id' => $this->envelopeId($trace),
                'receipt_id' => $this->stringOrNull(data_get($trace->metadata ?? [], 'decision_receipt.receipt_id')),
                'trace_id' => $trace->id,
                'correlation_id' => $trace->trace_key ?: $trace->id,
                'emitter_stage' => 'atlas.ledger_backfill.ai_traces',
                'emitter_version' => 'atlas.ledger_trace_backfill.v1',
                'occurred_at' => $trace->completed_at ?? $trace->updated_at ?? $trace->created_at ?? now(),
            ]);

            if ($event) {
                $backfilled++;
                $events[] = $this->eventSummary($trace, $eventId, 'backfilled');
                continue;
            }

            $skipped++;
            $events[] = $this->eventSummary($trace, $eventId, 'skipped_ledger_unavailable');
        }

        return $this->render([
            'status' => 'ok',
            'schema_version' => 'atlas.ledger_trace_backfill.v1',
            'available' => true,
            'dry_run' => $dryRun,
            'hours' => $hours,
            'limit' => $limit,
            'candidate_count' => $traces->count(),
            'backfilled_count' => $backfilled,
            'skipped_count' => $skipped,
            'events' => $events,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload, int $defaultExit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : $defaultExit;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Ledger Trace Backfill</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Candidates', (string) ($payload['candidate_count'] ?? 0));
        $this->components->twoColumnDetail('Backfilled', (string) ($payload['backfilled_count'] ?? 0));
        $this->components->twoColumnDetail('Skipped', (string) ($payload['skipped_count'] ?? 0));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : $defaultExit;
    }

    private function eventId(AiTrace $trace): string
    {
        return 'BT'.substr(hash('sha256', 'ai_trace_provider_returned:'.$trace->id), 0, 30);
    }

    private function envelopeId(AiTrace $trace): string
    {
        $receiptEnvelopeId = $this->stringOrNull(data_get($trace->metadata ?? [], 'decision_receipt.envelope_id'));

        return $receiptEnvelopeId ?: 'trace:'.substr(hash('sha256', $trace->id), 0, 34);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(AiTrace $trace): array
    {
        return [
            'schema_version' => 'atlas.provider_usage.trace_backfill.v1',
            'source' => 'ai_traces',
            'backfill' => true,
            'source_trace_id' => $trace->id,
            'source_trace_key_hash' => $trace->trace_key ? hash('sha256', $trace->trace_key) : null,
            'provider_cli' => $trace->provider,
            'provider' => $trace->provider,
            'model_name_if_available' => $trace->model,
            'domain' => $this->stringOrNull(data_get($trace->metadata ?? [], 'task_request.domain')),
            'flow' => $this->stringOrNull(data_get($trace->metadata ?? [], 'task_request.flow')),
            'task_type' => $trace->intent,
            'specialist_profile' => $trace->agent_slug,
            'exit_status' => $this->exitStatus((string) $trace->status),
            'latency_seconds' => is_numeric($trace->latency_ms) ? round(((int) $trace->latency_ms) / 1000, 4) : null,
            'prompt_hash' => $trace->prompt_hash,
            'response_hash' => $trace->response_hash,
            'operator_input_hash' => $trace->operator_input ? hash('sha256', $trace->operator_input) : null,
            'raw_prompt_in_ledger' => false,
            'raw_response_in_ledger' => false,
            'raw_operator_input_in_ledger' => false,
            'cost_confidence' => 'unknown',
            'cost_mode' => 'unknown',
            'token_source' => 'not_available',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventSummary(AiTrace $trace, string $eventId, string $status): array
    {
        return [
            'event_id' => $eventId,
            'status' => $status,
            'trace_id' => $trace->id,
            'provider' => $trace->provider,
            'model' => $trace->model,
            'trace_status' => $trace->status,
        ];
    }

    private function exitStatus(string $status): string
    {
        return match ($status) {
            'succeeded', 'completed', 'passed' => 'succeeded',
            'failed', 'cancelled', 'timed_out' => 'failed',
            default => $status,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
