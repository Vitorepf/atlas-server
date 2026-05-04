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

        $task = $this->taskFor($request);
        $payload = $this->runner->run($task, $request->harnessOptions());
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
}
