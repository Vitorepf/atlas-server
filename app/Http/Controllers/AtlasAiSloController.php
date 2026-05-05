<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiSloController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
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

        $hours = (int) ($data['hours'] ?? 24);
        $filters = $this->filters($data);
        $report = $replay->sloReportForWindow(now()->subHours($hours), null, $filters);

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_slo' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function filters(array $data): array
    {
        $aliases = [
            'domain' => ['domain'],
            'flow' => ['flow'],
            'surface_id' => ['surface_id', 'surface'],
            'provider' => ['provider'],
            'model' => ['model'],
            'runtime' => ['runtime'],
            'tool_id' => ['tool_id', 'tool'],
        ];
        $filters = [];

        foreach ($aliases as $dimension => $keys) {
            foreach ($keys as $key) {
                $value = $data[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $filters[$dimension] = trim((string) $value);
                    break;
                }
            }
        }

        return $filters;
    }
}
