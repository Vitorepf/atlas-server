<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiAgentBehaviorReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'status' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:160'],
            'agent_slug' => ['nullable', 'string', 'max:160'],
            'finding_code' => ['nullable', 'string', 'max:180'],
            'contract_id' => ['nullable', 'string', 'max:180'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->scalarFilters($data, [
            'status',
            'provider',
            'model',
            'agent_slug',
            'finding_code',
            'contract_id',
        ]);
        $report = $replay->agentBehaviorReportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'agent_behavior' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
