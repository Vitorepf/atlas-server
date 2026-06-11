<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverAtlasMissionJob;
use App\Models\AtlasMissionDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * G3 — o fio HTTP da missão: pedido em linguagem natural → job governado em
 * background → registro durável p/ polling. A entrega segue TODA a cadeia
 * existente (certificação + branch, nunca main); aqui é só transporte + estado.
 */
class AtlasMissionDeliveryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! (bool) config('atlas.mission.http_delivery_enabled', false)) {
            return response()->json([
                'status' => 'disabled',
                'flag' => 'ATLAS_MISSION_HTTP_DELIVERY_ENABLED',
            ], 503);
        }

        $validated = $request->validate([
            'request' => ['required', 'string', 'min:8', 'max:8000'],
            'operator_id' => ['nullable', 'string', 'max:120'],
        ]);

        $delivery = AtlasMissionDelivery::query()->create([
            'request' => $validated['request'],
            'status' => AtlasMissionDelivery::STATUS_QUEUED,
            'requested_via' => 'http',
            'operator_id' => $validated['operator_id'] ?? null,
        ]);

        DeliverAtlasMissionJob::dispatch((string) $delivery->id);

        return response()->json([
            'schema_version' => 'atlas.ai.mission_delivery_record.v1',
            'id' => $delivery->id,
            'status' => $delivery->status,
            'poll' => '/ai/missions/'.$delivery->id,
        ], 202);
    }

    public function show(AtlasMissionDelivery $delivery): JsonResponse
    {
        return response()->json([
            'schema_version' => (string) $delivery->schema_version,
            'id' => $delivery->id,
            'status' => $delivery->status,
            'request' => $delivery->request,
            'mission_id' => $delivery->mission_id,
            'branch' => $delivery->branch,
            'result' => $delivery->result,
            'error' => $delivery->error,
            'started_at' => $delivery->started_at?->toJSON(),
            'finished_at' => $delivery->finished_at?->toJSON(),
            'created_at' => $delivery->created_at?->toJSON(),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'deliveries' => AtlasMissionDelivery::query()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'status', 'request', 'mission_id', 'branch', 'created_at', 'finished_at']),
        ]);
    }
}
