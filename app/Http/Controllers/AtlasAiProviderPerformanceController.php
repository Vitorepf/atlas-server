<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiProviderPerformanceController extends Controller
{
    public function __invoke(Request $request, ProviderPerformanceProjection $performance, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'provider_cli' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'string', 'max:160'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'risk' => ['nullable', 'string', 'max:80'],
            'selection_mode' => ['nullable', 'string', 'max:80'],
        ]);

        $hours = $input->hours($data['hours'] ?? null);
        $filters = $input->aliasedScalarFilters($data, [
            'provider_cli' => ['provider_cli', 'provider'],
            'domain' => ['domain'],
            'flow' => ['flow'],
            'task_type' => ['task_type'],
            'risk' => ['risk'],
            'selection_mode' => ['selection_mode'],
        ]);
        $report = $performance->reportForWindow(now()->subHours($hours), filters: $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'provider_performance' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
