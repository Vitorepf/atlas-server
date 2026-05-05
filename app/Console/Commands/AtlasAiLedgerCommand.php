<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AtlasAiLedgerCommand extends Command
{
    protected $signature = 'atlas:ai:ledger
        {envelope : Operation envelope id to replay}
        {--limit=100 : Maximum number of events to render}
        {--slo : Include SLO observation summary for the envelope}
        {--repair : Include Repair Loop summary for the envelope}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay Atlas AI kernel evidence events for one operation envelope.';

    public function handle(AtlasLedgerReplayService $replay): int
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
        $events = array_slice($replay->eventsForEnvelope($envelopeId), 0, $limit);
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
        if ((bool) $this->option('slo')) {
            $payload['slo'] = $replay->sloReportForEnvelope($envelopeId);
        }
        if ((bool) $this->option('repair')) {
            $payload['repair'] = $replay->repairReportForEnvelope($envelopeId);
        }

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
        if (is_array($payload['slo'] ?? null)) {
            $slo = $payload['slo'];
            $this->components->twoColumnDetail('SLO observations', (string) ($slo['observation_count'] ?? 0));
            $this->components->twoColumnDetail('SLO worst status', (string) ($slo['worst_status'] ?? '-'));
            $this->components->twoColumnDetail('SLO worst severity', (string) ($slo['worst_severity'] ?? '-'));
        }
        if (is_array($payload['repair'] ?? null)) {
            $repair = $payload['repair'];
            $this->components->twoColumnDetail('Repair events', (string) ($repair['repair_event_count'] ?? 0));
            $this->components->twoColumnDetail('Repair latest status', (string) ($repair['latest_status'] ?? '-'));
            $this->components->twoColumnDetail('Repair latest strategy', (string) ($repair['latest_strategy'] ?? '-'));
        }

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

        if (is_array($payload['slo'] ?? null) && is_array(data_get($payload, 'slo.stages'))) {
            $sloRows = collect((array) data_get($payload, 'slo.stages'))
                ->map(fn (array $stage, string $name): array => [
                    $name,
                    $stage['count'] ?? 0,
                    $stage['worst_status'] ?? '-',
                    $stage['worst_severity'] ?? '-',
                    $stage['p50_ms'] ?? 0,
                    $stage['p95_ms'] ?? 0,
                    $stage['max_ms'] ?? 0,
                ])
                ->all();

            if ($sloRows !== []) {
                $this->table(['stage', 'count', 'status', 'severity', 'p50 ms', 'p95 ms', 'max ms'], $sloRows);
            }
        }

        if (is_array($payload['repair'] ?? null) && is_array(data_get($payload, 'repair.events'))) {
            $repairRows = collect((array) data_get($payload, 'repair.events'))
                ->map(fn (array $event): array => [
                    $event['event_type'] ?? '-',
                    $event['status'] ?? '-',
                    $event['strategy'] ?? '-',
                    $event['failure_domain'] ?? '-',
                    ($event['repair_executed'] ?? false) ? 'yes' : 'no',
                    $event['occurred_at'] ?? '-',
                ])
                ->all();

            if ($repairRows !== []) {
                $this->table(['event', 'status', 'strategy', 'failure', 'executed', 'occurred at'], $repairRows);
            }
        }

        return ($payload['status'] ?? null) === 'ledger_table_missing' ? self::FAILURE : self::SUCCESS;
    }
}
