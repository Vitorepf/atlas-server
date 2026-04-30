<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use App\Services\Ai\AiCouncilCoordinator;
use App\Services\AuditLogService;
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

    public function retry(AiJob $job, AuditLogService $audit): JsonResponse
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
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'result_text' => null,
            'result_json' => [],
            'max_attempts' => max((int) $job->max_attempts, (int) $job->attempts + 1),
            'error_code' => null,
            'error_message' => null,
        ]);
        $job->trace?->update([
            'status' => 'queued',
            'response_hash' => null,
            'response_text' => null,
            'latency_ms' => null,
            'completed_at' => null,
        ]);

        $audit->record('ai_job_retry_requested', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => 'Retry manual solicitado para job de IA.',
            'evidence' => [
                'agent_slug' => $job->agent_slug,
                'provider' => $job->provider,
                'attempts' => $job->attempts,
            ],
            'privacy' => $this->privacyFromJob($job),
            'refs' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
            ],
        ]);

        return response()->json([
            'job' => (new AiJobResource($job->refresh()->load(['trace', 'attemptHistory'])))->resolve(),
        ]);
    }

    public function cancel(AiJob $job, AuditLogService $audit, AiCouncilCoordinator $council): JsonResponse
    {
        if (in_array($job->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return response()->json([
                'job' => (new AiJobResource($job->load(['trace', 'attemptHistory'])))->resolve(),
            ]);
        }

        $jobsToCancel = $this->isCouncilJob($job)
            ? AiJob::query()
                ->where('trace_id', $job->trace_id)
                ->whereIn('status', ['queued', 'processing'])
                ->get()
            : collect([$job]);

        $jobsToCancel->each(function (AiJob $jobToCancel): void {
            $jobToCancel->update([
                'status' => 'cancelled',
                'reserved_at' => null,
                'started_at' => null,
                'finished_at' => now(),
                'worker_id' => null,
            ]);
        });

        if ($this->isCouncilJob($job) && $job->trace) {
            $council->sync($job->trace);
        } else {
            $job->trace?->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);
        }

        $audit->record('ai_job_cancelled', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => 'Job de IA cancelado manualmente.',
            'evidence' => [
                'agent_slug' => $job->agent_slug,
                'provider' => $job->provider,
                'attempts' => $job->attempts,
                'cancelled_jobs' => $jobsToCancel->count(),
            ],
            'privacy' => $this->privacyFromJob($job),
            'refs' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
            ],
        ]);

        return response()->json([
            'job' => (new AiJobResource($job->refresh()->load(['trace', 'attemptHistory'])))->resolve(),
        ]);
    }

    private function privacyFromJob(AiJob $job): array
    {
        $privacy = data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy'));

        return is_array($privacy) ? $privacy : [];
    }

    private function isCouncilJob(AiJob $job): bool
    {
        return $job->kind === 'council'
            || data_get($job->payload, 'execution_policy') === 'dual_review'
            || data_get($job->metadata, 'execution_policy') === 'dual_review';
    }
}
