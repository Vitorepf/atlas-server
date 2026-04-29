<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackAiTraceRequest;
use App\Http\Requests\StoreAiInteractionRequest;
use App\Http\Resources\AiTraceResource;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiInteractionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $traces = AiTrace::query()
            ->with(['job', 'jobs'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('agent'), fn ($query, $agent) => $query->where('agent_slug', $agent))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'traces' => AiTraceResource::collection($traces)->resolve(),
        ]);
    }

    public function store(StoreAiInteractionRequest $request, AiGatewayService $gateway): JsonResponse
    {
        $data = $request->validated();
        $trace = $gateway->enqueueInteraction((string) $data['input_text'], $data);

        return response()->json([
            'trace' => (new AiTraceResource($trace))->resolve(),
        ], 202);
    }

    public function show(AiTrace $trace): JsonResponse
    {
        return response()->json([
            'trace' => (new AiTraceResource($trace->load(['job.attemptHistory', 'jobs.attemptHistory'])))->resolve(),
        ]);
    }

    public function feedback(FeedbackAiTraceRequest $request, AiTrace $trace, AiGatewayService $gateway): JsonResponse
    {
        return response()->json([
            'trace' => (new AiTraceResource($gateway->recordFeedback($trace, $request->validated())))->resolve(),
        ]);
    }
}
