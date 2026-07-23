<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\AiProviderChoiceBuilder;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\HumanSurface\AiExecutionPresentationState;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Provider pause/fallback family (gemini→claude auto-fallback, provider-choice
 * pause, fair-mode fallback gating, invocation-fingerprint persistence)
 * extracted VERBATIM from AiWorker (GOD-DEBULK entangled-family split). Bodies
 * are byte-identical modulo parent-back-reference rewrites (cross-cutting
 * helpers via $this->parent). Facade AiWorker keeps same-signature delegators
 * for the hot-path entry points.
 */
class ProviderPauseFallbackSection
{
    public function __construct(
        private readonly AiWorker $parent,
        private readonly FairClaudePolicy $fairClaude,
        private readonly AiExecutionPresentationState $presentationStates,
        private readonly AiProviderChoiceBuilder $choices,
        private readonly AiWorkerLogger $logger,
        private readonly AuditLogService $audit,
        private readonly ProviderUsagePayload $providerUsage,
        private readonly ProviderFairnessBudgetSection $providerFairnessBudget,
    ) {}

    public function shouldPauseForChoice(AiJob $job, AiProviderResult $result): bool
    {
        if (! in_array($result->errorCode, ['rate_limited', 'auth_expired'], true)) {
            return false;
        }

        if ($this->parent->isCouncilJob($job)) {
            return false;
        }

        return data_get($job->metadata, 'provider_choice_state') !== 'resolved';
    }

    public function persistInvocationFingerprint(AiJob $job, AiProviderResult $result): void
    {
        $fingerprint = data_get($result->metadata, 'claude_invocation_fingerprint');
        if (! is_array($fingerprint)) {
            return;
        }

        $metadata = array_merge(is_array($job->metadata) ? $job->metadata : [], [
            'claude_invocation_fingerprint' => $fingerprint,
        ]);
        $job->forceFill(['metadata' => $metadata])->save();

        $trace = $job->trace ?: $job->trace()->first();
        if ($trace) {
            $trace->forceFill([
                'metadata' => array_merge($trace->metadata ?? [], [
                    'claude_invocation_fingerprint' => $fingerprint,
                ]),
            ])->save();
        }
    }

