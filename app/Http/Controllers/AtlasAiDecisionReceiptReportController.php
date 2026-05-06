<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiDecisionReceiptReportController extends Controller
{
    public function __invoke(Request $request, AtlasLedgerReplayService $replay): JsonResponse
    {
        $data = $request->validate([
            'envelope' => ['required', 'string', 'max:160'],
        ]);

        return response()->json([
            'status' => 'ok',
            'decision_receipt_replay' => $replay->decisionReceiptReportForEnvelope($data['envelope']),
        ]);
    }
}
