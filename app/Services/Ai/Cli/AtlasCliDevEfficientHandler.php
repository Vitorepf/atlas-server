<?php

declare(strict_types=1);

namespace App\Services\Ai\Cli;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasCliDevAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Thin orchestration helper used by {@see AtlasCliDevCommand} when the
 * operator passes `--efficient`. Reuses the **same** core pipeline that the
 * HTTP /plan and /run endpoints drive: orchestrator → token service →
 * RunExecutor. The CLI never re-implements business logic; it only frames
 * the arguments and renders the result.
 *
 * Single responsibility: keep `AtlasCliDevCommand::handle` readable while
 * the surface boundary lives entirely in `AtlasCliDevAdapter`.
 */
final class AtlasCliDevEfficientHandler
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_FLAG_DISABLED = 'flag_disabled';

    public const OUTCOME_PLAN_FAILED = 'plan_failed';

    public const OUTCOME_PLAN_ONLY = 'plan_only_emitted';

    public const OUTCOME_CONFIRMATION_REQUIRED = 'confirmation_required';

    public const OUTCOME_NOT_EXECUTABLE = 'not_executable';

    public const OUTCOME_TOKEN_FAILED = 'token_failed';

    public const OUTCOME_RUN_FAILED = 'run_failed';

    public function __construct(
        private readonly AtlasDevFastPathOrchestrator $orchestrator,
        private readonly AtlasCliDevAdapter $adapter,
        private readonly ConfirmationTokenService $tokens,
        private readonly RunExecutor $runExecutor,
        private readonly ReceiptStorage $storage,
        private readonly ConfigRepository $config,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated input bag from the command:
     *   - workspace, raw_intent (strings, required)
     *   - user_constraints (list<string>)
     *   - operator_confirmed (bool — true when --yes was passed)
     *   - flow_origin, command_intent (optional Router hints)
     *   - thread_id, conversation_id, composer_mode, composer_task, provider_choice (optional)
     * @return array{outcome:string,exit_code:int,payload:array<string,mixed>}
     */
    public function run(array $input): array
    {
        if (! (bool) $this->config->get('atlas_dev.efficient.plan_enabled', false)) {
            return $this->fail(self::OUTCOME_FLAG_DISABLED, 2, [
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_DISABLED',
                    'message' => 'Atlas Dev plan flow is disabled by feature flag (atlas_dev.efficient.plan_enabled).',
                ],
            ]);
        }

        try {
            $envelope = $this->adapter->buildEnvelope($input);
            $plan = $this->orchestrator->planOnly(
                surfaceId: $envelope->surfaceId,
                workspace: $envelope->workspace,
                rawIntent: $envelope->rawIntent,
                userConstraints: $envelope->userConstraints,
                surfaceHints: $this->surfaceHintsFromEnvelope($envelope),
            );
        } catch (Throwable $e) {
            return $this->fail(self::OUTCOME_PLAN_FAILED, 64, [
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_FAILED',
                    'message' => $e->getMessage(),
                ],
            ]);
        }

        $planPayload = $this->adapter->formatPlanOnly($plan);
        $operatorConfirmed = (bool) ($input['operator_confirmed'] ?? false);
        $isExecutable = $plan->routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH;

        $body = $this->renderPlanBody($plan, $planPayload, $operatorConfirmed, $isExecutable);

        // F-04 parity: scrub any absolute workspace prefix that survived the
        // canonical summary (mini_spec.expected_files, discovery candidates,
        // etc.) before the CLI renders it.
        $body = $this->redactor->redactWorkspaceIn($body, $plan->envelope->workspace);

        if (! $isExecutable) {
            return $this->ok(self::OUTCOME_NOT_EXECUTABLE, 0, $body);
        }

        if (! $operatorConfirmed) {
            $body['confirmation_required'] = true;
            $body['confirmation_hint'] = 'Re-run with --yes to execute this plan.';

            return $this->ok(self::OUTCOME_CONFIRMATION_REQUIRED, 0, $body);
        }

        if (! (bool) $this->config->get('atlas_dev.efficient.run_enabled', false)) {
            return $this->fail(self::OUTCOME_FLAG_DISABLED, 2, [
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_DISABLED',
                    'message' => 'Atlas Dev run flow is disabled by feature flag (atlas_dev.efficient.run_enabled).',
                ],
            ]);
        }

        $issue = $this->tokens->issue(
            runId: $plan->envelope->runId,
            taskContractHash: $plan->taskContract->taskContractHash,
            surfaceId: $plan->envelope->surfaceId,
        );

        $consume = $this->tokens->validateAndConsume(
            runId: $plan->envelope->runId,
            taskContractHash: $plan->taskContract->taskContractHash,
            plaintext: $issue->plaintext,
        );

        if ($consume->ok !== true) {
            return $this->fail(self::OUTCOME_TOKEN_FAILED, 66, [
                'error' => [
                    'code' => 'CONFIRMATION_TOKEN_REJECTED',
                    'reason' => $consume->reason,
                ],
            ]);
        }

        try {
            [$envelopeRebuilt, $taskContract, $promptProjection] = $this->reloadArtifacts($plan->envelope->runId);
            $runResult = $this->runExecutor->execute(
                envelope: $envelopeRebuilt,
                taskContract: $taskContract,
                promptProjection: $promptProjection,
                runId: $plan->envelope->runId,
            );
        } catch (Throwable $e) {
            return $this->fail(self::OUTCOME_RUN_FAILED, 70, [
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_FAILED',
                    'message' => $e->getMessage(),
                ],
            ]);
        }

        $runArray = $runResult->toArray();
        // Strip absolute persisted_receipt_paths; downstream surfaces poll the
        // Show endpoint to get redacted refs.
        unset($runArray['persisted_receipt_paths']);

        $body['run'] = array_merge(
            $runArray,
            [
                'persisted_receipt_refs' => $this->redactor->artifactRefs(
                    $plan->envelope->runId,
                    $runResult->persistedReceiptPaths,
                ),
                'confirmation_token_id' => $consume->tokenId,
            ],
        );
        $body['confirmation_required'] = false;

        return $this->ok(self::OUTCOME_OK, 0, $body);
    }

    /**
     * @return array{0: OperationEnvelope, 1: LightTaskContract, 2: ProviderPromptProjection}
     */
    private function reloadArtifacts(string $runId): array
    {
        $envelope = $this->storage->read($runId, ArtifactNames::OPERATION_ENVELOPE);
        $taskContract = $this->storage->read($runId, ArtifactNames::TASK_CONTRACT);
        $promptProjection = $this->storage->read($runId, ArtifactNames::PROMPT_PROJECTION);

        if ($envelope === null || $taskContract === null || $promptProjection === null) {
            throw new \RuntimeException("Atlas Dev: missing plan artifacts for run_id={$runId}.");
        }

        return [
            OperationEnvelope::fromArray($envelope),
            LightTaskContract::fromArray($taskContract),
            ProviderPromptProjection::fromArray($promptProjection),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function surfaceHintsFromEnvelope(OperationEnvelope $envelope): array
    {
        $hints = [];
        $ctx = $envelope->surfaceContext;
        if ($ctx->threadId !== null) {
            $hints['thread_id'] = $ctx->threadId;
        }
        if ($ctx->conversationId !== null) {
            $hints['conversation_id'] = $ctx->conversationId;
        }
        if ($ctx->composerMode !== null) {
            $hints['composer_mode'] = $ctx->composerMode;
        }
        if ($ctx->composerTask !== null) {
            $hints['composer_task'] = $ctx->composerTask;
        }
        if ($ctx->providerChoice !== null) {
            $hints['provider_choice'] = $ctx->providerChoice;
        }

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $planPayload
     * @return array<string, mixed>
     */
    private function renderPlanBody(PlanOnlyResult $plan, array $planPayload, bool $operatorConfirmed, bool $isExecutable): array
    {
        $suggested = null;
        if ($plan->routing->kind !== RoutingDecision::ATLAS_DEV_FAST_PATH) {
            $suggested = $this->suggestedFlowFromRouting($plan);
        }

        return [
            'kind' => 'plan_only',
            'run_id' => $plan->envelope->runId,
            'surface_id' => $plan->envelope->surfaceId,
            'workspace_label' => $this->redactor->workspaceLabel($plan->envelope->workspace),
            'workspace_hash' => $plan->envelope->workspaceHash,
            'routing' => [
                'kind' => $plan->routing->kind,
                'reasons' => array_values($plan->routing->reasons),
                'blockers' => array_values($plan->blockers),
                'is_executable' => $isExecutable,
                'suggested_flow' => $suggested,
            ],
            'task_kind' => $plan->classification->taskKind,
            'risk_level' => $plan->riskLevel,
            'mode' => $plan->compactSdd->mode,
            'hashes' => $planPayload['hashes'] ?? [],
            'expected_files' => $planPayload['expected_files'] ?? [],
            'allowed_files' => $planPayload['allowed_files'] ?? [],
            'verification_commands' => $planPayload['verification_commands'] ?? [],
            'persisted_artifact_refs' => $planPayload['persisted_artifact_refs'] ?? [],
            'prompt_sendable' => $planPayload['prompt_sendable'] ?? false,
            'operator_confirmed' => $operatorConfirmed,
        ];
    }

    private function suggestedFlowFromRouting(PlanOnlyResult $plan): ?string
    {
        return match ($plan->routing->kind) {
            RoutingDecision::READ_ONLY_ANSWER => 'atlas_explain',
            RoutingDecision::FORGE_PROMOTION_PREVIEW => 'atlas_forge',
            RoutingDecision::BLOCKED => 'atlas_conversation',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome:string,exit_code:int,payload:array<string,mixed>}
     */
    private function ok(string $outcome, int $exitCode, array $payload): array
    {
        return ['outcome' => $outcome, 'exit_code' => $exitCode, 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome:string,exit_code:int,payload:array<string,mixed>}
     */
    private function fail(string $outcome, int $exitCode, array $payload): array
    {
        return ['outcome' => $outcome, 'exit_code' => $exitCode, 'payload' => $payload];
    }
}