    public function shouldFallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result): bool
    {
        if ($this->fairClaude->isFairPayload(is_array($job->payload) ? $job->payload : [])
            || $this->fairClaude->isFairPayload(is_array($job->metadata) ? $job->metadata : [])) {
            return false;
        }

        if ($this->parent->isCouncilJob($job)) {
            return false;
        }

        if (($attempt->provider ?: $job->provider) !== 'gemini_cli') {
            return false;
        }

        if (! in_array($result->errorCode, ['rate_limited', 'auth_expired'], true)) {
            return false;
        }

        if (! $this->fallbackProviderWithinBudget($job, 'claude_cli')) {
            return false;
        }

        return data_get($job->metadata, 'gemini_fallback_attempted') !== true;
    }

    public function fallbackProviderWithinBudget(AiJob $job, string $provider): bool
    {
        return $this->providerFairnessBudget->fallbackProviderWithinBudget($job, $provider);
    }

    public function fallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $fallbackProvider = 'claude_cli';
        $presentationState = $this->presentationStates->automaticProviderFallback(trace: $job->trace);
        $metadata = array_merge($job->metadata ?? [], [
            'gemini_fallback_attempted' => true,
            'fallback_state' => 'queued',
            'fallback_reason' => $result->errorCode,
            'fallback_error_message' => $result->errorMessage,
            'fallback_degraded' => true,
            'lost_capabilities' => ['long_context_full', 'native_multimodal'],
            'original_provider' => 'gemini_cli',
            'original_model' => $attempt->model ?: $job->model,
            'fallback_provider' => $fallbackProvider,
            'fallback_at' => now()->toIso8601String(),
        ]);
        $payload = is_array($job->payload) ? $job->payload : [];
        $payload['provider_fallback'] = [
            'original_provider' => 'gemini_cli',
            'original_model' => $attempt->model ?: $job->model,
            'fallback_provider' => $fallbackProvider,
            'fallback_reason' => $result->errorCode,
            'fallback_degraded' => true,
            'lost_capabilities' => ['long_context_full', 'native_multimodal'],
        ];

        $job->forceFill([
            'status' => 'queued',
            'provider' => $fallbackProvider,
            'model' => null,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'finished_at' => null,
            'max_attempts' => max((int) $job->max_attempts, (int) $job->attempts + 1),
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        $job->trace?->forceFill([
            'status' => 'queued',
            'provider' => $fallbackProvider,
            'model' => null,
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'provider_fallback' => $payload['provider_fallback'],
                'last_error_code' => $result->errorCode,
                'last_error_message' => $result->errorMessage,
                'presentation_state' => $presentationState,
            ]),
        ])->save();

        if ($job->trace) {
            $this->parent->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_recovering', '', [
                'presentation_state' => $presentationState,
            ], 'system');
        }

        $this->logger->event(
            eventType: 'provider_fallback_requeued',
            message: 'Gemini job requeued to Claude after quota/capacity or auth fallback.',
            severity: 'warning',
            provider: 'gemini_cli',
            job: $job,
            attempt: $attempt,
            metadata: [
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
                'worker_id' => $workerId,
            ],
            workerId: $workerId,
        );
        $this->parent->recordTelemetry('provider_fallback_requeued', $job, $attempt, [
            'event_phase' => 'worker',
            'duration_ms' => $result->durationMs,
            'metadata' => [
                'worker_id' => $workerId,
                'original_provider' => 'gemini_cli',
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
            ],
        ]);
        $this->parent->recordLedgerEvent(LedgerEventType::ProviderFallback, $job, $attempt, $this->providerUsage->fallback(
            $job,
            $attempt,
            $result,
            $fallbackProvider,
            'gemini_to_claude',
            $this->parent->kernelContextForJob($job),
        ), $workerId);
        $this->audit->record('ai_provider_fallback_requeued', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'severity' => 'warning',
            'summary' => 'Gemini indisponivel ou sem autenticacao; job reenfileirado para Claude.',
            'evidence' => [
                'original_provider' => 'gemini_cli',
                'original_model' => $attempt->model ?: $job->model,
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ],
            'privacy' => $this->parent->privacyFromJob($job),
            'refs' => [
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt->id,
            ],
        ]);

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    public function pauseForChoice(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $resetAtIso = data_get($result->metadata, 'provider_reset_at');
        $resetAt = is_string($resetAtIso)
            ? (function () use ($resetAtIso): ?\Carbon\CarbonImmutable {
                try {
                    return CarbonImmutable::parse($resetAtIso);
                } catch (\Throwable $e) {
                    return null;
                }
            })()
            : null;

        $options = $this->choices->build(
            errorCode: (string) $result->errorCode,
            currentProvider: (string) ($attempt->provider ?: $job->provider),
            currentModel: $job->model,
            resetAt: $resetAt,
            fairMode: $this->fairClaude->isFairPayload(is_array($job->payload) ? $job->payload : [])
                || $this->fairClaude->isFairPayload(is_array($job->metadata) ? $job->metadata : []),
        );

        $lastAttemptedId = data_get($job->metadata, 'provider_choice_last_attempted_option_id');
        if (is_string($lastAttemptedId) && $lastAttemptedId !== '') {
            $options = array_values(array_filter(
                $options,
                fn (array $opt): bool => ($opt['id'] ?? null) !== $lastAttemptedId,
            ));
        }

        $metadata = array_merge($job->metadata ?? [], [
            'provider_choice_state' => 'pending',
            'provider_choice_error_code' => $result->errorCode,
            'provider_choice_offered_at' => now()->toIso8601String(),
            'provider_reset_at' => $resetAtIso,
            'reset_hint' => data_get($result->metadata, 'reset_hint'),
            'choice_options' => $options,
        ]);
        $presentationState = $this->presentationStates->providerChoice(
            errorCode: (string) $result->errorCode,
            options: $options,
            resetAt: is_string($resetAtIso) ? $resetAtIso : null,
            trace: $job->trace,
        );
        $metadata['presentation_state'] = $presentationState;

        $attempt->update([
            'status' => 'failed',
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'stdout_excerpt' => Str::limit($result->stdout, 4000, '...'),
            'stderr_excerpt' => Str::limit($result->stderr, 4000, '...'),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'finished_at' => now(),
            'metadata' => $result->metadata,
        ]);

        $job->update([
            'status' => 'awaiting_user_choice',
            'available_at' => now()->addYear(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'metadata' => $metadata,
        ]);
        if ($job->trace) {
            $traceMetadata = array_merge($job->trace->metadata ?? [], [
                'presentation_state' => $presentationState,
            ]);
            $job->trace->update([
                'status' => 'awaiting_user_choice',
                'metadata' => $traceMetadata,
            ]);
        }

        $this->parent->emitStreamEvent(
            $job,
            $attempt,
            'lifecycle',
            'provider_choice_required',
            '',
            [
                'presentation_state' => $presentationState,
            ],
            'system',
            null,
        );

        $this->logger->event(
            eventType: 'provider_choice_required',
            message: 'AI job paused awaiting operator choice on provider failure.',
            severity: 'warning',
            provider: $attempt->provider,
            job: $job,
            attempt: $attempt,
            metadata: [
                'error_code' => $result->errorCode,
                'option_ids' => array_column($options, 'id'),
            ],
            workerId: $workerId,
        );

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

}
