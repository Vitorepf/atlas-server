<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiKernelPipelineReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'status' => ['nullable', 'string', 'max:80'],
            'surface_id' => ['nullable', 'string', 'max:120'],
            'surface' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'string', 'max:160'],
            'input_mode' => ['nullable', 'string', 'max:120'],
            'surface_contract_source' => ['nullable', 'string', 'max:120'],
            'contract_source' => ['nullable', 'string', 'max:120'],
            'emitter_stage' => ['nullable', 'string', 'max:160'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->aliasedScalarFilters($data, [
            'status' => ['status'],
            'surface_id' => ['surface_id', 'surface'],
            'flow' => ['flow'],
            'input_mode' => ['input_mode'],
            'surface_contract_source' => ['surface_contract_source', 'contract_source'],
            'emitter_stage' => ['emitter_stage'],
        ]);

        $report = $replay->kernelPipelineReportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_pipeline' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
