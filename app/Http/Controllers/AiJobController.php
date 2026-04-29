<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jobs = AiJob::query()
            ->with('trace')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('provider'), fn ($query, $provider) => $query->where('provider', $provider))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'jobs' => AiJobResource::collection($jobs)->resolve(),
        ]);
    }

    public function show(AiJob $job): JsonResponse
    {
        return response()->json([
            'job' => (new AiJobResource($job->load(['trace', 'attemptHistory'])))->resolve(),
        ]);
    }

    public function retry(AiJob $job): JsonResponse
    {
        if (! in_array($job->status, ['failed', 'cancelled'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'AI_JOB_NOT_RETRYABLE',
                    'message' => 'Only failed or cancelled AI jobs can be retried manually.',
                ],
            ], 422);
        }

        $job->update([
            'status' => 'queued',
            'available_at' => now(),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $job->trace?->update(['status' => 'queued', 'completed_at' => null]);

        return response()->json([
            'job' => (new AiJobResource($job->refresh()->load(['trace', 'attemptHistory'])))->resolve(),
        ]);
    }

    public function cancel(AiJob $job): JsonResponse
    {
        if (in_array($job->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return response()->json([
                'job' => (new AiJobResource($job->load(['trace', 'attemptHistory'])))->resolve(),
            ]);
        }

        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
        ]);
        $job->trace?->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        return response()->json([
            'job' => (new AiJobResource($job->refresh()->load(['trace', 'attemptHistory'])))->resolve(),
        ]);
    }
}
