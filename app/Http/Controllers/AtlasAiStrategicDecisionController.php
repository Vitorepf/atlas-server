<?php

namespace App\Http\Controllers;

use App\Services\Ai\Domain\StrategicDecisionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiStrategicDecisionController extends Controller
{
    public function __invoke(Request $request, StrategicDecisionReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:240'],
            'decision' => ['nullable', 'string', 'max:4000'],
            'options' => ['nullable', 'array', 'max:12'],
            'options.*' => ['string', 'max:500'],
            'values' => ['nullable', 'array', 'max:12'],
            'values.*' => ['string', 'max:240'],
            'constraints' => ['nullable', 'array', 'max:20'],
            'constraints.*' => ['string', 'max:500'],
            'impact' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'horizon_days' => ['nullable', 'integer', 'between:1,3650'],
            'audit' => ['nullable', 'boolean'],
            'register_rivals' => ['nullable', 'boolean'],
        ]);
        $data['surface_id'] = 'atlas_api_strategic_decision';
        $data['operator_id'] = (string) ($request->user()?->getAuthIdentifier() ?? 'api');
        $result = (bool) ($data['audit'] ?? false)
            ? $reviews->auditedPacket($data)
            : ['packet' => $reviews->packet($data)];
        $rivalsRegistration = (bool) ($data['register_rivals'] ?? false)
            ? $reviews->registerRivalsCase($result['packet'], $result['receipt'] ?? null)
            : null;

        return response()->json([
            'status' => 'ok',
            'strategic_decision' => $result['packet'],
            'decision_receipt' => $result['receipt'] ?? null,
            'ledger' => $result['ledger'] ?? null,
            'rivals_registration' => $rivalsRegistration,
        ]);
    }
}
