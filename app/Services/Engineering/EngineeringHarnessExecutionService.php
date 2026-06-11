<?php

namespace App\Services\Engineering;

use App\Models\AtlasTask;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\Programming\ProgrammingExecutionResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class EngineeringHarnessExecutionService
{
    public function __construct(
        private readonly EngineeringHarnessRunnerService $runner,
        private readonly AtlasRepairOrchestrator $repairOrchestrator,
        private readonly RepairRequestFactory $repairRequests,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    public function execute(ProgrammingExecutionRequest $request): ProgrammingExecutionResult
    {
        if (! DatabaseTableAvailability::has('atlas_tasks')) {
            $harnessOptions = $this->effectiveHarnessOptions($request);

            return ProgrammingExecutionResult::fromArray($this->withKernelRepairDecision($request, [
                'status' => 'blocked',
                'executor' => 'engineering_harness',
                'blocking_failures' => ['atlas_tasks_table_missing'],
                'summary' => 'Engineering Harness requer tabela atlas_tasks.',
            ], null, $harnessOptions));
        }

        $harnessOptions = $this->effectiveHarnessOptions($request);
        if ($block = $this->policyContractBlock($request, $harnessOptions)) {
            return ProgrammingExecutionResult::fromArray($this->withKernelRepairDecision($request, $block, null, $harnessOptions));
        }

        $task = $this->taskFor($request);
        $payload = $this->runner->run($task, $harnessOptions);
        $decision = (string) data_get($payload, 'run.decision', 'unresolved');

        return ProgrammingExecutionResult::fromArray($this->withKernelRepairDecision($request, [
            'status' => match ($decision) {
                'resolved' => 'passed',
                'partial' => 'partial',
                default => 'blocked',
            },
            'executor' => 'engineering_harness',
            'task_id' => $task->id,
            'created_task' => (bool) data_get($task->metadata, 'programming_orchestrator.created_for_harness', false),
            'harness_payload' => $payload,
            'policy_contracts' => $request->policyContracts(),
            'policy_contract_enforcement' => $this->policyContractEnforcement($request, $harnessOptions),
            'blocking_failures' => (array) data_get($payload, 'score.blocking_reasons', []),
            'evidence_refs' => array_values(array_filter([
                data_get($payload, 'run.id') ? 'engineering_run:'.data_get($payload, 'run.id') : null,
                data_get($payload, 'context_pack.hash') ? 'context_pack:'.data_get($payload, 'context_pack.hash') : null,
            ])),
        ], $task, $harnessOptions));
    }

    private function taskFor(ProgrammingExecutionRequest $request): AtlasTask
    {
        if ($request->taskId() !== null) {
            $task = AtlasTask::query()->with(['project', 'projectStep'])->find($request->taskId());
            if ($task instanceof AtlasTask) {
                return $task;
            }
        }

        return AtlasTask::query()->create([
            'title' => Str::limit($request->objective(), 180, ''),
            'description' => $request->objective(),
            'status' => 'open',
            'priority' => $request->profile() === 'forge' ? 'high' : 'normal',
            'domain' => 'atlas',
            'estimated_minutes' => $request->profile() === 'forge' ? 90 : 25,
            'metadata' => [
                'engineering_contract' => $this->contractFor($request),
                'programming_orchestrator' => [
                    'created_for_harness' => true,
                    'profile' => $request->profile(),
                    'workspace' => $request->workspace(),
                    'policy_contracts' => $request->policyContracts(),
                    'agent_behavior_contract' => $request->agentBehaviorContract(),
                    'created_at' => now()->toJSON(),
                ],
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function contractFor(ProgrammingExecutionRequest $request): array
    {
        $contract = $request->contract();

        return array_replace_recursive([
            'contract_version' => 1,
            'source' => 'programming_orchestrator',
            'type' => $request->profile() === 'forge' ? 'feature' : 'dev',
            'goal' => $request->objective(),
            'acceptance_criteria' => [
                'Executar o menor diff que satisfaz o pedido.',
                'Registrar evidencias, testes ou motivo verificavel para bloqueio.',
            ],
            'definition_of_done' => [
                'Diff revisado.',
                'Gates proporcionais ao risco avaliados.',
                'Completion packet ou harness payload disponivel.',
            ],
            'agent_behavior_contract' => $request->agentBehaviorContract(),
        ], $contract);
    }

    /**
     * @return array<string,mixed>
     */
    private function effectiveHarnessOptions(ProgrammingExecutionRequest $request): array
    {
        $options = $request->harnessOptions();
        $contracts = $request->policyContracts();
        $gate = (array) data_get($contracts, 'gates', []);
        $tool = (array) data_get($contracts, 'tools', []);

        if ($this->gateRequiresEvidence($gate)) {
            $options['auto_test'] = true;
            $options['complete'] = true;
            $options['quality_scan'] = $this->upgradeMode((string) ($options['quality_scan'] ?? 'off'), 'required');
            $options['harness_policy'] = $this->upgradeMode((string) ($options['harness_policy'] ?? 'auto'), 'strict');
        }

        if ($tool !== [] && ! $this->toolAllowsWorkspaceWrite($tool)) {
            $options['apply_isolated_patch'] = false;
            $options['permission'] = 'read';
        }

        return $options;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function policyContractBlock(ProgrammingExecutionRequest $request, array $options): ?array
    {
        $tool = (array) data_get($request->policyContracts(), 'tools', []);
        if ($tool === [] || $this->toolAllowsWorkspaceWrite($tool) || (bool) ($options['no_provider'] ?? false)) {
            return null;
        }

        return [
            'status' => 'blocked',
            'executor' => 'engineering_harness',
            'blocking_failures' => ['tool_contract_blocks_workspace_write'],
            'summary' => 'Engineering Harness bloqueado: contrato de ferramentas esta em modo somente leitura.',
            'policy_contracts' => $request->policyContracts(),
            'policy_contract_enforcement' => $this->policyContractEnforcement($request, $options, blockedReason: 'tool_contract_blocks_workspace_write'),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function policyContractEnforcement(ProgrammingExecutionRequest $request, array $options, ?string $blockedReason = null): array
    {
        $contracts = $request->policyContracts();
        $gate = (array) data_get($contracts, 'gates', []);
        $tool = (array) data_get($contracts, 'tools', []);

        return array_filter([
            'schema_version' => 1,
            'source' => 'effective_policy_v2_policy_contracts',
            'gate_contract_enforced' => $this->gateRequiresEvidence($gate),
            'tool_contract_enforced' => $tool !== [],
            'blocked_reason' => $blockedReason,
            'effective_options' => [
                'auto_test' => (bool) ($options['auto_test'] ?? false),
                'complete' => (bool) ($options['complete'] ?? false),
                'quality_scan' => $options['quality_scan'] ?? null,
                'harness_policy' => $options['harness_policy'] ?? null,
                'permission' => $options['permission'] ?? null,
                'apply_isolated_patch' => (bool) ($options['apply_isolated_patch'] ?? true),
                'no_provider' => (bool) ($options['no_provider'] ?? false),
                'agent_behavior_contract' => $options['agent_behavior_contract'] ?? null,
            ],
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function gateRequiresEvidence(array $gate): bool
    {
        $minimum = strtolower(trim((string) ($gate['minimum_gate'] ?? '')));

        return (bool) ($gate['evidence_required'] ?? false)
            || in_array($minimum, ['strict', 'release'], true);
    }

    /**
     * @param  array<string,mixed>  $tool
     */
    private function toolAllowsWorkspaceWrite(array $tool): bool
    {
        $mode = strtolower(trim((string) ($tool['mode'] ?? '')));
        if ($mode === 'read_only') {
            return false;
        }

        return (bool) ($tool['workspace_write'] ?? in_array($mode, ['workspace_write', 'harness'], true));
    }

    private function upgradeMode(string $current, string $required): string
    {
        $rank = ['off' => 0, 'auto' => 1, 'strict' => 2, 'required' => 3, 'release' => 4];
        $current = strtolower(trim($current));
        $required = strtolower(trim($required));

        return ($rank[$current] ?? 0) >= ($rank[$required] ?? 0) ? $current : $required;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $harnessOptions
     * @return array<string,mixed>
     */
    private function withKernelRepairDecision(ProgrammingExecutionRequest $request, array $result, ?AtlasTask $task, array $harnessOptions): array
    {
        if (($result['status'] ?? null) === 'passed') {
            return $result;
        }

        $decision = $this->kernelRepairDecision($request, $result, $task, $harnessOptions);
        $this->ledger->recordRepairDecision($decision, $this->kernelRepairLedgerContext($request, $result));

        $result['kernel_repair_decision'] = $decision->toArray();
        $result['repair_contract'] = [
            'schema_version' => 1,
            'orchestrator' => 'AtlasRepairOrchestrator',
            'request_factory' => 'RepairRequestFactory',
            'decision_status' => $decision->status->value,
            'strategy' => $decision->strategy,
            'decision_required_before_enqueue' => true,
            'blocks_when_kernel_blocks' => true,
            'execution_enabled' => false,
        ];

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $harnessOptions
     */
    private function kernelRepairDecision(ProgrammingExecutionRequest $request, array $result, ?AtlasTask $task, array $harnessOptions): RepairDecision
    {
        $evidenceRefs = $this->kernelRepairEvidenceRefs($result, $task);
        $failureDomain = $this->kernelRepairFailureDomain($result);
        $repairRequest = $this->repairRequests->fromKernelContext(
            envelopeId: $this->kernelRepairEnvelopeId($request, $result, $task),
            receiptId: $this->kernelRepairReceiptId($request),
            failure: new FailureClassification(
                domain: $failureDomain,
                source: 'engineering_harness',
                signals: $this->kernelRepairSignals($result),
                confidence: 0.95,
                metadata: [
                    'executor' => 'engineering_harness',
                    'status' => (string) ($result['status'] ?? 'unknown'),
                    'blocking_failures' => (array) ($result['blocking_failures'] ?? []),
                    'run_decision' => data_get($result, 'harness_payload.run.decision'),
                ],
            ),
            policy: $this->repairRequests->defaultPolicy(
                enabled: true,
                maxAttempts: max(1, (int) ($harnessOptions['max_attempts'] ?? 1)),
                allowedStrategies: RepairStrategy::values(),
                requiresEvidenceForHeavyRepair: true,
            ),
            currentAttempt: $this->kernelRepairCurrentAttempt($result),
            evidenceRefs: $evidenceRefs,
            dryRun: (bool) ($harnessOptions['dry_run'] ?? false),
            metadata: [
                'profile' => $request->profile(),
                'workspace' => $request->workspace(),
                'provider' => $request->provider(),
                'model' => $request->model(),
                'no_provider' => (bool) ($harnessOptions['no_provider'] ?? false),
                'policy_contracts' => $request->policyContracts(),
                'agent_behavior_contract' => $request->agentBehaviorContract(),
            ],
        );

        return $this->repairOrchestrator->plan($repairRequest);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function kernelRepairLedgerContext(ProgrammingExecutionRequest $request, array $result): array
    {
        $envelopeId = (string) data_get($result, 'kernel_repair_decision.evidence_payload.envelope_id', '');
        if ($envelopeId === '') {
            $runId = data_get($result, 'harness_payload.run.id');
            $envelopeId = is_string($runId) && trim($runId) !== ''
                ? 'engineering_run:'.trim($runId)
                : 'engineering_harness:preflight:'.substr(hash('sha256', $request->objective()), 0, 16);
        }

        return [
            'tenant_id' => data_get($request->toArray(), 'operator.tenant_id', 'default'),
            'operator_id' => data_get($request->toArray(), 'operator.operator_id', 'system'),
            'envelope_id' => $envelopeId,
            'receipt_id' => $this->kernelRepairReceiptId($request),
            'correlation_id' => data_get($result, 'harness_payload.run.id')
                ? 'engineering_run:'.data_get($result, 'harness_payload.run.id')
                : $envelopeId,
            'emitter_stage' => 'engineering_harness.repair',
            'emitter_version' => 'engineering_harness.repair.v1',
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function kernelRepairEnvelopeId(ProgrammingExecutionRequest $request, array $result, ?AtlasTask $task): string
    {
        $runId = data_get($result, 'harness_payload.run.id');
        if (is_string($runId) && trim($runId) !== '') {
            return 'engineering_run:'.trim($runId);
        }

        if ($task instanceof AtlasTask) {
            return 'atlas_task:'.$task->id;
        }

        return 'engineering_harness:preflight:'.substr(hash('sha256', $request->objective()), 0, 16);
    }

    private function kernelRepairReceiptId(ProgrammingExecutionRequest $request): ?string
    {
        foreach ([
            'decision_receipt.receipt_id',
            'programming_message_plan.operational_decision.receipt_id',
            'programming_message_plan.operational_decision.decision_receipt.receipt_id',
        ] as $path) {
            $receiptId = data_get($request->toArray(), $path);
            if (is_string($receiptId) && trim($receiptId) !== '') {
                return trim($receiptId);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function kernelRepairFailureDomain(array $result): FailureDomain
    {
        $blockingFailures = array_map('strval', (array) ($result['blocking_failures'] ?? []));

        if (in_array('atlas_tasks_table_missing', $blockingFailures, true)) {
            return FailureDomain::RuntimeUnsupported;
        }

        if (in_array('tool_contract_blocks_workspace_write', $blockingFailures, true)) {
            return FailureDomain::ToolPolicyDenied;
        }

        if (($result['status'] ?? null) === 'partial') {
            return FailureDomain::GateFailed;
        }

        if ($blockingFailures !== [] || (($result['status'] ?? null) === 'blocked')) {
            return FailureDomain::HarnessFailed;
        }

        return FailureDomain::Unknown;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<int,string>
     */
    private function kernelRepairSignals(array $result): array
    {
        $signals = EngineeringStringListNormalizer::uniqueTruthyStringValues((array) ($result['blocking_failures'] ?? []));

        $status = (string) ($result['status'] ?? '');
        if ($status !== '') {
            array_unshift($signals, 'status:'.$status);
        }

        $runDecision = data_get($result, 'harness_payload.run.decision');
        if (is_string($runDecision) && trim($runDecision) !== '') {
            $signals[] = 'run_decision:'.trim($runDecision);
        }

        return EngineeringStringListNormalizer::uniqueTruthyStringValues($signals);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<int,string>
     */
    private function kernelRepairEvidenceRefs(array $result, ?AtlasTask $task): array
    {
        $refs = (array) ($result['evidence_refs'] ?? []);

        $runId = data_get($result, 'harness_payload.run.id');
        if (is_string($runId) && trim($runId) !== '') {
            $refs[] = 'engineering_run:'.trim($runId);
        }

        $contextHash = data_get($result, 'harness_payload.context_pack.hash');
        if (is_string($contextHash) && trim($contextHash) !== '') {
            $refs[] = 'context_pack:'.trim($contextHash);
        }

        if ($task instanceof AtlasTask) {
            $refs[] = 'atlas_task:'.$task->id;
        }

        return EngineeringStringListNormalizer::uniqueTruthyStringValues($refs);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function kernelRepairCurrentAttempt(array $result): int
    {
        $attempt = (int) data_get($result, 'harness_payload.run.attempt_count', 1);

        return max(0, $attempt - 1);
    }
}
