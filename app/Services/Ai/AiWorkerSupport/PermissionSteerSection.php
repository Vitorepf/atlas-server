<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Governance\AiPermissionDecision;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\Surface\AtlasFinalResponseSanitizer;
use Illuminate\Support\Str;

/**
 * Operator permission + pending-steer application and provider-result metadata shaping, extracted VERBATIM from AiWorker (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor scanner
 * pins stay on the facade. No scanner pin token moved with this family.
 */
class PermissionSteerSection
{
    public function __construct(
        private readonly AtlasFinalResponseSanitizer $finalResponses,
        private readonly AiSessionStateService $states,
    ) {}

    public function sanitizeProviderResultForOperator(AiProviderResult $result): AiProviderResult
    {
        if ($result->output === '') {
            return $result;
        }

        [$output, $sanitization] = $this->finalResponses->sanitize($result->output);
        if (($sanitization['changed'] ?? false) !== true) {
            return $result;
        }

        return new AiProviderResult(
            ok: $result->ok,
            output: $output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, [
                'final_response_sanitization' => $sanitization,
            ]),
        );
    }

    public function applyPermissionRuntime(AiJob $job, AiPermissionDecision $permission): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $toolPermissions = is_array(data_get($payload, 'tool_permissions'))
            ? data_get($payload, 'tool_permissions')
            : [];
        $payload['tool_permissions'] = array_merge($toolPermissions, $permission->runtimePayload());

        $job->forceFill(['payload' => $payload])->save();

        return $job->refresh()->load('trace');
    }

    public function applyPendingSteer(AiJob $job): AiJob
    {
        $trace = $job->trace ?: $job->trace()->first();
        $thread = $trace?->thread()->first();
        $session = $trace?->session()->first();

        if (! $trace || ! $thread) {
            return $job;
        }

        $steer = $this->states->consumePendingSteer($thread, $session);
        if (! is_string($steer) || trim($steer) === '') {
            return $job;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $steerPayload = [
            'content' => Str::limit(trim($steer), 4000, '...'),
            'injected_at' => now()->toJSON(),
            'source' => 'ai_session_state.pending_steer',
        ];

        $job->update([
            'prompt' => AiWorkerPendingSteerPromptSupport::promptWithPendingSteer($job->prompt, $steerPayload['content']),
            'payload' => array_merge($payload, [
                'pending_steer' => $steerPayload,
            ]),
            'metadata' => array_merge($metadata, [
                'pending_steer_injected' => true,
                'pending_steer_injected_at' => $steerPayload['injected_at'],
            ]),
        ]);

        $trace->update([
            'metadata' => array_merge($trace->metadata ?? [], [
                'pending_steer' => [
                    'injected' => true,
                    'injected_at' => $steerPayload['injected_at'],
                ],
            ]),
        ]);

        return $job->refresh()->load('trace');
    }

    public function withPermissionMetadata(AiProviderResult $result, AiPermissionDecision $permission): AiProviderResult
    {
        return new AiProviderResult(
            ok: $result->ok,
            output: $result->output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, ['permission' => $permission->toArray()]),
        );
    }

    public function withPowerSessionMetadata(AiProviderResult $result, string $powerSessionId): AiProviderResult
    {
        return new AiProviderResult(
            ok: $result->ok,
            output: $result->output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, ['power_session_id' => $powerSessionId]),
        );
    }
}
