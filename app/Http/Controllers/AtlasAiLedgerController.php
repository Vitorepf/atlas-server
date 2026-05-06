<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeInput;
use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiLedgerController extends Controller
{
    public function show(string $envelope, Request $request, KernelLedgerEnvelopeReportService $reports): JsonResponse
    {
        $envelopeId = trim($envelope);
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT],
            'slo' => ['nullable', 'boolean'],
            'repair' => ['nullable', 'boolean'],
            'kernel' => ['nullable', 'boolean'],
        ]);

        $payload = $reports->report(
            envelopeId: $envelopeId,
            limit: $filters['limit'] ?? null,
            includeSlo: (bool) ($filters['slo'] ?? false),
            includeRepair: (bool) ($filters['repair'] ?? false),
            includeKernel: (bool) ($filters['kernel'] ?? false),
        );

        return response()->json($payload, $payload['status'] === 'ledger_table_missing' ? 503 : 200);
    }
}
