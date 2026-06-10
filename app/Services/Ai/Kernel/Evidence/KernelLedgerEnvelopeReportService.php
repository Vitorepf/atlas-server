<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Services\Ai\Support\DatabaseTableAvailability;

class KernelLedgerEnvelopeReportService
{
    public function __construct(
        private readonly AtlasLedgerReplayService $replay,
        private readonly KernelLedgerEnvelopeInput $input,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(string $envelopeId, mixed $limit = null, bool $includeSlo = false, bool $includeRepair = false, bool $includeKernel = false): array
    {
        $envelopeId = trim($envelopeId);
        $limit = $this->input->eventLimit($limit);
        $filters = [
            'limit' => $limit,
            'slo' => $includeSlo,
            'repair' => $includeRepair,
            'kernel' => $includeKernel,
        ];

        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [
                'envelope_id' => $envelopeId,
                'status' => 'ledger_table_missing',
                'event_count' => 0,
                'filters' => $filters,
                'events' => [],
            ];
        }

        $events = array_slice($this->replay->eventsForEnvelope($envelopeId), 0, $limit);
        $payload = [
            'envelope_id' => $envelopeId,
            'status' => $events === [] ? 'not_found' : 'ok',
            'event_count' => count($events),
            'filters' => $filters,
            'events' => array_map(fn (array $event): array => $this->eventPayload($event), $events),
        ];

        if ($includeSlo) {
            $payload['slo'] = $this->replay->sloReportForEnvelope($envelopeId);
        }

        if ($includeRepair) {
            $payload['repair'] = $this->replay->repairReportForEnvelope($envelopeId);
        }

        if ($includeKernel) {
            $payload['kernel_pipeline'] = $this->replay->kernelPipelineReportForEnvelope($envelopeId);
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function eventPayload(array $event): array
    {
        return [
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
        ];
    }
}
