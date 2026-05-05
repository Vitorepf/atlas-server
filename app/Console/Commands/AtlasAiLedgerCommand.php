<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AtlasAiLedgerCommand extends Command
{
    protected $signature = 'atlas:ai:ledger
        {envelope : Operation envelope id to replay}
        {--limit=100 : Maximum number of events to render}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay Atlas AI kernel evidence events for one operation envelope.';

    public function handle(AtlasEvidenceLedger $ledger): int
    {
        $envelopeId = trim((string) $this->argument('envelope'));
        if ($envelopeId === '') {
            $this->error('Envelope vazio.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('atlas_ledger_events')) {
            $payload = [
                'envelope_id' => $envelopeId,
                'status' => 'ledger_table_missing',
                'events' => [],
            ];

            return $this->render($payload);
        }

        $limit = max(1, (int) $this->option('limit'));
        $events = array_slice($ledger->eventsForEnvelope($envelopeId), 0, $limit);
        $payload = [
            'envelope_id' => $envelopeId,
            'status' => $events === [] ? 'not_found' : 'ok',
            'event_count' => count($events),
            'events' => array_map(fn (array $event): array => [
                'event_id' => $event['event_id'] ?? null,
                'event_type' => $event['event_type'] ?? null,
                'tenant_id' => $event['tenant_id'] ?? null,
                'operator_id' => $event['operator_id'] ?? null,
                'receipt_id' => $event['receipt_id'] ?? null,
                'trace_id' => $event['trace_id'] ?? null,
                'correlation_id' => $event['correlation_id'] ?? null,
                'causation_id' => $event['causation_id'] ?? null,
                'emitter_stage' => $event['emitter_stage'] ?? null,
                'payload_hash' => $event['payload_hash'] ?? null,
                'occurred_at' => $event['occurred_at'] ?? null,
                'payload' => $event['payload'] ?? [],
            ], $events),
        ];

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ledger_table_missing' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Ledger</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Envelope', (string) ($payload['envelope_id'] ?? '-'));
        $this->components->twoColumnDetail('Events', (string) ($payload['event_count'] ?? 0));

        $rows = collect((array) ($payload['events'] ?? []))
            ->map(fn (array $event): array => [
                $event['event_type'] ?? '-',
                $event['emitter_stage'] ?? '-',
                $event['trace_id'] ?? '-',
                $event['payload_hash'] ?? '-',
                $event['occurred_at'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->table(['event', 'stage', 'trace', 'payload hash', 'occurred at'], $rows);
        }

        return ($payload['status'] ?? null) === 'ledger_table_missing' ? self::FAILURE : self::SUCCESS;
    }
}
