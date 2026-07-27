<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use Illuminate\Support\Str;

/**
 * Per-provider execution lanes (claude/codex/cursor/minimax/hermes).
 *
 * GOD-DEBULK D3 (2026-07-22): this file is the relocated home of the Dev claude
 * lane governance-consult pin (ProviderGovernanceConsult::consultBeforeSpawn
 * inside executeClaudeProvider). The architectural invariant is unchanged —
 * the consult still fires before the spawn — only the file location moved.
 * Pinned by tests/Feature/Architecture/ProviderSpawnGovernanceConsultTest.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class ProviderExecutionSection
{
    public function __construct(
        private readonly WorkspaceGitSupport $workspaceGit,
        private readonly ProviderResultSupport $providerResult,
        private readonly ResolverSupport $resolver,
        private readonly GovernanceSection $governance,
    ) {}

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeLockedProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        ?string $hermesPromptOverride = null,
    ): array {
        if (! $promptProjection->isSendable()) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'prompt_projection_not_sendable:'.implode(',', $promptProjection->qualityChecks->failedChecks()),
                    stderr: 'Atlas Dev refused to dispatch provider because ProviderPromptProjection is not sendable.',
                    providerSafe: false,
                ),
                0,
            ];
        }

        return match ($taskContract->providerLock->provider) {
            SonnetClaudeCliAdapter::PROVIDER => $this->executeClaudeProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCodexCliInvocationDriver::PROVIDER => $this->executeCodexProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCursorCliInvocationDriver::PROVIDER => $this->executeCursorProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER => $this->executeMinimaxProvider($envelope, $taskContract, $promptProjection),
            'hermes_cli' => $this->executeHermesProvider($envelope, $taskContract, $promptProjection, $hermesPromptOverride),
            default => [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'unsupported_provider_lock:'.$taskContract->providerLock->provider,
                    stderr: 'Atlas Dev has no runtime driver for provider_lock.provider='.$taskContract->providerLock->provider.'.',
                ),
                0,
            ],
        };
    }

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeClaudeProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $gateway = $this->resolver->resolve(ClaudeCliGateway::class);
        if (! $gateway instanceof ClaudeCliGateway) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: SonnetClaudeCliAdapter::PROVIDER,
                    modelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
                    error: 'claude_cli_gateway_unbound',
                    stderr: 'Claude CLI gateway is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $adapter = new SonnetClaudeCliAdapter($gateway);

        // SLICE 2 — the Dev claude path drives ClaudeCliGateway DIRECTLY, skipping
        // AiProviderManager. Rather than run blind, consult the SHARED governance
        // seam (same cost-guard + ADML the manager runs) before executing. It
        // records this execution as CONSULTED (coverage rises) and, only when the
        // operator flips enforce ON with a hard threshold, can block the spawn.
        // Fail-open: no seam bound => proceeds exactly as today.
        $consult = $this->resolver->resolve(ProviderGovernanceConsult::class);
        if (is_object($consult) && method_exists($consult, 'consultBeforeSpawn')) {
            $advisory = $consult->consultBeforeSpawn([
                'provider' => SonnetClaudeCliAdapter::PROVIDER,
                'surface' => ProviderGovernanceCoverageLedger::SURFACE_DEV_CLAUDE_GATEWAY,
                'executor' => 'dev',
                'actor' => 'dev',
                'prompt' => $promptProjection->renderedPromptText,
                'kind' => 'atlas_dev_run',
            ]);
            if (($advisory['should_block'] ?? false) === true) {
                return [
                    $this->providerResult->blockedProviderCallResult(
                        runId: $promptProjection->runId,
                        provider: SonnetClaudeCliAdapter::PROVIDER,
                        modelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
                        error: 'governance_cost_guard_block',
                        stderr: 'Governance cost guard blocked spawn (enforce ON): '.(string) ($advisory['reason'] ?? 'cost_guard_hard_exceeded'),
                    ),
                    0,
                ];
            }
        } else {
            // MULTX-05 (partial, no enforce flip): the seam was resolved to null
            // OR did not implement `consultBeforeSpawn`. Record the skip so the
            // fail-open path stops being invisible; the flip observe→enforce is
            // a separate governed slice (ELEV-26 window), NEVER done here.
            $this->governance->recordGovernanceConsultSkipped(
                provider: SonnetClaudeCliAdapter::PROVIDER,
                surface: ProviderGovernanceCoverageLedger::SURFACE_DEV_CLAUDE_GATEWAY,
                executor: 'dev',
                reason: is_object($consult)
                    ? GovernanceConsultSkipCounter::REASON_METHOD_MISSING
                    : GovernanceConsultSkipCounter::REASON_SEAM_UNBOUND,
            );
        }

        return [
            $adapter->executeOneCall(
                promptProjection: $promptProjection,
                taskContract: $taskContract,
                workspace: $envelope->workspace,
                timeoutSeconds: $this->providerResult->providerTimeoutSeconds($taskContract),
            ),
            1,
        ];
    }

    /**
     * Codex CLI is used as a governed workspace mutator for review/repair gates.
     * Atlas derives the diff after Codex returns and still runs scope +
     * verification before a completion claim can be promoted.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeCodexProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolver->resolveConcrete(AtlasForgeCodexCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCodexCliInvocationDriver) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'codex_cli_driver_unavailable',
                    stderr: 'Codex CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));
        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.codex_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'review/repair current workspace diff; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'sandbox' => 'workspace-write',
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerResult->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerResult->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $providerChangedFiles = $this->workspaceGit->stringList((array) ($result['changed_files'] ?? []));
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->workspaceGit->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $blockers[] = 'codex_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $errors = array_values(array_unique($blockers));
        if ($errors === []) {
            $stdout = $this->workspaceGit->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Codex CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Codex CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * Cursor CLI is a governed workspace mutator: unlike the Claude adapter it
     * edits the isolated worktree directly. We therefore convert the post-run
     * git diff into the ProviderCallResult stdout and later skip re-applying it.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeCursorProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolver->resolveConcrete(AtlasForgeCursorCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCursorCliInvocationDriver) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'cursor_cli_driver_unavailable',
                    stderr: 'Cursor CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));

        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.cursor_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'mutate only allowed files; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerResult->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerResult->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $scopeViolations = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? $path : '',
            (array) ($result['scope_violations'] ?? []),
        ), static fn (string $path): bool => $path !== ''));

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $stdout = '';
        $errors = $blockers;
        if ($scopeViolations !== []) {
            $errors[] = 'cursor_cli_scope_violations:'.implode(',', $scopeViolations);
        }
        if ($blockers === []) {
            $stdout = $this->workspaceGit->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Cursor CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Cursor CLI runtime blocked: ".implode(',', $blockers)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * MiniMax M3 — writes files directly into the workspace (like Cursor).
     * The worker handles context compilation, invocation and repair loop.
     * We derive the git diff after writes complete and surface it as stdout.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeMinimaxProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $worker = $this->resolver->resolveConcrete(AtlasMinimaxFirstWorkerService::class);
        if (! $worker instanceof AtlasMinimaxFirstWorkerService) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'minimax_worker_unbound',
                    stderr: 'AtlasMinimaxFirstWorkerService is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $finding = [
            'title' => mb_substr($envelope->normalizedIntent, 0, 300),
            'description' => mb_substr($promptProjection->renderedPromptText, 0, 2_000),
            'spec_seed' => ['candidate_id' => $taskContract->taskId],
        ];

        $startMs = (int) (microtime(true) * 1_000);
        $result = $worker->run([
            'finding' => $finding,
            'allowed_files' => array_values($taskContract->allowedFiles),
            'validation_commands' => array_values($taskContract->validationCommands),
            'worktree_path' => $envelope->workspace,
            'repo_root' => $envelope->workspace,
            'max_repairs' => $taskContract->repairPolicy->maxAttempts,
        ]);
        $durationMs = (int) (microtime(true) * 1_000) - $startMs;

        $status = (string) ($result['status'] ?? 'blocked');
        $tokensUsed = (int) ($result['run_summary']['provider_call']['tokens_used'] ?? 0);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $b): string => is_string($b) ? $b : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $b): bool => $b !== ''));

        if ($status !== 'completed') {
            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: 'MiniMax worker: '.implode('; ', $blockers ?: [$status]),
                    durationMs: $durationMs,
                    tokensIn: $tokensUsed,
                    tokensOut: 0,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: $blockers ?: [$status],
                ),
                1,
            ];
        }

        // Worker wrote files directly — derive diff like Cursor provider.
        $stdout = $this->workspaceGit->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
        if (trim($stdout) === '') {
            $stdout = 'no_patch_needed: true
reason: MiniMax worker completed without a workspace diff in allowed_files.
';
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: 0,
                stdout: $stdout,
                stderr: '',
                durationMs: $durationMs,
                tokensIn: $tokensUsed,
                tokensOut: 0,
                costEstimateUsd: null,
                providerSafe: true,
            ),
            1,
        ];
    }

    /**
     * Hermes CLI is the Atlas executive runtime governed through
     * {@see HermesCliProvider}. Like Codex/Cursor/MiniMax it
     * mutates the isolated workspace directly, so Atlas derives the post-run
     * git diff and still runs scope + verification before any completion claim.
     *
     * The provider chooses its own cwd via
     * {@see RunsCliProcesses::workdirForJob()}, which
     * reads (in order) payload.tool_permissions.workspace, payload.workspace,
     * then config('atlas.ai.workdir') — realpath()'d and required to be a dir.
     * We therefore pin BOTH workspace keys to $envelope->workspace so Hermes
     * edits the Dev worktree and not the global Atlas workdir.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    public function executeHermesProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        ?string $promptOverride = null,
    ): array {
        $manager = app(AiProviderManager::class);

        $provider = null;
        try {
            $provider = $manager->get('hermes_cli');
        } catch (\Throwable) {
            $provider = null;
        }
        if (! $provider instanceof AiProvider) {
            return [
                $this->providerResult->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: 'hermes_cli',
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'hermes_cli_provider_unavailable',
                    stderr: 'Hermes CLI provider could not be resolved from AiProviderManager.',
                ),
                0,
            ];
        }

        $timeoutSeconds = $this->providerResult->providerTimeoutSeconds($taskContract);
        $hermesOverrides = $this->atlasDevHermesOverrides($taskContract);

        // M2: When a repair attempt overrides the prompt (failure context fed
        // forward), use the override text instead of the original projection.
        $promptText = $promptOverride ?? $promptProjection->renderedPromptText;

        // workdirForJob() reads tool_permissions.workspace || workspace ||
        // config('atlas.ai.workdir'). Pin both so Hermes runs IN the Dev
        // worktree ($envelope->workspace) and edits files there.
        $usageFile = tempnam(sys_get_temp_dir(), 'atlas-dev-hermes-');
        $job = new AiJob([
            'trace_id' => 'atlas-dev:'.$promptProjection->runId,
            'kind' => 'atlas_dev_run',
            'provider' => 'hermes_cli',
            'model' => $this->hermesModelForContract($taskContract),
            'prompt' => $promptText,
            'input_text' => $promptText,
            'timeout_seconds' => $timeoutSeconds,
            'payload' => [
                'workspace' => $envelope->workspace,
                // mode 'danger' → HermesCliProvider passes --yolo so Hermes edits the
                // isolated workspace AUTONOMOUSLY (a non-interactive run has no TTY to
                // approve writes); ScopeGuard + verification gate the result downstream.
                'tool_permissions' => HermesWorkspaceDefaults::toolPermissions($envelope->workspace),
                'dev_execution_plan' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                ],
                'hermes' => array_merge($hermesOverrides, array_filter([
                    'usage_file' => is_string($usageFile) ? $usageFile : null,
                ])),
            ],
        ]);

        // Snapshot pré-run dos ignorados-proibidos (vendor/, caches): o
        // detector pós-run reporta só o delta como mutação do provider.
        $preIgnoredForbidden = $this->workspaceGit->ignoredForbiddenSnapshot($envelope->workspace, $taskContract->forbiddenFiles);

        $startMs = (int) (microtime(true) * 1_000);
        try {
            $result = $provider->run($job, $promptText);
        } catch (\Throwable $e) {
            if (is_string($usageFile)) {
                @unlink($usageFile);
            }

            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: 'hermes_cli',
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: Str::limit($e->getMessage(), 500, '...'),
                    durationMs: (int) (microtime(true) * 1_000) - $startMs,
                    tokensIn: null,
                    tokensOut: null,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: ['hermes_cli_invocation_threw'],
                ),
                1,
            ];
        }

        $errors = $result->ok ? [] : array_values(array_filter([
            is_string($result->errorCode) && $result->errorCode !== '' ? $result->errorCode : null,
        ]));
        $usage = (array) data_get($result->metadata, 'hermes_usage', []);
        if (is_string($usageFile)) {
            @unlink($usageFile);
        }

        // Hermes mutated the workspace directly — derive diff like the
        // Codex/Cursor/MiniMax providers and let scope/verification gate it.
        $providerChangedFiles = $errors === []
            ? $this->workspaceGit->stringList($this->workspaceGit->changedFilePathsInWorkspace(
                $envelope->workspace,
                $taskContract->allowedFiles,
                $taskContract->forbiddenFiles,
                $preIgnoredForbidden,
            ))
            : [];
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->workspaceGit->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $errors[] = 'hermes_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        if ($errors === []) {
            $stdout = $this->workspaceGit->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Hermes CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Hermes CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: 'hermes_cli',
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $errors === [] ? 0 : 1,
                stdout: $stdout,
                stderr: '',
                durationMs: (int) $result->durationMs,
                tokensIn: is_numeric($usage['input_tokens'] ?? null)
                    ? (int) $usage['input_tokens']
                    : null,
                tokensOut: is_numeric($usage['output_tokens'] ?? null)
                    ? (int) $usage['output_tokens']
                    : null,
                costEstimateUsd: is_numeric($usage['estimated_cost_usd'] ?? null)
                    ? (float) $usage['estimated_cost_usd']
                    : 0.0,
                providerSafe: true,
                errors: array_values(array_unique($errors)),
            ),
            $result->ok ? 1 : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasDevHermesOverrides(LightTaskContract $taskContract): array
    {
        $overrides = [];

        $transport = strtolower(trim((string) config('atlas_dev.efficient.hermes_execution_transport', '')));
        if (in_array($transport, ['cli', 'acp'], true)) {
            $overrides['execution_transport'] = $transport;
        }

        // transporte cli = one-shot `hermes -z` DE VERDADE. Sem isto o provider
        // caía em `hermes chat --max-turns 1 --query`: no repo real 1 turno só
        // explora e nunca edita (fire test 03/07: toy passava por sorte — o
        // one-shot com o MESMO prompt editou e validou; o chat devolvia
        // no_patch_needed). O one-shot roda a missão completa e ignora
        // max_turns por construção.
        if ($transport === 'cli') {
            $overrides['cli_oneshot'] = true;
        }

        $singleFileMaxTurns = (int) config('atlas_dev.efficient.hermes_single_file_max_turns', 0);
        if (count($taskContract->allowedFiles) === 1 && $singleFileMaxTurns > 0) {
            $overrides['max_turns'] = max(1, min(10, $singleFileMaxTurns));
        }

        return $overrides;
    }

    public function hermesModelForContract(LightTaskContract $taskContract): string
    {
        $model = trim($taskContract->providerLock->modelFamily);

        return $model === '' || $model === 'hermes_cli_default'
            ? HermesWorkspaceDefaults::model()
            : $model;
    }
}
