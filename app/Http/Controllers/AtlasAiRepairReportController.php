<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiRepairReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'status' => ['nullable', 'string', 'max:80'],
            'strategy' => ['nullable', 'string', 'max:120'],
            'failure_domain' => ['nullable', 'string', 'max:160'],
            'emitter_stage' => ['nullable', 'string', 'max:160'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->scalarFilters($data, ['status', 'strategy', 'failure_domain', 'emitter_stage']);
        $report = $replay->repairReportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_repair' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
