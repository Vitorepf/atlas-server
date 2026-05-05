<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\AtlasAiPolicyService;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Engineering\EngineeringHarnessExecutionService;
use Illuminate\Support\Str;

class AtlasProgrammingOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly AtlasAiPolicyService $policies,
        private readonly AtlasDecideService $decide,
        private readonly EngineeringHarnessExecutionService $harness,
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
        $maxIterations = max($profile === 'forge' ? 5 : 3, (int) ($options['max_iterations'] ?? 0));
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
        $policyMaxIterations = max(1, min(10, (int) data_get($executionPolicy, 'max_iterations', $maxIterations)));
        $policyAutoTest = (bool) data_get($executionPolicy, 'auto_test', $profile === 'forge' || (bool) ($options['auto_test'] ?? false));

        return [
            'schema_version' => 1,
            'plan_id' => (string) Str::orderedUuid(),
            'parent_plan_id' => is_string($options['parent_plan_id'] ?? null) && trim((string) $options['parent_plan_id']) !== ''
                ? trim((string) $options['parent_plan_id'])
                : null,
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
            'operational_decision' => $decision->toArray(),
            'created_at' => now()->toJSON(),
        ];
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
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => 'planned',
            'repair_prompt' => $this->repairPrompt($failure),
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
            'operational_decision_id' => data_get($programmingMessagePlan, 'operational_decision.decision_id'),
            'plan_id' => data_get($programmingMessagePlan, 'plan_id'),
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $dispatch
     * @return array<string,mixed>
     */
    public function harnessCompletionContract(array $result, array $dispatch, ?string $model = null): array
    {
        $status = (string) ($result['status'] ?? 'unknown');

        return array_filter([
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
            'policy_contracts' => data_get($dispatch, 'policy_contracts'),
            'completed_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
        $maxIterations = max(1, min(10, (int) data_get($policy, 'max_iterations', $executionProfile['max_iterations'] ?? 1)));
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
            'ui', 'frontend', 'visual', 'e2e' => 'visual',
            default => $candidate,
        };

        if (in_array($candidate, ['repair', 'review', 'refactor', 'qa', 'security', 'database', 'visual'], true)) {
            return $candidate;
        }

        $text = strtolower(Str::ascii((string) ($options['task'] ?? '')));

        return match (true) {
            $this->containsAny($text, ['corrija', 'corrigir', 'conserte', 'fix', 'repair', 'bug', 'erro', 'falha', 'quality gate']) => 'repair',
            $this->containsAny($text, ['review', 'revisao', 'revisão', 'code review', 'analise o codigo', 'analise o código']) => 'review',
            $this->containsAny($text, ['refactor', 'refator', 'refatore', 'refatoracao', 'refatoração']) => 'refactor',
            $this->containsAny($text, ['security', 'seguranca', 'segurança', 'vulnerab', 'threat']) => 'security',
            $this->containsAny($text, ['database', 'banco', 'postgres', 'migration', 'migracao', 'migração']) => 'database',
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
