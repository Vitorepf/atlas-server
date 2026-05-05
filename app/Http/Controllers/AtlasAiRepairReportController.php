<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiRepairReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
            'status' => ['nullable', 'string', 'max:80'],
            'strategy' => ['nullable', 'string', 'max:120'],
            'failure_domain' => ['nullable', 'string', 'max:160'],
            'emitter_stage' => ['nullable', 'string', 'max:160'],
        ]);

        $hours = (int) ($data['hours'] ?? 24);
        $filters = collect($data)
            ->only(['status', 'strategy', 'failure_domain', 'emitter_stage'])
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();
        $report = $replay->repairReportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_repair' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
