<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiInboxActionReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'action' => ['nullable', 'string', 'max:120'],
            'actor_type' => ['nullable', 'string', 'max:120'],
            'inbox_type' => ['nullable', 'string', 'max:120'],
            'recommended_action' => ['nullable', 'string', 'max:180'],
            'result' => ['nullable', 'string', 'max:120'],
            'emitter_stage' => ['nullable', 'string', 'max:160'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->scalarFilters($data, [
            'action',
            'actor_type',
            'inbox_type',
            'recommended_action',
            'result',
            'emitter_stage',
        ]);
        $report = $replay->inboxActionReportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'inbox_actions' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
