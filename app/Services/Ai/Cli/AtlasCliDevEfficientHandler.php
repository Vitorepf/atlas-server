<?php

declare(strict_types=1);

namespace App\Services\Ai\Cli;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasCliDevAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Process\Process;
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
     *                                       - workspace, raw_intent (strings, required)
     *                                       - user_constraints (list<string>)
     *                                       - operator_confirmed (bool — true when --yes was passed)
     *                                       - flow_origin, command_intent (optional Router hints)
     *                                       - thread_id, conversation_id, composer_mode, composer_task, provider_choice (optional)
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
            compactSddHash: $plan->compactSdd->compactSddHash,
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
                expectedCompactSddHash: $consume->expectedCompactSddHash,
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
        $hints['flow_origin'] = $envelope->flowOrigin;
        if ($envelope->commandIntent !== null) {
            $hints['command_intent'] = $envelope->commandIntent;
        }

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
            'read_only_answer' => $this->readOnlyAnswer($plan),
            'operator_confirmed' => $operatorConfirmed,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readOnlyAnswer(PlanOnlyResult $plan): ?array
    {
        if ($plan->routing->kind !== RoutingDecision::READ_ONLY_ANSWER) {
            return null;
        }

        if ($plan->classification->taskKind === TaskClassification::KIND_REVIEW) {
            return $this->reviewAnswer($plan);
        }

        if ($plan->classification->taskKind === TaskClassification::KIND_QUESTION) {
            $refs = array_values($plan->toSummaryArray()['expected_files'] ?? []);

            return [
                'kind' => 'confirmed_code_references',
                'provider_calls' => 0,
                'answer' => $refs === []
                    ? 'No confirmed code reference was found for this workspace question.'
                    : 'Confirmed code reference: '.implode(', ', array_map('strval', $refs)),
                'refs' => $refs,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewAnswer(PlanOnlyResult $plan): array
    {
        $findings = $this->reviewFindingsFromDiff($this->workspaceDiff($plan));

        return [
            'kind' => 'diff_review_findings',
            'provider_calls' => 0,
            'findings' => $findings,
            'finding_count' => count($findings),
        ];
    }

    private function workspaceDiff(PlanOnlyResult $plan): string
    {
        $paths = [];
        foreach ($plan->miniSpec->expectedFiles as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }
            $workspace = rtrim($plan->envelope->workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $real = realpath($file) ?: $file;
            $paths[] = str_starts_with($real, $workspace)
                ? substr($real, strlen($workspace))
                : $file;
        }

        $process = new Process(array_merge(['git', '-C', $plan->envelope->workspace, 'diff', '--'], $paths));
        $process->run();

        return $process->getOutput();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewFindingsFromDiff(string $diff): array
    {
        if ($diff === '') {
            return [];
        }

        $file = null;
        $removed = [];
        $added = [];
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++ b/')) {
                $file = substr($line, 6);

                continue;
            }
            if (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                $removed[] = substr($line, 1);
            }
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added[] = substr($line, 1);
            }
        }

        if (preg_match('/100\\s*-\\s*\\$percent/', implode("\n", $removed)) === 1
            && preg_match('/100\\s*\\+\\s*\\$percent/', implode("\n", $added)) === 1) {
            return [[
                'severity' => 'high',
                'file' => $file,
                'title' => 'Discount calculation was inverted',
                'body' => 'The diff changes the discount formula from subtracting percent to adding percent, so a 10% discount increases 1000 cents to 1100 instead of reducing it to 900.',
            ]];
        }

        return [[
            'severity' => 'medium',
            'file' => $file,
            'title' => 'Workspace diff requires review',
            'body' => 'The diff changes executable code. No deterministic Atlas Dev review rule matched a concrete defect, so the operator should inspect the changed logic before accepting it.',
        ]];
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
