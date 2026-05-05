<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AtlasAiLedgerController extends Controller
{
    public function show(string $envelope, Request $request, AtlasLedgerReplayService $replay): JsonResponse
    {
        $envelopeId = trim($envelope);
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'slo' => ['nullable', 'boolean'],
            'repair' => ['nullable', 'boolean'],
        ]);

        if (! Schema::hasTable('atlas_ledger_events')) {
            return response()->json([
                'envelope_id' => $envelopeId,
                'status' => 'ledger_table_missing',
                'event_count' => 0,
                'events' => [],
            ], 503);
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $events = array_slice($replay->eventsForEnvelope($envelopeId), 0, $limit);
        $payload = [
            'envelope_id' => $envelopeId,
            'status' => $events === [] ? 'not_found' : 'ok',
            'event_count' => count($events),
            'filters' => [
                'limit' => $limit,
                'slo' => (bool) ($filters['slo'] ?? false),
                'repair' => (bool) ($filters['repair'] ?? false),
            ],
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

        if ((bool) ($filters['slo'] ?? false)) {
            $payload['slo'] = $replay->sloReportForEnvelope($envelopeId);
        }
        if ((bool) ($filters['repair'] ?? false)) {
            $payload['repair'] = $replay->repairReportForEnvelope($envelopeId);
        }

        return response()->json($payload);
    }
}
