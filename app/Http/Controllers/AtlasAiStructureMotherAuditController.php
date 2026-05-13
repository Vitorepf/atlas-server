<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasStructureMotherAuditReadModel;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiStructureMotherAuditController extends Controller
{
    public function __invoke(Request $request, AtlasStructureMotherAuditReadModel $audit, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
            'workspace' => ['nullable', 'string', 'max:1200'],
        ]);
        $hours = $input->hours($data['hours'] ?? null);

        return response()->json([
            'status' => 'ok',
            'structure_mother_audit' => $audit->report([
                'hours' => $hours,
                'workspace' => $data['workspace'] ?? null,
            ]),
        ]);
    }
}
