<?php

namespace App\Services\Engineering;

use App\Models\AtlasTask;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\Programming\ProgrammingExecutionResult;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EngineeringHarnessExecutionService
{
    public function __construct(
        private readonly EngineeringHarnessRunnerService $runner,
    ) {}

    public function execute(ProgrammingExecutionRequest $request): ProgrammingExecutionResult
    {
        if (! Schema::hasTable('atlas_tasks')) {
            return ProgrammingExecutionResult::fromArray([
                'status' => 'blocked',
                'executor' => 'engineering_harness',
                'blocking_failures' => ['atlas_tasks_table_missing'],
                'summary' => 'Engineering Harness requer tabela atlas_tasks.',
            ]);
        }

        $harnessOptions = $this->effectiveHarnessOptions($request);
        if ($block = $this->policyContractBlock($request, $harnessOptions)) {
            return ProgrammingExecutionResult::fromArray($block);
        }

        $task = $this->taskFor($request);
        $payload = $this->runner->run($task, $harnessOptions);
        $decision = (string) data_get($payload, 'run.decision', 'unresolved');

        return ProgrammingExecutionResult::fromArray([
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
        ]);
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
}
