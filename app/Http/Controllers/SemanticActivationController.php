<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateSemanticActivationRequest;
use App\Http\Requests\IndexSemanticActivationRequest;
use App\Http\Requests\SemanticActivationFeedbackRequest;
use App\Http\Resources\SemanticNoteActivationResource;
use App\Models\SemanticNoteActivation;
use App\Services\Semantic\ActivationEngine;
use Illuminate\Http\JsonResponse;

class SemanticActivationController extends Controller
{
    public function index(IndexSemanticActivationRequest $request, ActivationEngine $engine): JsonResponse
    {
        $data = $request->validated();
        $activations = $engine->pending(
            contextType: $data['context_type'] ?? null,
            limit: (int) ($data['limit'] ?? 10),
        );

        return response()->json([
            'activations' => SemanticNoteActivationResource::collection($activations)->resolve(),
        ]);
    }

    public function create(CreateSemanticActivationRequest $request, ActivationEngine $engine): JsonResponse
    {
        $data = $request->validated();
        $result = $engine->createForContext(
            contextType: $data['context_type'] ?? 'morning_briefing',
            contextPayload: $data['context_payload'] ?? [],
        );

        return response()->json($result);
    }

    public function markShown(SemanticNoteActivation $activation, ActivationEngine $engine): SemanticNoteActivationResource
    {
        return new SemanticNoteActivationResource($engine->markShown($activation)->load('note'));
    }

    public function feedback(
        SemanticActivationFeedbackRequest $request,
        SemanticNoteActivation $activation,
        ActivationEngine $engine,
    ): SemanticNoteActivationResource {
        $data = $request->validated();

        return new SemanticNoteActivationResource($engine->recordFeedback(
            activation: $activation,
            score: (int) $data['usefulness_score'],
            feedback: $data['operator_feedback'] ?? null,
        )->load('note'));
    }

    public function dismiss(SemanticNoteActivation $activation, ActivationEngine $engine): SemanticNoteActivationResource
    {
        return new SemanticNoteActivationResource($engine->dismiss($activation)->load('note'));
    }
}
