<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiSelfImprovementScheduleReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $report = $replay->selfImprovementScheduleReportForWindow(now()->subHours($hours));

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'self_improvement_schedule_replay' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
