<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasQualitativeLevelsReadModel;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiQualitativeLevelsController extends Controller
{
    public function __invoke(Request $request, AtlasQualitativeLevelsReadModel $levels, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
        ]);
        $hours = $input->hours($data['hours'] ?? null);

        return response()->json([
            'status' => 'ok',
            'hours' => $hours,
            'qualitative_levels' => $levels->report(now()->subHours($hours)),
        ]);
    }
}
