<?php

namespace App\Http\Controllers;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringQaService;
use App\Services\Engineering\EngineeringReviewService;
use App\Services\Engineering\PostgresEngineeringReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngineeringTaskGateController extends Controller
{
    public function qa(Request $request, AtlasTask $task, EngineeringQaService $qa): JsonResponse
    {
        $payload = $qa->record($task, $request->validate([
            'target_id' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['passed', 'failed', 'needs_review', 'not_applicable'])],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['string', 'max:1000'],
            'expected_result' => ['required', 'string', 'max:2000'],
            'actual_result' => ['required', 'string', 'max:2000'],
            'screenshot_url' => ['nullable', 'string', 'max:1000'],
            'artifact_url' => ['nullable', 'string', 'max:1000'],
            'console_output' => ['nullable', 'string', 'max:4000'],
            'network_output' => ['nullable', 'string', 'max:4000'],
            'risk_notes' => ['nullable', 'string', 'max:2000'],
            'visual_required' => ['nullable', 'boolean'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string', 'max:500'],
        ]));

        return response()->json($payload, 201);
    }

    public function deepReview(Request $request, AtlasTask $task, EngineeringReviewService $review): JsonResponse
    {
        return response()->json($review->deepReview($task, $request->validate([
            'recorded_by' => ['nullable', 'string', 'max:120'],
            'findings' => ['nullable', 'array'],
            'findings.*.severity' => ['required_with:findings', Rule::in(['p0', 'p1', 'p2', 'p3'])],
            'findings.*.status' => ['nullable', Rule::in(['open', 'resolved', 'dismissed', 'fixed', 'false_positive', 'accepted_risk'])],
            'findings.*.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'findings.*.category' => ['nullable', 'string', 'max:80'],
            'findings.*.title' => ['required_with:findings', 'string', 'max:180'],
            'findings.*.body' => ['nullable', 'string', 'max:12000'],
            'findings.*.file_path' => ['nullable', 'string', 'max:1000'],
            'findings.*.start_line' => ['nullable', 'integer', 'min:1'],
            'findings.*.end_line' => ['nullable', 'integer', 'min:1'],
            'findings.*.recommendation' => ['nullable', 'string', 'max:4000'],
            'findings.*.evidence' => ['nullable', 'array'],
        ])));
    }

    public function dbReview(Request $request, AtlasTask $task, PostgresEngineeringReviewService $review): JsonResponse
    {
        return response()->json($review->review($task, $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string', 'max:1000'],
        ])), 201);
    }

    public function dbExplain(Request $request, AtlasTask $task, PostgresEngineeringReviewService $review): JsonResponse
    {
        return response()->json($review->explain($task, $request->validate([
            'query' => ['nullable', 'string', 'max:12000'],
        ])), 201);
    }
}
