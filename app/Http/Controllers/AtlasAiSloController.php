<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiSloController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'domain' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'string', 'max:120'],
            'surface_id' => ['nullable', 'string', 'max:120'],
            'surface' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'runtime' => ['nullable', 'string', 'max:120'],
            'tool_id' => ['nullable', 'string', 'max:120'],
            'tool' => ['nullable', 'string', 'max:120'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->aliasedScalarFilters($data, [
            'domain' => ['domain'],
            'flow' => ['flow'],
            'surface_id' => ['surface_id', 'surface'],
            'provider' => ['provider'],
            'model' => ['model'],
            'runtime' => ['runtime'],
            'tool_id' => ['tool_id', 'tool'],
        ]);
        $report = $replay->sloReportForWindow(now()->subHours($hours), null, $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_slo' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
