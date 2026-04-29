<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnswerCognitiveGameRequest;
use App\Http\Requests\StartCognitiveGameRequest;
use App\Http\Resources\CognitiveGameRunResource;
use App\Models\CognitiveGameRun;
use App\Services\Semantic\CognitiveGameService;
use Illuminate\Http\JsonResponse;

class CognitiveGameController extends Controller
{
    public function today(CognitiveGameService $service): JsonResponse
    {
        $game = $service->today();

        return response()->json([
            'game' => $game ? (new CognitiveGameRunResource($game))->resolve() : null,
        ]);
    }

    public function start(StartCognitiveGameRequest $request, CognitiveGameService $service): CognitiveGameRunResource
    {
        $data = $request->validated();

        return new CognitiveGameRunResource($service->start(
            gameKey: $data['game_key'] ?? 'recall',
            noteIds: $data['note_ids'] ?? [],
        ));
    }

    public function answer(
        AnswerCognitiveGameRequest $request,
        CognitiveGameRun $game,
        CognitiveGameService $service,
    ): CognitiveGameRunResource {
        $data = $request->validated();

        return new CognitiveGameRunResource($service->answer(
            run: $game,
            answer: $data['operator_answer'],
            durationSeconds: $data['duration_seconds'] ?? null,
        ));
    }
}
