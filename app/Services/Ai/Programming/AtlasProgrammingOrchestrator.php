<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\AtlasAiPolicyService;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Provider\AgentBehaviorContract;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyRepoOnboardingService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use App\Services\Engineering\EngineeringHarnessExecutionService;
use Illuminate\Support\Str;
use Throwable;

class AtlasProgrammingOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly AtlasAiPolicyService $policies,
        private readonly AtlasDecideService $decide,
        private readonly EngineeringHarnessExecutionService $harness,
        private readonly AgentBehaviorContract $agentBehavior,
        private readonly ProgrammingRetrievalPlanner $retrievalPlanner,
        private readonly ProgrammingStageReceiptStore $stageReceipts,
        private readonly ProgrammingResumeService $resumeService,
        private readonly ProgrammingSandboxManager $sandboxManager,
        private readonly ProgrammingTestImpactAnalyzer $testImpactAnalyzer,
        private readonly ProgrammingPatchVerifier $patchVerifier,
        private readonly ProgrammingLearningCandidateProjector $learningCandidates,
        private readonly ProgrammingRepairExecutor $repairExecutor,
        private readonly ?AtlasContextOperationsRuntimeService $contextOperations = null,
        private readonly ?AtlasPersistentContextRuntimeService $persistentContext = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sessionPlan(string $workspace, string $profile = 'dev', array $options = []): array
    {
        $profile = $profile === 'forge' ? 'forge' : 'dev';
        $workspace = realpath($workspace) ?: $workspace;
        $complete = $profile === 'forge' || (bool) ($options['complete'] ?? false);
        $maxIterations = ProgrammingIterationPolicy::forExecutionPolicy(
            $options['max_iterations'] ?? null,
            $complete,
            $profile === 'forge',
        );
        $programmingFlow = $this->programmingFlow($profile, $options);
        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => 'dev',
            'routing_task' => $programmingFlow,
            'decision_mode' => isset($options['provider']) && is_string($options['provider']) && trim($options['provider']) !== ''
                ? 'manual_override'
                : 'atlas_decide',
            'operator_requested_provider' => isset($options['provider']) && is_string($options['provider']) && trim($options['provider']) !== ''
                ? trim($options['provider'])
                : 'auto',
            'requested_provider' => isset($options['provider']) && is_string($options['provider']) && trim($options['provider']) !== ''
                ? trim($options['provider'])
                : null,
            'requested_model' => isset($options['model']) && is_string($options['model']) && trim($options['model']) !== ''
                ? trim($options['model'])
                : null,
            'programming_profile' => $profile,
            'programming_flow' => $programmingFlow,
            'dev_execution_plan' => [
                'programming_profile' => $profile,
                'programming_flow' => $programmingFlow,
                'complete' => $complete,
                'operator_options' => [
                    'complete' => $complete,
                    'auto_test' => $profile === 'forge' || (bool) ($options['auto_test'] ?? false),
                    'max_iterations' => $maxIterations,
                    'force_harness' => (bool) ($options['force_harness'] ?? false),
                    'programming_intent' => is_string($options['intent'] ?? null) ? trim((string) $options['intent']) : null,
                ],
            ],
        ];
        if (is_array($options['ai_policy_override'] ?? null) && $options['ai_policy_override'] !== []) {
            $payload['ai_policy_override'] = $options['ai_policy_override'];
        }
        $decisionOptions = $this->decide->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => (string) ($options['task'] ?? ''),
            'provider' => $payload['requested_provider'],
            'model' => $payload['requested_model'],
            'payload' => $payload,
        ]);
        $decision = $this->decide->operationalDecision($decisionOptions);
        $policyProfile = (array) data_get($decision->toArray(), 'policy_profile', $this->policies->effectiveProfile($decisionOptions));
        $executionPolicy = (array) data_get($policyProfile, 'execution_policy', []);
        $policyContracts = $this->policyContracts($policyProfile);
        $policyMaxIterations = ProgrammingIterationPolicy::forRepairPolicy(
            data_get($executionPolicy, 'max_iterations', $maxIterations),
            $maxIterations,
        );
        $policyAutoTest = (bool) data_get($executionPolicy, 'auto_test', $profile === 'forge' || (bool) ($options['auto_test'] ?? false));

        $planId = (string) Str::orderedUuid();
        $parentPlanId = is_string($options['parent_plan_id'] ?? null) && trim((string) $options['parent_plan_id']) !== ''
            ? trim((string) $options['parent_plan_id'])
            : null;

        $plan = [
            'schema_version' => 1,
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'programming_profile' => $profile,
            'programming_flow' => $programmingFlow,
            'workspace' => $workspace,
            'interactive' => (bool) ($options['interactive'] ?? true),
            'executor_decision' => $this->executorDecision($profile, $decision->toArray(), $options, $policyProfile),
            'execution_profile' => [
                'complete' => $complete,
                'auto_test' => $policyAutoTest,
                'max_iterations' => $policyMaxIterations,
                'open_brain' => (string) data_get($executionPolicy, 'open_brain', $profile === 'forge' ? 'required' : 'auto'),
                'done_policy' => (bool) data_get($executionPolicy, 'quality_required', $profile === 'forge')
                    ? 'evidence_required'
                    : 'evidence_preferred',
                'engineering_harness' => (bool) data_get($executionPolicy, 'harness_required', false)
                    ? 'required'
                    : ($profile === 'forge' ? 'preferred_for_medium_or_high_risk' : 'automatic_when_needed'),
                'quality_required' => (bool) data_get($executionPolicy, 'quality_required', false),
                'gate_contract' => data_get($policyContracts, 'gates'),
                'tool_contract' => data_get($policyContracts, 'tools'),
            ],
            'policy_profile' => $policyProfile,
            'policy_contracts' => $policyContracts,
            'agent_behavior_contract' => $this->agentBehavior->toArray(),
            'operational_decision' => $decision->toArray(),
            'created_at' => now()->toJSON(),
        ];
        $previousReceipts = is_array($options['previous_stage_receipts'] ?? null)
            ? $options['previous_stage_receipts']
            : [];
        $plan['agentic_rag_plan'] = $this->retrievalPlanner->plan(
            planId: $planId,
            workspace: $workspace,
            objective: (string) ($options['task'] ?? ''),
            flow: $programmingFlow,
            options: [
                'quality_required' => (bool) data_get($executionPolicy, 'quality_required', false),
                'previous_stage_receipts' => $previousReceipts,
            ],
        );
        $plan['stage_receipt_plan'] = [
            'schema_version' => 'atlas.programming.stage_receipt_plan.v1',
            'expected_receipts' => $this->stageReceipts->expectedReceipts(
                planId: $planId,
                parentPlanId: $parentPlanId,
                stages: ['plan', 'review', 'patch', 'test', 'repair'],
            ),
        ];
        $plan['resume_state'] = $this->resumeService->state($planId, $parentPlanId, $previousReceipts);
        $plan['test_impact_plan'] = $this->testImpactAnalyzer->analyze(
            changedFiles: [],
            codeGraph: (array) data_get($plan, 'agentic_rag_plan.semantic_code_graph', []),
            risk: (string) data_get($decision->toArray(), 'task_profile.risk_level', 'medium'),
        );
        $plan['sandbox_plan'] = $this->sandboxManager->plan(
            workspace: $workspace,
            risk: (string) data_get($decision->toArray(), 'task_profile.risk_level', 'medium'),
            write: (string) data_get($policyContracts, 'tools.mode', 'workspace_write') !== 'read_only',
        );
        $plan['patch_verifier_gate'] = $this->patchVerifier->verify([
            'changed_files' => [],
            'tests' => [],
            'no_test_reason' => 'planning_stage_no_patch_yet',
            'action_manifests' => [['schema_version' => 'atlas.programming.action_manifest.v1', 'stage' => 'plan']],
        ]);
        $plan['learning_candidate_policy'] = $this->learningCandidates->project([
            'status' => 'planned',
            'evidence_refs' => ['agentic_rag:'.data_get($plan, 'agentic_rag_plan.retrieval_receipt.receipt_id')],
        ]);
        $plan['persistent_context'] = $this->persistentContextContract($plan, $options);
        $plan['context_operations'] = $this->contextOperationsContract($plan, $options);
        $plan['context_intelligence'] = data_get($plan, 'context_operations.context_intelligence');
        $plan['conversation_ops'] = data_get($plan, 'context_operations.conversation_ops');
        $plan['programming_orchestration_contract'] = $this->programmingOrchestrationContract($plan, $options);
        $frontendContract = $this->frontendDesignHarnessContract($plan, $options);
        if ($frontendContract !== null) {
            $plan['frontend_design_harness_contract'] = $frontendContract;
        }
        $plan['repair_execution_contract'] = $this->repairExecutionContract($plan);

        return $plan;
    }

    public function executeWithHarness(ProgrammingExecutionRequest $request): ProgrammingExecutionResult
    {
        return $this->harness->execute($request);
    }

    public function orchestratorId(): string
    {
        return 'AtlasProgrammingOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['programming'];
    }

    public function supportedFlows(): array
    {
        return [
            'programming.dev',
            'programming.repair',
            'programming.review',
            'programming.refactor',
            'programming.qa',
            'programming.security',
            'programming.database',
            'programming.frontend',
            'programming.visual',
            'programming.forge',
        ];
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    public function plan(string $flow, array $input = [], array $context = []): array
    {
        $profile = str_ends_with($flow, '.forge') || $flow === 'forge' ? 'forge' : 'dev';

        return $this->sessionPlan(
            workspace: (string) ($context['workspace'] ?? $input['workspace'] ?? base_path()),
            profile: $profile,
            options: array_merge($input, $context, [
                'flow' => str_replace('programming.', '', $flow),
                'task' => (string) ($input['task'] ?? $input['text'] ?? ''),
            ]),
        );
    }

    public function execute(array $plan, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => 'requires_runner',
            'reason' => 'programming_execution_is_dispatched_by_cli_or_engineering_harness',
            'plan' => $plan,
            'context' => $context,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        $attempt = max(1, (int) ($context['attempt'] ?? data_get($context, 'repair.attempt', 1)));
        $maxAttempts = max($attempt, (int) ($context['max_attempts'] ?? data_get($context, 'repair.max_attempts', 3)));
        $retrievalPlan = (array) ($context['agentic_rag_plan'] ?? $context['retrieval_plan'] ?? []);
        $repairPlan = $this->repairExecutor->attemptPlan($failure, $retrievalPlan, $attempt, $maxAttempts);

        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => data_get($repairPlan, 'status', 'planned'),
            'repair_plan' => $repairPlan,
            'repair_capsule' => data_get($repairPlan, 'repair_capsule'),
            'repair_prompt' => $this->repairPrompt(
                (string) ($context['task'] ?? data_get($context, 'objective', 'programming repair')),
                array_merge($failure, ['repair_capsule' => data_get($repairPlan, 'repair_capsule')]),
                $attempt,
                $maxAttempts,
            ),
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'decision' => $result['decision'] ?? null,
            'evidence_refs' => (array) ($result['evidence_refs'] ?? []),
            'context' => $context,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $programmingMessagePlan
     * @return array<string,mixed>|null
     */
    public function dispatchContract(?array $programmingMessagePlan): ?array
    {
        if ($programmingMessagePlan === null) {
            return null;
        }

        $executor = (string) data_get($programmingMessagePlan, 'executor_decision.executor', 'simple_provider_execution');

        return [
            'schema_version' => 1,
            'status' => 'selected',
            'source' => 'AtlasProgrammingOrchestrator',
            'dispatch_path' => $executor === 'engineering_harness'
                ? 'programming_orchestrator_harness'
                : 'ai_gateway_provider',
            'executor' => $executor,
            'reason' => data_get($programmingMessagePlan, 'executor_decision.reason'),
            'programming_profile' => data_get($programmingMessagePlan, 'programming_profile'),
            'policy_profile_id' => data_get($programmingMessagePlan, 'policy_profile.profile_id'),
            'profile_context' => data_get($programmingMessagePlan, 'policy_profile.profile_context'),
            'execution_policy' => data_get($programmingMessagePlan, 'policy_profile.execution_policy'),
            'policy_contracts' => data_get($programmingMessagePlan, 'policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.effective_policy.operational_contracts'),
            'programming_orchestration_contract' => data_get($programmingMessagePlan, 'programming_orchestration_contract'),
            'frontend_design_harness_contract' => data_get($programmingMessagePlan, 'frontend_design_harness_contract'),
            'agentic_rag_plan' => data_get($programmingMessagePlan, 'agentic_rag_plan'),
            'stage_receipt_plan' => data_get($programmingMessagePlan, 'stage_receipt_plan'),
            'resume_state' => data_get($programmingMessagePlan, 'resume_state'),
            'persistent_context' => data_get($programmingMessagePlan, 'persistent_context'),
            'context_operations' => data_get($programmingMessagePlan, 'context_operations'),
            'context_intelligence' => data_get($programmingMessagePlan, 'context_intelligence'),
            'conversation_ops' => data_get($programmingMessagePlan, 'conversation_ops'),
            'sandbox_plan' => data_get($programmingMessagePlan, 'sandbox_plan'),
            'operational_decision_id' => data_get($programmingMessagePlan, 'operational_decision.decision_id'),
            'plan_id' => data_get($programmingMessagePlan, 'plan_id'),
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function persistentContextContract(array $plan, array $options): array
    {
        $flow = (string) data_get($plan, 'programming_flow', 'dev');
        $profile = (string) data_get($plan, 'programming_profile', 'dev');
        $workspace = (string) data_get($plan, 'workspace', base_path());
        $flowId = $profile === 'forge' ? 'atlas_forge' : match ($flow) {
            'repair' => 'atlas_debug',
            'review' => 'atlas_review',
            default => 'atlas_dev',
        };
        $receiptId = data_get($plan, 'agentic_rag_plan.retrieval_receipt.receipt_id');
        $decisionId = data_get($plan, 'operational_decision.decision_id');
        $contextRefs = array_values(array_filter([
            data_get($plan, 'agentic_rag_plan.context_pack.context_pack_hash'),
            data_get($plan, 'resume_state.continuation_packet.context_pack_hash'),
        ], 'is_string'));
        $evidenceRefs = array_values(array_filter([
            is_string($receiptId) && $receiptId !== '' ? 'agentic_rag:'.$receiptId : null,
            is_string($decisionId) && $decisionId !== '' ? 'decision:'.$decisionId : null,
        ], 'is_string'));

        if (! $this->workspaceLooksProjectScoped($workspace)) {
            $runtime = [
                'workspace' => $workspace,
                'flow_id' => $flowId,
                'plan_id' => data_get($plan, 'plan_id'),
                'reason' => 'workspace_not_project_scoped',
            ];

            return [
                'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                'status' => AtlasPersistentContextRuntimeService::STATUS_DEGRADED,
                'scope' => [
                    'scope_type' => 'programming_plan',
                    'scope_id' => (string) data_get($plan, 'plan_id'),
                    'workspace' => $workspace,
                    'surface_id' => 'atlas_programming_orchestrator',
                    'domain' => 'programming',
                    'flow_id' => $flowId,
                    'provider' => data_get($plan, 'executor_decision.provider') ?: data_get($plan, 'executor_decision.executor'),
                ],
                'reason' => 'workspace_not_project_scoped',
                'context_pack_hash' => hash('sha256', json_encode($runtime, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'persistent_context_hash' => hash('sha256', json_encode([
                    'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                    ...$runtime,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'must_know_ledger' => [],
                'evidence_refs' => $evidenceRefs,
                'claim_policy' => [
                    'provider_calls_made' => false,
                    'workspace_scan_skipped' => true,
                    'reason' => 'workspace_not_project_scoped',
                ],
                'writes' => false,
            ];
        }

        try {
            return ($this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class))->build([
                'prompt' => (string) ($options['task'] ?? data_get($plan, 'operational_decision.input_text', '')),
                'workspace' => $workspace,
                'surface_id' => 'atlas_programming_orchestrator',
                'domain' => 'programming',
                'flow_id' => $flowId,
                'flow_profile' => 'programming.'.$flow,
                'runtime_mode' => $profile === 'forge' ? 'forge' : 'dev',
                'provider' => data_get($plan, 'executor_decision.provider') ?: data_get($plan, 'executor_decision.executor'),
                'scope_type' => 'programming_plan',
                'scope_id' => (string) data_get($plan, 'plan_id'),
                'payload' => [
                    'workspace' => data_get($plan, 'workspace'),
                    'context_refs' => $contextRefs,
                    'routing_domain' => 'programming',
                    'routing_task' => $flowId,
                    'programming_profile' => $profile,
                    'programming_flow' => $flow,
                    'plan_id' => data_get($plan, 'plan_id'),
                    'agentic_rag_plan' => data_get($plan, 'agentic_rag_plan'),
                    'resume_state' => data_get($plan, 'resume_state'),
                ],
                'evidence_refs' => $evidenceRefs,
                'must_keep_items' => [
                    ['id' => 'plan_id', 'kind' => 'decision', 'value' => (string) data_get($plan, 'plan_id')],
                    ['id' => 'programming_flow', 'kind' => 'flow_route', 'value' => 'programming.'.$flow],
                    ['id' => 'executor', 'kind' => 'runtime_decision', 'value' => (string) data_get($plan, 'executor_decision.executor')],
                ],
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                'status' => AtlasPersistentContextRuntimeService::STATUS_DEGRADED,
                'error' => 'persistent_context_threw',
                'exception_class' => $exception::class,
                'writes' => false,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function contextOperationsContract(array $plan, array $options): array
    {
        $flow = (string) data_get($plan, 'programming_flow', 'dev');
        $profile = (string) data_get($plan, 'programming_profile', 'dev');
        $workspace = (string) data_get($plan, 'workspace', base_path());
        $flowId = $profile === 'forge' ? 'atlas_forge' : match ($flow) {
            'repair' => 'atlas_debug',
            'review' => 'atlas_review',
            default => 'atlas_dev',
        };
        $receiptId = data_get($plan, 'agentic_rag_plan.retrieval_receipt.receipt_id');
        $decisionId = data_get($plan, 'operational_decision.decision_id');
        $contextRefs = array_values(array_filter([
            data_get($plan, 'agentic_rag_plan.context_pack.context_pack_hash'),
            data_get($plan, 'resume_state.continuation_packet.context_pack_hash'),
        ], 'is_string'));
        $evidenceRefs = array_values(array_filter([
            is_string($receiptId) && $receiptId !== '' ? 'agentic_rag:'.$receiptId : null,
            is_string($decisionId) && $decisionId !== '' ? 'decision:'.$decisionId : null,
        ], 'is_string'));

        if (! $this->workspaceLooksProjectScoped($workspace)) {
            return [
                'schema_version' => AtlasContextOperationsRuntimeService::SCHEMA_VERSION,
                'status' => AtlasContextOperationsRuntimeService::STATUS_WATCH,
                'flow_binding' => [
                    'domain' => 'programming',
                    'flow_id' => $flowId,
                    'flow_profile' => 'programming.'.$flow,
                    'runtime_mode' => $profile === 'forge' ? 'forge' : 'dev',
                    'required_gates' => [],
                ],
                'reason' => 'workspace_not_project_scoped',
                'context_intelligence' => [
                    'schema_version' => 'atlas.context_intelligence.context_certification.v1',
                    'status' => 'watch',
                    'reason' => 'workspace_scan_skipped_for_non_project_workspace',
                ],
                'conversation_ops' => [
                    'schema_version' => 'atlas.conversation_ops.health_report.v1',
                    'status' => 'watch',
                    'reason' => 'workspace_scan_skipped_for_non_project_workspace',
                ],
                'handoff_packet' => [
                    'schema_version' => 'atlas.conversation_ops.handoff_packet.v1',
                    'role' => $profile === 'forge' ? 'forge_intake' : 'dev_runtime',
                    'task' => 'execute programming flow '.$flowId.' with project-scoped context required before mutation',
                    'allowed_scope' => ['domain:programming', 'flow:'.$flowId],
                    'evidence_refs' => $evidenceRefs,
                ],
                'integration_policy' => [
                    'context_required_for_flow' => true,
                    'verified_compaction_required' => $profile === 'forge',
                    'verified_compaction_deferred_until_project_scoped_workspace' => $profile === 'forge',
                    'handoff_required' => true,
                    'subagent_return_audit_required' => true,
                    'memory_promotion_requires_review' => true,
                    'workspace_scan_skipped' => true,
                ],
                'claim_policy' => [
                    'provider_calls_made' => false,
                    'workspace_scan_skipped' => true,
                    'reason' => 'workspace_not_project_scoped',
                ],
                'writes' => false,
                'operations_runtime_hash' => hash('sha256', json_encode([
                    'workspace' => $workspace,
                    'flow_id' => $flowId,
                    'plan_id' => data_get($plan, 'plan_id'),
                    'reason' => 'workspace_not_project_scoped',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ];
        }

        return ($this->contextOperations ?? app(AtlasContextOperationsRuntimeService::class))->evaluate([
            'prompt' => (string) ($options['task'] ?? ''),
            'domain' => 'programming',
            'flow_id' => $flowId,
            'flow_profile' => 'programming.'.$flow,
            'runtime_mode' => $profile === 'forge' ? 'forge' : 'dev',
            'scope_id' => (string) data_get($plan, 'plan_id'),
            'context_refs' => $contextRefs,
            'evidence_refs' => $evidenceRefs,
            'handoff_target' => [
                'kind' => $profile === 'forge' ? 'atlas_forge' : 'atlas_dev',
                'reason' => $profile === 'forge' ? 'programming_forge_profile' : 'programming_dev_profile',
            ],
            'policy_required' => true,
            'evidence_required' => (string) data_get($plan, 'execution_profile.done_policy') === 'evidence_required',
            'tool_plan_required' => true,
            'force_verified_compaction' => $profile === 'forge',
            'must_keep_items' => [
                ['id' => 'plan_id', 'kind' => 'decision', 'digest' => (string) data_get($plan, 'plan_id')],
                ['id' => 'programming_flow', 'kind' => 'flow_route', 'digest' => 'programming.'.$flow],
                ['id' => 'executor', 'kind' => 'runtime_decision', 'digest' => (string) data_get($plan, 'executor_decision.executor')],
            ],
            'turns' => [
                ['role' => 'user', 'content' => (string) ($options['task'] ?? '')],
            ],
        ]);
    }

    private function workspaceLooksProjectScoped(string $workspace): bool
    {
        $workspace = realpath($workspace) ?: $workspace;
        if (! is_dir($workspace)) {
            return false;
        }

        foreach (['.git', 'composer.json', 'package.json', 'pnpm-workspace.yaml', 'artisan', 'pyproject.toml', 'go.mod', 'Package.swift'] as $marker) {
            if (file_exists($workspace.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $dispatch
     * @return array<string,mixed>
     */
    public function harnessCompletionContract(array $result, array $dispatch, ?string $model = null): array
    {
        $status = (string) ($result['status'] ?? 'unknown');
        $changedFiles = array_values(array_filter((array) ($result['changed_files'] ?? data_get($result, 'harness_payload.run.changed_files', [])), 'is_string'));
        $actionManifests = array_values((array) ($result['action_manifests'] ?? data_get($result, 'harness_payload.action_manifests', [])));
        $testImpact = $this->testImpactAnalyzer->analyze(
            changedFiles: $changedFiles,
            codeGraph: (array) data_get($dispatch, 'agentic_rag_plan.semantic_code_graph', []),
            risk: (string) data_get($dispatch, 'execution_policy.risk_level', 'medium'),
        );
        $patchVerifier = $this->patchVerifier->verify([
            'changed_files' => $changedFiles,
            'tests' => (array) data_get($testImpact, 'selected_tests', []),
            'no_test_reason' => $changedFiles === [] ? 'no_patch_changes_reported' : null,
            'action_manifests' => $actionManifests !== [] ? $actionManifests : [['schema_version' => 'atlas.programming.action_manifest.v1', 'stage' => 'completion_projection']],
        ]);

        $completion = [
            'schema_version' => 1,
            'status' => in_array($status, ['passed', 'partial'], true) ? 'passed' : 'blocked',
            'executor' => (string) ($result['executor'] ?? data_get($dispatch, 'executor', 'engineering_harness')),
            'dispatch_path' => data_get($dispatch, 'dispatch_path'),
            'provider' => 'engineering_harness',
            'model' => $model,
            'task_id' => $result['task_id'] ?? null,
            'engineering_run_id' => data_get($result, 'harness_payload.run.id'),
            'score' => data_get($result, 'harness_payload.run.score'),
            'evidence_refs' => (array) ($result['evidence_refs'] ?? []),
            'blocking_failures' => (array) ($result['blocking_failures'] ?? []),
            'kernel_repair_decision' => $result['kernel_repair_decision'] ?? null,
            'repair_contract' => $result['repair_contract'] ?? null,
            'policy_contracts' => data_get($dispatch, 'policy_contracts'),
            'programming_orchestration_contract' => data_get($dispatch, 'programming_orchestration_contract'),
            'frontend_design_harness_contract' => data_get($dispatch, 'frontend_design_harness_contract'),
            'test_impact_receipt' => $testImpact,
            'patch_verifier_report' => $patchVerifier,
            'learning_candidate' => $this->learningCandidates->project([
                'status' => in_array($status, ['passed', 'partial'], true) ? 'passed' : 'blocked',
                'evidence_refs' => (array) ($result['evidence_refs'] ?? []),
            ]),
            'completed_at' => now()->toJSON(),
        ];

        $persistentContextUpdate = $this->persistentContextOutcomeContract($completion, $dispatch);
        if ($persistentContextUpdate !== null) {
            $completion['persistent_context_update'] = $persistentContextUpdate;
        }

        return array_filter($completion, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $completion
     * @param  array<string,mixed>  $dispatch
     * @return array<string,mixed>|null
     */
    private function persistentContextOutcomeContract(array $completion, array $dispatch): ?array
    {
        $runtime = data_get($dispatch, 'persistent_context');
        if (! is_array($runtime) || ($runtime['schema_version'] ?? null) !== AtlasPersistentContextRuntimeService::SCHEMA_VERSION) {
            return null;
        }

        try {
            return ($this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class))->recordOutcome($runtime, [
                'summary' => 'Programming completion projected by AtlasProgrammingOrchestrator.',
                'evidence_refs' => array_values(array_filter((array) ($completion['evidence_refs'] ?? []), 'is_string')),
                'confidence' => in_array(($completion['status'] ?? null), ['passed', 'partial'], true) ? 0.8 : 0.4,
                'memory_type' => 'technical_context',
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => 'atlas.persistent_context.post_execution_update.v1',
                'status' => 'degraded',
                'error' => 'persistent_context_outcome_threw',
                'exception_class' => $exception::class,
                'promotion_allowed' => false,
                'requires_confirmation' => true,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $programmingMessagePlan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    public function frontendDesignHarnessContract(array $programmingMessagePlan, array $options = []): ?array
    {
        $flow = (string) data_get($programmingMessagePlan, 'programming_flow', '');
        $profileId = (string) data_get($programmingMessagePlan, 'policy_profile.profile_id', '');
        $task = strtolower(Str::ascii((string) ($options['task'] ?? data_get($programmingMessagePlan, 'operational_decision.input_text', ''))));
        $isFrontend = in_array($flow, ['visual', 'frontend', 'programming.visual', 'programming.frontend'], true)
            || in_array($profileId, ['programming.visual', 'programming.frontend'], true)
            || $this->containsAny($task, ['frontend', 'ui', 'layout', 'screen', 'tela', 'component', 'react', 'expo', 'mobile visual', 'design system']);

        if (! $isFrontend) {
            return null;
        }

        $planId = (string) (data_get($programmingMessagePlan, 'plan_id') ?: Str::orderedUuid());
        $atlasFrontendRuntime = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => $task,
            'surface' => 'programming.frontend',
            'workspace' => (string) data_get($programmingMessagePlan, 'workspace', ''),
        ]);
        $preExecutionGate = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => $task,
            'surface' => 'programming.frontend',
            'workspace' => (string) data_get($programmingMessagePlan, 'workspace', ''),
            'acceptance_criteria' => (bool) ($options['acceptance_criteria'] ?? $options['frontend_acceptance'] ?? false),
            'test_plan' => (bool) ($options['test_plan'] ?? $options['frontend_test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($options['visual_quality_plan'] ?? $options['frontend_visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($options['evidence_plan'] ?? $options['frontend_evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($options['senior_design_review'] ?? $options['frontend_senior_design_review'] ?? false),
        ]);
        $workspace = (string) data_get($programmingMessagePlan, 'workspace', '');
        $frontendApp = trim((string) ($options['frontend_app'] ?? $options['frontend_app_subscope'] ?? ''));
        $enterpriseInput = [
            'task' => $task,
            'surface' => 'programming.frontend',
            'workspace' => $workspace,
            'frontend_app' => $frontendApp,
            'acceptance_criteria' => (bool) ($options['acceptance_criteria'] ?? $options['frontend_acceptance'] ?? false),
            'test_plan' => (bool) ($options['test_plan'] ?? $options['frontend_test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($options['visual_quality_plan'] ?? $options['frontend_visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($options['evidence_plan'] ?? $options['frontend_evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($options['senior_design_review'] ?? $options['frontend_senior_design_review'] ?? false),
        ];
        $selectedWorkspace = app(AtlasFrontendSelectedWorkspaceService::class)->resolve($enterpriseInput + [
            'selection_source' => 'atlas_dev_or_atlas_code',
        ]);
        $workspaceSelected = ($selectedWorkspace['status'] ?? null) === 'selected';
        $enterpriseBootstrap = $workspaceSelected
            ? app(AtlasFrontendEnterpriseBootstrapService::class)->run($enterpriseInput)
            : null;
        $companyRepoOnboarding = $workspaceSelected
            ? app(AtlasFrontendCompanyRepoOnboardingService::class)->run($enterpriseInput + [
                'provider' => (string) ($options['provider'] ?? $options['frontend_provider'] ?? 'provider_neutral'),
                'output' => (string) ($options['frontend_proof_output'] ?? $options['proof_output'] ?? ''),
            ])
            : null;
        $executionRunbook = $workspaceSelected
            ? app(AtlasFrontendExecutionRunbookService::class)->compile($enterpriseInput + [
                'evidence_output' => (string) ($options['frontend_evidence_output'] ?? $options['evidence_output'] ?? '<evidence-dir>'),
                'write_evidence_kit' => false,
            ])
            : null;
        $providerInstructionPacket = $workspaceSelected
            ? app(AtlasFrontendProviderInstructionPacketService::class)->compile($enterpriseInput + [
                'provider' => (string) ($options['provider'] ?? $options['frontend_provider'] ?? 'provider_neutral'),
                'evidence_output' => (string) ($options['frontend_evidence_output'] ?? $options['evidence_output'] ?? '<evidence-dir>'),
                'write_evidence_kit' => false,
            ])
            : null;

        return [
            'schema_version' => 'atlas.programming.frontend_design_harness.v1',
            'source' => 'AtlasProgrammingOrchestrator',
            'plan_id' => $planId,
            'status' => $enterpriseBootstrap !== null && ($enterpriseBootstrap['status'] ?? null) === 'ready' && ($executionRunbook['status'] ?? null) === 'ready' && ($providerInstructionPacket['status'] ?? null) === 'ready'
                ? 'ready_for_enterprise_frontend_dispatch'
                : 'contract_required_before_frontend_claim',
            'specialist_profile' => 'programming.frontend',
            'provider_policy' => [
                'provider_neutral' => true,
                'hardcode_claude_codex_gemini_forbidden' => true,
                'atlas_decide_required' => true,
                'external_skill_license_review_required_for_product_use' => true,
            ],
            'context_pack_required' => [
                'framework_routes_components',
                'design_tokens_or_reason',
                'existing_screenshots_or_reason',
                'viewport_device_matrix',
                'asset_provenance',
                'a11y_baseline_or_reason',
                'performance_budget_or_reason',
                'console_network_errors_or_reason',
                'prior_visual_regressions_or_reason',
            ],
            'output_types' => [
                'production_ui_patch',
                'clickable_prototype',
                'motion_design_asset',
                'deck_infographic',
            ],
            'required_gates' => [
                'typescript_or_reason',
                'eslint_or_biome_or_reason',
                'console_error_check',
                'visual_smoke_multi_viewport',
                'no_text_overlap',
                'responsive_check',
                'a11y_check_or_reason',
                'state_transition_check',
                'asset_provenance_check',
                'design_5d_review',
                'performance_budget_or_reason',
            ],
            'evidence_contract' => [
                'schema_version' => 'atlas.programming.frontend_evidence.v1',
                'required_before_done' => [
                    'context_pack_hash',
                    'changed_files_or_prototype_artifacts',
                    'tool_run_receipts',
                    'screenshots_or_reason',
                    'gate_results',
                    'design_review_summary',
                ],
                'visual_smoke_tool' => 'programming.visual_smoke',
                'hash_algorithm' => 'sha256',
            ],
            'completion_rules' => [
                'screenshot_alone_is_insufficient' => true,
                'visual_a11y_perf_state_required_or_reason' => true,
                'human_review_required_for_broad_visual_change' => true,
                'may_not_declare_enterprise_harness_complete_without_gate_receipts' => true,
            ],
            'atlas_frontend_runtime' => $atlasFrontendRuntime,
            'pre_execution_gate' => $preExecutionGate,
            'enterprise_operating_contract' => [
                'schema_version' => 'atlas.programming.frontend_enterprise_operating_contract.v1',
                'workspace_mode' => $selectedWorkspace['workspace_mode'] ?? 'generic_or_missing_workspace',
                'selected_workspace_schema' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
                'enterprise_bootstrap_schema' => AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION,
                'company_repo_onboarding_schema' => AtlasFrontendCompanyRepoOnboardingService::SCHEMA_VERSION,
                'execution_runbook_schema' => AtlasFrontendExecutionRunbookService::SCHEMA_VERSION,
                'provider_instruction_packet_schema' => AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION,
                'selected_workspace_status' => $selectedWorkspace['status'] ?? 'not_evaluated',
                'selected_workspace_dispatch_readiness_status' => data_get($selectedWorkspace, 'dispatch_readiness.status', 'not_evaluated'),
                'runtime_projection_allowed' => (bool) data_get($selectedWorkspace, 'dispatch_readiness.runtime_projection_allowed', false),
                'frontend_app_candidate_status' => data_get($selectedWorkspace, 'frontend_app_candidates.status', 'not_evaluated'),
                'frontend_app_candidate_count' => (int) data_get($selectedWorkspace, 'frontend_app_candidates.candidate_count', 0),
                'frontend_app_primary_candidate_ref' => data_get($selectedWorkspace, 'frontend_app_candidates.primary_candidate_ref'),
                'frontend_app_candidate_confirmation_required' => (bool) data_get($selectedWorkspace, 'frontend_app_candidates.operator_decision.required', false),
                'frontend_app_scope_status' => data_get($executionRunbook, 'frontend_app_scope.status', 'not_evaluated'),
                'frontend_app_scope_hash' => data_get($executionRunbook, 'frontend_app_scope.relative_name_hash'),
                'onboarding_frontend_app_scope_status' => data_get($companyRepoOnboarding, 'frontend_app_scope.status', 'not_evaluated'),
                'onboarding_frontend_app_scope_hash' => data_get($companyRepoOnboarding, 'frontend_app_scope.relative_name_hash'),
                'operator_start_panel_status' => data_get($selectedWorkspace, 'operator_start_panel.status', 'not_evaluated'),
                'operator_primary_action' => data_get($selectedWorkspace, 'operator_start_panel.primary_action'),
                'next_best_action' => data_get($selectedWorkspace, 'next_best_action.id'),
                'next_best_action_priority' => data_get($selectedWorkspace, 'next_best_action.priority'),
                'task_binding_status' => data_get($selectedWorkspace, 'task_binding.status', 'not_evaluated'),
                'task_bound' => (bool) data_get($selectedWorkspace, 'task_binding.task_present', false),
                'onboarding_status' => $companyRepoOnboarding['status'] ?? 'not_evaluated',
                'bootstrap_status' => $enterpriseBootstrap['status'] ?? 'not_evaluated',
                'runbook_status' => $executionRunbook['status'] ?? 'not_evaluated',
                'provider_packet_status' => $providerInstructionPacket['status'] ?? 'not_evaluated',
                'selected_workspace_provider_dispatch_allowed' => (bool) data_get($selectedWorkspace, 'dispatch_readiness.provider_dispatch_allowed', false),
                'provider_dispatch_allowed' => (bool) data_get($enterpriseBootstrap, 'readiness.provider_dispatch_allowed') && ($companyRepoOnboarding['status'] ?? null) === 'ready_for_operator_execution' && ($executionRunbook['status'] ?? null) === 'ready' && ($providerInstructionPacket['status'] ?? null) === 'ready',
                'premium_frontend_claim_allowed' => (bool) data_get($enterpriseBootstrap, 'readiness.premium_frontend_claim_allowed') && ($executionRunbook['status'] ?? null) === 'ready',
                'world_best_claim_allowed' => false,
                'claim_policy' => [
                    'local_company_repos_use_enterprise_bootstrap_and_runbook' => true,
                    'runbook_is_not_execution_evidence' => true,
                    'completion_requires_run_certification_handoff_and_outcome' => true,
                    'raw_customer_source_returned' => false,
                ],
                'hash_refs' => [
                    'enterprise_bootstrap_hash' => $enterpriseBootstrap['enterprise_bootstrap_hash'] ?? null,
                    'company_repo_onboarding_hash' => $companyRepoOnboarding['onboarding_hash'] ?? null,
                    'runbook_hash' => $executionRunbook['runbook_hash'] ?? null,
                    'provider_instruction_packet_hash' => $providerInstructionPacket['provider_instruction_packet_hash'] ?? null,
                ],
            ],
            'selected_workspace' => $selectedWorkspace,
            'company_repo_onboarding' => $companyRepoOnboarding,
            'enterprise_bootstrap' => $enterpriseBootstrap,
            'execution_runbook' => $executionRunbook,
            'provider_instruction_packet' => $providerInstructionPacket,
        ];
    }

    /**
     * @param  array<string,mixed>  $programmingMessagePlan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function programmingOrchestrationContract(array $programmingMessagePlan, array $options = []): array
    {
        $planId = (string) data_get($programmingMessagePlan, 'plan_id', '');
        $parentPlanId = data_get($programmingMessagePlan, 'parent_plan_id');
        $flow = (string) data_get($programmingMessagePlan, 'programming_flow', 'dev');
        $profile = (string) data_get($programmingMessagePlan, 'programming_profile', 'dev');
        $executor = (string) data_get($programmingMessagePlan, 'executor_decision.executor', 'simple_provider_execution');
        $toolMode = (string) data_get($programmingMessagePlan, 'policy_contracts.tools.mode', 'workspace_write');
        $readOnly = $toolMode === 'read_only' || $flow === 'review';
        $autoTest = (bool) data_get($programmingMessagePlan, 'execution_profile.auto_test', false);
        $repairEnabled = $executor === 'dev_repair_executor' || $flow === 'repair';
        $resumed = $parentPlanId !== null || (is_string($options['resume'] ?? null) && trim((string) $options['resume']) !== '');

        $stages = [
            $this->orchestrationStage($planId, 'plan', 'required', [
                'description' => 'Normalize objective, load Open Brain context and issue a single canonical plan.',
                'write_allowed' => false,
                'provider_allowed' => false,
                'required_evidence' => ['context_pack_hash', 'operational_decision_id'],
            ]),
            $this->orchestrationStage($planId, 'review', $readOnly || in_array($flow, ['review', 'refactor', 'forge'], true) ? 'required' : 'optional', [
                'description' => 'Inspect code, docs, prior decisions and risk before patching.',
                'write_allowed' => false,
                'provider_allowed' => true,
                'required_evidence' => ['review_summary', 'risk_notes'],
            ]),
            $this->orchestrationStage($planId, 'patch', $readOnly ? 'blocked' : 'required', [
                'description' => 'Apply the smallest scoped code change through the selected executor.',
                'write_allowed' => ! $readOnly,
                'provider_allowed' => true,
                'required_evidence' => ['diff_stat', 'changed_files'],
            ]),
            $this->orchestrationStage($planId, 'test', $autoTest ? 'required' : 'operator_or_policy', [
                'description' => 'Run the declared test, lint, quality or harness actions and attach evidence.',
                'write_allowed' => false,
                'provider_allowed' => false,
                'required_evidence' => ['tool_run_receipts', 'gate_result'],
            ]),
            $this->orchestrationStage($planId, 'repair', $repairEnabled ? 'conditional' : 'blocked', [
                'description' => 'Resume from failed gates only after Kernel repair decision and evidence.',
                'write_allowed' => $repairEnabled && ! $readOnly,
                'provider_allowed' => $repairEnabled,
                'required_evidence' => ['failure_packet', 'kernel_repair_decision'],
            ]),
        ];

        return [
            'schema_version' => 'atlas.programming.orchestration.v1',
            'source' => 'AtlasProgrammingOrchestrator',
            'plan_id' => $planId,
            'parent_plan_id' => is_string($parentPlanId) && trim($parentPlanId) !== '' ? trim($parentPlanId) : null,
            'resumed' => $resumed,
            'programming_profile' => $profile,
            'programming_flow' => str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow,
            'executor' => $executor,
            'canonical_surface' => 'atlas_cli_dev',
            'surface_rule' => 'all_cli_app_chat_programming_surfaces_must_follow_this_contract',
            'stage_order' => array_column($stages, 'stage'),
            'stages' => $stages,
            'receipt_contract' => [
                'schema_version' => 'atlas.programming.stage_receipt.v1',
                'required_fields' => [
                    'receipt_id',
                    'plan_id',
                    'stage',
                    'status',
                    'evidence_refs',
                    'input_hash',
                    'output_hash',
                    'created_at',
                ],
                'hash_algorithm' => 'sha256',
                'append_only' => true,
            ],
            'resume_contract' => [
                'can_resume' => true,
                'resume_key' => 'plan_id',
                'parent_plan_id_required_when_resuming' => true,
                'must_load_open_brain' => true,
                'must_preserve_prior_decisions' => true,
                'must_attach_previous_stage_receipts' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $programmingMessagePlan
     * @return array<string,mixed>
     */
    public function repairExecutionContract(array $programmingMessagePlan): array
    {
        $executionProfile = (array) data_get($programmingMessagePlan, 'execution_profile', []);
        $policy = (array) data_get($programmingMessagePlan, 'policy_profile.execution_policy', []);
        $contracts = (array) (data_get($programmingMessagePlan, 'policy_contracts')
            ?: data_get($programmingMessagePlan, 'policy_profile.policy_contracts')
            ?: data_get($programmingMessagePlan, 'policy_profile.effective_policy.operational_contracts')
            ?: []);
        $maxIterations = ProgrammingIterationPolicy::forRepairPolicy(
            data_get($policy, 'max_iterations', $executionProfile['max_iterations'] ?? 1),
            (int) ($executionProfile['max_iterations'] ?? 1),
        );
        $complete = (bool) ($executionProfile['complete'] ?? false);
        $executor = (string) data_get($programmingMessagePlan, 'executor_decision.executor', 'simple_provider_execution');

        return [
            'schema_version' => 1,
            'status' => $executor === 'dev_repair_executor' ? 'active' : 'inactive',
            'source' => 'AtlasProgrammingOrchestrator',
            'executor' => $executor,
            'enabled' => $executor === 'dev_repair_executor',
            'complete_mode' => $complete,
            'max_iterations' => $maxIterations,
            'auto_test' => (bool) data_get($policy, 'auto_test', $executionProfile['auto_test'] ?? false),
            'quality_required' => (bool) data_get($policy, 'quality_required', $complete),
            'required_final_status' => $complete ? 'passed' : 'not_failed',
            'repair_when_status' => ['failed', 'needs_review'],
            'stop_when_status' => $complete ? ['passed'] : ['passed', 'needs_review'],
            'stop_when_quality_worsens' => true,
            'kernel_repair_contract' => [
                'schema_version' => 1,
                'orchestrator' => 'AtlasRepairOrchestrator',
                'request_factory' => 'RepairRequestFactory',
                'failure_domain' => 'gate.failed',
                'decision_required_before_enqueue' => true,
                'records_kernel_decision' => true,
                'blocks_when_kernel_blocks' => true,
            ],
            'allowed_strategies' => RepairStrategy::values(),
            'heavy_strategies' => [
                RepairStrategy::RerunTool->value,
                RepairStrategy::RerunHarness->value,
            ],
            'requires_evidence_for_heavy_repair' => true,
            'repair_capsule_contract' => [
                'schema_version' => 'atlas.programming.repair_capsule.v1',
                'required_before_patch_repair' => true,
                'must_include_failure_taxonomy' => true,
                'must_include_provider_policy' => true,
                'must_preserve_original_provider_and_model' => true,
                'fallback_allowed' => false,
                'must_include_verification_plan' => true,
                'must_include_stop_rule' => true,
            ],
            'gate_contract' => data_get($contracts, 'gates'),
            'tool_contract' => data_get($contracts, 'tools'),
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $completion
     */
    public function repairPrompt(string $task, array $completion, int $iteration, int $maxIterations): string
    {
        return implode("\n\n", [
            "Corrija a tarefa anterior do Atlas. Iteracao de reparo {$iteration}/{$maxIterations}.",
            "Objetivo original: {$task}",
            'Quality gate atual:',
            json_encode([
                'status' => $completion['status'] ?? null,
                'changed_files' => $completion['changed_files'] ?? [],
                'tests' => data_get($completion, 'completion_packet.tests', []),
                'quality_gates' => data_get($completion, 'quality_gates', data_get($completion, 'completion_packet.quality_gates', [])),
                'risks' => data_get($completion, 'completion_packet.risks', []),
                'repair_capsule' => data_get($completion, 'repair_capsule'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'Aplique a menor correcao que faca os gates passarem. Preserve mudancas nao relacionadas e explique qualquer gate que ainda nao possa ser verificado.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policyProfile
     * @return array<string,mixed>
     */
    private function executorDecision(string $profile, array $decision, array $options, array $policyProfile): array
    {
        $risk = (string) data_get($decision, 'task_profile.risk_level', 'low');
        $complexity = (string) data_get($decision, 'task_profile.complexity', 'low');
        $forceHarness = (bool) ($options['force_harness'] ?? false);
        $policyExecutor = (string) data_get($policyProfile, 'execution_policy.executor_preference', 'simple_provider_execution');
        $executor = $policyExecutor !== '' ? $policyExecutor : 'simple_provider_execution';
        $reason = 'policy_execution_preference';

        if ($forceHarness) {
            $executor = 'engineering_harness';
            $reason = 'operator_forced_harness';
        } elseif ($profile === 'forge') {
            $reason = 'forge_policy_requires_harness';
        } elseif ($executor === 'dev_repair_executor') {
            $reason = 'policy_complete_mode_requires_repair_loop';
        } elseif ($executor === 'engineering_harness') {
            $reason = 'policy_requires_harness';
        } elseif ($complexity === 'high' && (bool) data_get($policyProfile, 'execution_policy.harness_required', false)) {
            $reason = 'policy_high_complexity_harness';
        } elseif (in_array($risk, ['high', 'critical'], true)) {
            $reason = 'programming_risk_managed_by_policy';
        }

        return [
            'executor' => $executor,
            'reason' => $reason,
            'harness_ready' => $executor === 'engineering_harness',
            'requires_task_id' => false,
            'can_create_task' => true,
            'policy_profile_id' => data_get($policyProfile, 'profile_id'),
            'policy_executor_preference' => $policyExecutor,
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function orchestrationStage(string $planId, string $stage, string $mode, array $attributes): array
    {
        return [
            'stage' => $stage,
            'mode' => $mode,
            'receipt_id' => $planId !== '' ? hash('sha256', $planId.'|'.$stage) : null,
            'receipt_schema' => 'atlas.programming.stage_receipt.v1',
            'status_values' => ['pending', 'running', 'passed', 'failed', 'blocked', 'skipped'],
            'description' => (string) ($attributes['description'] ?? ''),
            'write_allowed' => (bool) ($attributes['write_allowed'] ?? false),
            'provider_allowed' => (bool) ($attributes['provider_allowed'] ?? false),
            'required_evidence' => array_values((array) ($attributes['required_evidence'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function programmingFlow(string $profile, array $options): string
    {
        if ($profile === 'forge') {
            return 'forge';
        }

        $explicit = strtolower(trim((string) (
            $options['flow']
            ?? $options['routing_task']
            ?? $options['task_type']
            ?? ''
        )));
        $intent = strtolower(trim((string) ($options['intent'] ?? '')));

        $candidate = $explicit !== '' ? $explicit : $intent;
        $candidate = match ($candidate) {
            'fix', 'repair', 'quality_repair' => 'repair',
            'code_review', 'review' => 'review',
            'refactor', 'refactoring' => 'refactor',
            'test', 'tests', 'testing', 'quality', 'qa' => 'qa',
            'sec', 'security' => 'security',
            'db', 'database', 'postgres', 'migration', 'migrations' => 'database',
            'frontend' => 'frontend',
            'ui', 'visual', 'e2e' => 'visual',
            default => $candidate,
        };

        if (in_array($candidate, ['repair', 'review', 'refactor', 'qa', 'security', 'database', 'frontend', 'visual'], true)) {
            return $candidate;
        }

        $text = strtolower(Str::ascii((string) ($options['task'] ?? '')));

        return match (true) {
            $this->containsAny($text, ['corrija', 'corrigir', 'conserte', 'fix', 'repair', 'bug', 'erro', 'falha', 'quality gate']) => 'repair',
            $this->containsAny($text, ['review', 'revisao', 'revisão', 'code review', 'analise o codigo', 'analise o código']) => 'review',
            $this->containsAny($text, ['refactor', 'refator', 'refatore', 'refatoracao', 'refatoração']) => 'refactor',
            $this->containsAny($text, ['security', 'seguranca', 'segurança', 'vulnerab', 'threat']) => 'security',
            $this->containsAny($text, ['database', 'banco', 'postgres', 'migration', 'migracao', 'migração']) => 'database',
            $this->containsAny($text, ['frontend', 'component', 'react', 'expo', 'design system']) => 'frontend',
            $this->containsAny($text, ['visual', 'frontend', 'ui', 'tela', 'screenshot', 'e2e']) => 'visual',
            $this->containsAny($text, ['qa', 'testes', 'tests', 'regression', 'regressao', 'regressão']) => 'qa',
            default => 'dev',
        };
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $policyProfile
     * @return array<string,mixed>
     */
    private function policyContracts(array $policyProfile): array
    {
        $contracts = data_get($policyProfile, 'policy_contracts')
            ?: data_get($policyProfile, 'effective_policy.operational_contracts');

        return is_array($contracts) ? $contracts : [];
    }
}
