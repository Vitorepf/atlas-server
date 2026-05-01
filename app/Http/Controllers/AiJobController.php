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

    public function resumeChoice(AiJob $job, Request $request, AuditLogService $audit, AiCouncilCoordinator $council): JsonResponse
    {
        $request->validate([
            'option_id' => ['required', 'string', 'max:64'],
        ]);

        if ($job->status !== 'awaiting_user_choice') {
            return response()->json([
                'error' => [
                    'code' => 'AI_JOB_NOT_AWAITING_CHOICE',
                    'message' => 'Job is not waiting for an operator choice.',
                ],
            ], 422);
        }

        $optionId = (string) $request->input('option_id');
        $options = (array) data_get($job->metadata, 'choice_options', []);
        $option = collect($options)->firstWhere('id', $optionId);

        if (! is_array($option)) {
            return response()->json([
                'error' => [
                    'code' => 'AI_JOB_CHOICE_NOT_FOUND',
                    'message' => "Option [{$optionId}] not found in choice_options.",
                ],
            ], 422);
        }

        $action = (string) ($option['action'] ?? '');
        $metadata = array_merge($job->metadata ?? [], [
            'provider_choice_state' => 'resolved',
            'provider_choice_resolved_at' => now()->toIso8601String(),
            'provider_choice_resolved_option' => $optionId,
        ]);

        match ($action) {
            'switch_provider' => $job->update([
                'status' => 'queued',
                'provider' => (string) ($option['provider'] ?? $job->provider),
                'model' => array_key_exists('model', $option) ? $option['model'] : $job->model,
                'available_at' => now(),
                'reserved_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'worker_id' => null,
                'error_code' => null,
                'error_message' => null,
                'metadata' => $metadata,
            ]),
            'wait' => $job->update([
                'status' => 'queued',
                'available_at' => isset($option['available_at_iso'])
                    ? \Carbon\Carbon::parse((string) $option['available_at_iso'])
                    : now()->addMinutes(15),
                'reserved_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'worker_id' => null,
                'metadata' => $metadata,
            ]),
            'fail' => $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_code' => (string) ($option['reason'] ?? 'choice_failed'),
                'error_message' => isset($option['cli_command'])
                    ? "Login required: rode `{$option['cli_command']}` no terminal e tente novamente."
                    : 'Operator chose to fail this job.',
                'metadata' => $metadata,
            ]),
            'cancel' => $this->cancelByChoice($job, $metadata),
            default => $job->update([
                'metadata' => $metadata,
            ]),
        };

        $audit->record('ai_job_choice_resolved', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Operador escolheu opção [{$optionId}] (action={$action}).",
            'evidence' => [
                'option_id' => $optionId,
                'action' => $action,
                'option' => $option,
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

    private function cancelByChoice(AiJob $job, array $metadata): void
    {
        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_code' => 'cancelled_by_operator',
            'error_message' => 'Operador cancelou o job durante escolha de provider.',
            'metadata' => $metadata,
        ]);
        $job->trace?->update(['status' => 'cancelled', 'completed_at' => now()]);
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
