<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Http\Controllers\Controller;
use App\Http\Requests\AtlasDev\RunRequest;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Runtime\RunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use App\Support\AtlasSecurity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /ai/interactions/atlas-dev/run
 *
 * Invariants (per atlas-dev-efficient-programming-flow-v1.md §26.1):
 *   - operator_confirmed must be literal true (400 otherwise).
 *   - run_id must reference a persisted plan (404 otherwise).
 *   - task_contract_hash must match the persisted task_contract (422 otherwise).
 *   - confirmation_token must be valid, unexpired, not previously consumed
 *     and bound to (run_id, task_contract_hash) (403 otherwise).
 *   - Provider lock = claude_cli + sonnet (enforced inside SonnetClaudeCliAdapter).
 *   - Receipts persisted before responding.
 *
 * Confirmation token = DB+HMAC ConfirmationTokenService (F-05 canonical). The
 * filesystem ConfirmationTokenStore was removed.
 */
final class RunController extends Controller
{
    public function __construct(
        private readonly RunExecutor $executor,
        private readonly ReceiptStorage $storage,
        private readonly ConfirmationTokenService $tokens,
        private readonly ConfigRepository $config,
        private readonly AtlasDevRunIndexRepository $runIndex,
        private readonly RunWorkerDispatcher $workerDispatcher,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    public function __invoke(RunRequest $request): JsonResponse
    {
        if (! $this->config->get('atlas_dev.efficient.run_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_DISABLED',
                    'message' => 'Atlas Dev run endpoint is disabled by feature flag.',
                ],
            ], 503);
        }

        if ($request->input('operator_confirmed') !== true) {
            return response()->json([
                'error' => [
                    'code' => 'OPERATOR_NOT_CONFIRMED',
                    'message' => 'operator_confirmed must be literal boolean true.',
                ],
            ], 400);
        }

        $runId = (string) $request->input('run_id');
        $providedHash = (string) $request->input('task_contract_hash');
        $token = (string) $request->input('confirmation_token');

        $envelopePayload = $this->storage->read($runId, ArtifactNames::OPERATION_ENVELOPE);
        $taskContractPayload = $this->storage->read($runId, ArtifactNames::TASK_CONTRACT);
        $promptPayload = $this->storage->read($runId, ArtifactNames::PROMPT_PROJECTION);

        if ($envelopePayload === null || $taskContractPayload === null || $promptPayload === null) {
            return response()->json([
                'error' => [
                    'code' => 'PLAN_NOT_FOUND',
                    'message' => "No persisted plan artifacts for run_id '{$runId}'.",
                ],
            ], 404);
        }

