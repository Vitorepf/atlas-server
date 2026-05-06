<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReviewRecorder;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AtlasAiRivalsStrategyController extends Controller
{
    public function __invoke(Request $request, AtlasRivalsStrategyReadModel $rivals, KernelReplayReportInput $input): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
        ]);
        $hours = $input->hours($data['hours'] ?? null);
        $report = $rivals->report(now()->subHours($hours));

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'rivals_strategy' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }

    public function recordReview(Request $request, AtlasRivalsStrategyReadModel $rivals, AtlasRivalsStrategyReviewRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'review_id' => ['nullable', 'string', 'max:80'],
            'case_id' => ['nullable', 'string', 'max:80'],
            'horizon_days' => ['nullable', 'integer', 'between:1,3650'],
            'regret_score' => ['required', 'integer', 'between:0,100'],
            'alignment_score' => ['required', 'integer', 'between:0,100'],
            'agency_score' => ['required', 'integer', 'between:0,100'],
            'outcome_summary' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $recording = $recorder->record([
                ...$data,
                'recorded_by' => (string) ($request->user()?->getAuthIdentifier() ?? 'api'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'recorded_review' => $recording,
            'rivals_strategy' => $rivals->report(now()->subDays(365), now()->addDays(365)),
        ]);
    }

    public function dueReviews(Request $request, AtlasRivalsStrategyReadModel $rivals): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'between:0,365'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $report = $rivals->dueReviews((int) ($data['days'] ?? 30), (int) ($data['limit'] ?? 20));

        return response()->json([
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'due_reviews' => $report,
        ], ($report['available'] ?? false) ? 200 : 503);
    }
}
