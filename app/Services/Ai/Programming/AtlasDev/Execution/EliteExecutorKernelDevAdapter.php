<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\MutativeDecisionBinder;
use Symfony\Component\Process\Process;

final readonly class EliteExecutorKernelDevAdapter implements DevKernelExecutionPort
{
    public function __construct(
        private EliteExecutorKernel $kernel,
        private ?EngineeringModeExecutionOrderFactory $orders = null,
        private ?MutativeDecisionBinder $decisionBinder = null,
    ) {}

    public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
    {
        $factory = $this->orders ?? new EngineeringModeExecutionOrderFactory;
        $order = $factory->make($this->orderData($run, $plan));
        $order = ($this->decisionBinder ?? app(MutativeDecisionBinder::class))->bind(
            $order,
            'dev',
            MutativeDecisionBinder::decisionEventId('dev:'.$run->runHash),
            $run->operatorId !== '' ? $run->operatorId : 'atlas-dev-operator',
            ['dev_run_hash' => $run->runHash],
        );

        return $this->kernel->execute($order, ['task_goal' => $run->intent->rawGoal]);
    }

    /** @return array<string,mixed> */
    private function orderData(ConfirmedDevRun $run, DevPlan $plan): array
    {
        $intent = $run->intent;
        $runId = $plan->result->envelope->runId;
        $deliveryId = 'dev-'.$run->runHash;
        $authority = ['kind' => 'atlas_dev_confirmed_run', 'operator_id' => $run->operatorId, 'authority_hash' => $run->authorityHash];
        // Workspace estrangeiro (worktree descartável, ex. braço atlas_dev do
        // Rivals): não existe lease de task-lane porque não há main compartilhada
        // em disputa. O lease dev-scoped abaixo só é aceito pelo actuator
        // redirecionado para o próprio workspace (nunca pela main viva).
        if (realpath($intent->workspace) !== realpath(base_path())) {
            $authority += [
                'lease_id' => 'dev-'.$run->runHash,
                'lease_owner' => 'operator:'.$run->operatorId,
                'fencing_token' => 1,
            ];
        }
        $decisionEventId = MutativeDecisionBinder::decisionEventId('dev:'.$run->runHash);
        $order = [
            'run_hash' => $run->runHash,
            'run_id' => $runId,
            'delivery_id' => $deliveryId,
            'mode' => 'dev',
            'risk_class' => $intent->riskClass,
            'complexity_band' => 'C1',
            'duration_regime' => $intent->durationRegime,
            'work_topology' => $intent->topology,
            'product_intent_verdict_hash' => $intent->productIntentHash,
            'spec_hash' => $intent->specHash,
            'world_model_snapshot_hash' => $intent->worldModelSnapshotHash,
            'workspace' => $intent->workspace,
            'base_commit' => $this->baseCommit($intent->workspace),
            'allowed_scope' => $this->allowedScope($plan, $intent),
            'forbidden_scope' => $plan->result->miniSpec->forbiddenFiles,
            'authority_envelope' => $authority,
            // Placeholder id; MutativeDecisionBinder mints/rebinds sealed ledger event.
            'decision_receipt' => ['decision_event_id' => $decisionEventId],
            'decision_event_id' => $decisionEventId,
            'operator_contract' => ['presence' => 'confirmed', 'operator_id' => $run->operatorId],
            'provider_route' => [
                'provider' => $plan->result->taskContract->providerLock->provider,
                'model' => $plan->result->taskContract->providerLock->modelFamily,
                'response_contract' => $plan->result->taskContract->providerLock->responseContractFor(
                    $plan->result->compactSdd->taskKind,
                    $intent->rawGoal,
                ),
            ],
            'mutate' => $intent->mutate,
            'experiment_ref' => 'atlas-dev/'.$run->runHash,
            'idempotency_key' => 'atlas-dev:'.$run->runHash,
            'budget_posture' => 'unbounded_quality_first',
        ];
        if ($intent->marketDecisionHash !== null) {
            $order['market_decision_hash'] = $intent->marketDecisionHash;
        }

        return $order;
    }

    /**
     * Escopo do order: miniSpec quando existe; senão arquivos citados no goal
     * que existem no workspace. README.md é só o último recurso — um order com
     * escopo errado faz o provider "resolver" o arquivo errado.
     *
     * @return list<string>
     */
    private function allowedScope(DevPlan $plan, DevIntent $intent): array
    {
        if ($plan->result->miniSpec->allowedFiles !== []) {
            return $plan->result->miniSpec->allowedFiles;
        }
        $files = [];
        preg_match_all('/[A-Za-z0-9_.\/-]+\.[A-Za-z0-9]{1,8}/', $intent->rawGoal, $mentioned);
        foreach (array_unique($mentioned[0] ?? []) as $token) {
            $relative = ltrim($token, './');
            if (! str_contains($relative, '..')
                && is_file(rtrim($intent->workspace, '/').'/'.$relative)) {
                $files[] = $relative;
            }
        }

        if ($files === []) {
            // CRIAÇÃO: a task NOMEIA um alvo que ainda NÃO existe ("write the
            // solution into the file solution.py") — o filtro is_file acima o
            // descartava e o escopo caía em README.md: o provider "resolvia" o
            // arquivo errado com a solução certa e o corretor via vazio
            // (provado ao vivo 20/07, LCB 1873_*). Nome explícito com a palavra
            // file/arquivo é sinal forte o bastante para criar.
            preg_match_all('/\b(?:file|arquivo|ficheiro)\s+`?([A-Za-z0-9][A-Za-z0-9_.\/-]*\.[A-Za-z0-9]{1,8})`?/i', $intent->rawGoal, $named);
            foreach (array_unique($named[1] ?? []) as $token) {
                $relative = ltrim($token, './');
                if ($relative !== '' && ! str_contains($relative, '..')) {
                    $files[] = $relative;
                }
            }
        }

        return $files !== [] ? array_values(array_unique($files)) : ['README.md'];
    }

    private function baseCommit(string $workspace): string
    {
        $process = new Process(['git', '-C', $workspace, 'rev-parse', 'HEAD']);
        $process->run();
        $commit = trim($process->getOutput());
        if (! $process->isSuccessful() || preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new \RuntimeException('dev_kernel_base_commit_unavailable');
        }

        return $commit;
    }
}