        $surfaceId = (string) ($envelopePayload['surface_id'] ?? '');
        if ($surfaceId === 'atlas_desktop_ai' && ! $this->config->get('atlas_dev.efficient.desktop_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_DESKTOP_DISABLED',
                    'message' => 'Atlas Dev Desktop integration is disabled by feature flag.',
                ],
            ], 503);
        }

        $persistedHash = (string) ($taskContractPayload['task_contract_hash'] ?? '');
        if ($persistedHash === '' || ! hash_equals($persistedHash, $providedHash)) {
            return response()->json([
                'error' => [
                    'code' => 'TASK_CONTRACT_HASH_MISMATCH',
                    'message' => 'task_contract_hash does not reference the persisted plan.',
                ],
            ], 422);
        }

        $tokenResult = $this->tokens->validateAndConsume($runId, $providedHash, $token);
        if (! $tokenResult->ok) {
            return $this->tokenFailureResponse($tokenResult);
        }

        try {
            $envelope = OperationEnvelope::fromArray($envelopePayload);
            $taskContract = LightTaskContract::fromArray($taskContractPayload);
            $promptProjection = ProviderPromptProjection::fromArray($promptPayload);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_FAILED',
                    'message' => $this->redactThrowableMessage($e->getMessage(), $token),
                ],
            ], 500);
        }

        $dispatchMode = (string) $this->config->get('atlas_dev.efficient.run_dispatch_mode', 'process');
        if ($dispatchMode === 'inline') {
            try {
                $result = $this->executeProviderRun(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    promptProjection: $promptProjection,
                    runId: $runId,
                    providedHash: $providedHash,
                    expectedCompactSddHash: $tokenResult->expectedCompactSddHash,
                );
            } catch (CompactSddUnavailableException $e) {
                // F-03 fail-closed: the receipt cannot be composed without an
                // honest task_kind / risk_level. Provider was NOT invoked.
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode(),
                        'message' => $e->getMessage(),
                        'reason' => $e->reasonCode,
                        'detail' => $e->detail,
                        'run_id' => $e->runId,
                    ],
                ], 422);
            } catch (Throwable $e) {
                return response()->json([
                    'error' => [
                        'code' => 'ATLAS_DEV_RUN_FAILED',
                        'message' => $this->redactThrowableMessage($e->getMessage(), $token),
                    ],
                ], 500);
            }

            return response()->json([
                'data' => array_merge($result, [
                    'run_id' => $runId,
                    'task_contract_hash' => $providedHash,
                ]),
            ], 200);
        }

        $this->recordRunState($runId, 'queued', [
            'dispatch_mode' => $dispatchMode,
            'task_contract_hash' => $providedHash,
        ]);
        $this->runIndex->updateCompletion($runId, 'queued');

        if ($dispatchMode === 'process') {
            try {
                $pid = $this->workerDispatcher->dispatch($runId, $providedHash, $tokenResult->expectedCompactSddHash);
                $this->recordRunState($runId, 'queued', [
                    'dispatch_mode' => $dispatchMode,
                    'task_contract_hash' => $providedHash,
                    'worker_pid' => $pid,
                ]);
            } catch (Throwable $e) {
                $this->recordRunState($runId, 'failed', [
                    'error_code' => 'ATLAS_DEV_RUN_DISPATCH_FAILED',
                    'message' => $this->redactThrowableMessage($e->getMessage(), $token),
                    'task_contract_hash' => $providedHash,
                ]);
                $this->runIndex->updateCompletion($runId, 'failed');

                return response()->json([
                    'error' => [
                        'code' => 'ATLAS_DEV_RUN_DISPATCH_FAILED',
                        'message' => 'Atlas Dev failed to start the run worker.',
                    ],
                ], 500);
            }

            return response()->json([
                'data' => [
                    'ok' => true,
                    'run_id' => $runId,
                    'task_contract_hash' => $providedHash,
                    'state' => 'queued',
                    'completion_state' => null,
                    'dispatch_mode' => $dispatchMode,
                    'worker_pid' => $pid,
                ],
            ], 202);
        }

        app()->terminating(function () use ($envelope, $taskContract, $promptProjection, $runId, $providedHash, $tokenResult, $token): void {
            $this->recordRunState($runId, 'running', [
                'task_contract_hash' => $providedHash,
            ]);
            $this->runIndex->updateCompletion($runId, 'running');

            try {
                $result = $this->executeProviderRun(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    promptProjection: $promptProjection,
                    runId: $runId,
                    providedHash: $providedHash,
                    expectedCompactSddHash: $tokenResult->expectedCompactSddHash,
                );
                $this->recordRunState($runId, 'complete', [
                    'completion_state' => $result['completion_state'] ?? null,
                    'task_contract_hash' => $providedHash,
                ]);
            } catch (CompactSddUnavailableException $e) {
                $this->recordRunState($runId, 'failed', [
                    'error_code' => $e->errorCode(),
                    'reason' => $e->reasonCode,
                    'detail' => $e->detail,
                    'task_contract_hash' => $providedHash,
                ]);
                $this->runIndex->updateCompletion($runId, 'failed');
            } catch (Throwable $e) {
                $this->recordRunState($runId, 'failed', [
                    'error_code' => 'ATLAS_DEV_RUN_FAILED',
                    'message' => $this->redactThrowableMessage($e->getMessage(), $token),
                    'task_contract_hash' => $providedHash,
                ]);
                $this->runIndex->updateCompletion($runId, 'failed');
            }
        });

        return response()->json([
            'data' => [
                'ok' => true,
                'run_id' => $runId,
                'task_contract_hash' => $providedHash,
                'state' => 'queued',
                'completion_state' => null,
                'dispatch_mode' => $dispatchMode,
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeProviderRun(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        string $providedHash,
        ?string $expectedCompactSddHash,
    ): array {
        $this->extendRequestTimeLimitForProviderRun();

        $result = $this->executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            runId: $runId,
            // F-03 tamper-protection: the confirmation_token row pinned
            // the CompactSDD hash at Plan time. Forwarding it here lets
            // the executor fail closed BEFORE invoking the provider when
            // compact_sdd.json has been mutated between Plan and Run.
            expectedCompactSddHash: $expectedCompactSddHash,
        );

        $this->runIndex->updateCompletion($runId, $result->completionState, $result->verificationReceiptHash);

        // F-04: redact persisted receipt paths into provider-safe refs before
        // surfacing them in the HTTP body. The struct still carries the
        // absolute paths for internal callers (telemetry, scope guard log).
        $body = $result->toArray();
        if (isset($body['persisted_receipt_paths']) && is_array($body['persisted_receipt_paths'])) {
            $body['persisted_receipt_refs'] = $this->redactor->artifactRefs($runId, $body['persisted_receipt_paths']);
            unset($body['persisted_receipt_paths']);
        }

        return array_merge($body, [
            'run_id' => $runId,
            'task_contract_hash' => $providedHash,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordRunState(string $runId, string $status, array $extra = []): void
    {
        $this->storage->writeMonotonic($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE, array_filter([
            'schema_version' => 'atlas.dev.run_execution_state.v1',
            'run_id' => $runId,
            'status' => $status,
            'recorded_at' => now()->toISOString(),
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function tokenFailureResponse(ConfirmationTokenResult $result): JsonResponse
    {
        // F-09: a missing APP_KEY is a server-side config problem (500), not
        // a client-fixable 403. Operator MUST rotate APP_KEY to unblock.
        if ($result->reason === ConfirmationTokenResult::REASON_KEY_MISSING) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_KEY_MISSING',
                    'message' => 'Atlas Dev confirmation_token signing key is not configured.',
                ],
            ], 500);
        }

        return response()->json([
            'error' => [
                'code' => $this->errorCodeFor($result->reason),
                'message' => 'confirmation_token rejected: '.$result->reason,
            ],
        ], 403);
    }

    private function extendRequestTimeLimitForProviderRun(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $providerTimeout = (int) $this->config->get('atlas.ai.timeout_seconds', 600);
        $seconds = max(360, $providerTimeout + 60);

        @set_time_limit($seconds);
    }

    private function redactThrowableMessage(string $message, string $confirmationToken): string
    {
        $message = AtlasSecurity::redactString($message);
        if ($confirmationToken !== '') {
            $message = str_replace($confirmationToken, '[redacted]', $message);
        }

        $redacted = preg_replace('#/(?:Users|private/var|var/folders|tmp)/[^\s"\']+#', '[path-redacted]', $message);

        return is_string($redacted) ? $redacted : $message;
    }

    private function errorCodeFor(string $reason): string
    {
        return match ($reason) {
            // NOT_FOUND, INVALID and RUN_ID_MISMATCH all leak from the client's
            // POV as "the token you sent does not exist for this run". We map
            // them to a single CONFIRMATION_TOKEN_INVALID code to keep the
            // surface tight and avoid attacker probing of internal states.
            ConfirmationTokenResult::REASON_NOT_FOUND,
            ConfirmationTokenResult::REASON_INVALID,
            ConfirmationTokenResult::REASON_RUN_ID_MISMATCH => 'CONFIRMATION_TOKEN_INVALID',
            ConfirmationTokenResult::REASON_EXPIRED => 'CONFIRMATION_TOKEN_EXPIRED',
            ConfirmationTokenResult::REASON_ALREADY_USED => 'CONFIRMATION_TOKEN_ALREADY_CONSUMED',
            ConfirmationTokenResult::REASON_TASK_CONTRACT_MISMATCH => 'CONFIRMATION_TOKEN_CONTRACT_MISMATCH',
            default => 'CONFIRMATION_TOKEN_REJECTED',
        };
    }
}
