<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\MutativeDecisionBinder;
use App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\Execution\DevPlan;
use App\Services\Ai\Programming\AtlasDev\Execution\DevRunResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Compatibility translator for the v1 HTTP/worker contract.
 *
 * It owns no provider, workspace or release behavior. It derives the typed
 * Dev intent from persisted v1 artifacts, delegates to AtlasDevExecutionService
 * and projects the v2 outcome back into RunExecutionResult.
 */
final class KernelRunExecutor implements RunExecutor
{
    public function __construct(
        private readonly AtlasDevFastPathOrchestrator $orchestrator,
        private readonly AtlasDevExecutionService $execution,
    ) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        $plan = $this->orchestrator->planOnly(
            'atlas_dev_execution',
            $envelope->workspace,
            $envelope->normalizedIntent !== '' ? $envelope->normalizedIntent : $envelope->rawIntent,
            $envelope->userConstraints,
        );
        $intent = DevIntent::fromArray([
            'raw_goal' => $envelope->normalizedIntent !== '' ? $envelope->normalizedIntent : $envelope->rawIntent,
            'workspace' => $envelope->workspace,
            'operator_id' => $envelope->surfaceContext->productSurface,
            'product_intent_hash' => hash('sha256', $envelope->normalizedIntent.'|'.$envelope->rawIntent),
            'spec_hash' => $taskContract->specHash,
            'world_model_snapshot_hash' => $envelope->workspaceHash,
            'authority_hash' => $this->authorityHash($envelope, $taskContract, $runId),
            'risk_class' => $this->riskClass($plan),
            'duration_regime' => $this->duration($plan),
            'topology' => $this->topology($plan),
            'mutate' => $taskContract->allowsWrite() && $envelope->preflight->writeAllowed,
            'constraints' => $envelope->userConstraints,
        ]);
        $run = ConfirmedDevRun::fromIntent($intent, $intent->operatorId, $intent->authorityHash);
        // Preserve the plan that produced the typed intent. The facade must
        // not discover or route a second time after the v1 envelope has been
        // translated and bound to its authority/spec/world hashes.
        $result = $this->execution->run($run, DevPlan::fromResult($intent, $plan));

        return $this->toLegacyResult($result, $taskContract, $runId, $run);
    }

    /** @return array<string,mixed> */
    public function commissioningContract(DevIntent $intent, ConfirmedDevRun $run): array
    {
        return [
            'owner' => self::class,
            'invoked' => true,
            'status' => 'prepared',
            'downstream' => $this->execution->commissioningContract($intent, $run),
            'execution_requested' => false,
            'mutation_authorized' => false,
        ];
    }

    private function authorityHash(OperationEnvelope $envelope, LightTaskContract $contract, string $runId): string
    {
        return CanonicalKernelPayload::hash([
            'run_id' => $runId,
            'envelope_hash' => $envelope->envelopeHash,
            'task_contract_hash' => $contract->taskContractHash,
            'preflight_hash' => $envelope->preflight->hash(),
        ]);
    }

    private function riskClass(PlanOnlyResult $plan): string
    {
        return in_array($plan->riskLevel, ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'], true) ? $plan->riskLevel : 'R3';
    }

    private function duration(PlanOnlyResult $plan): string
    {
        return $plan->isForgePreview() ? 'durable_task' : 'interactive';
    }

    private function topology(PlanOnlyResult $plan): string
    {
        return $plan->isForgePreview() ? 'DAG' : 'single';
    }

    private function toLegacyResult(
        DevRunResult $result,
        LightTaskContract $contract,
        string $runId,
        ConfirmedDevRun $confirmed,
    ): RunExecutionResult {
        $outcome = is_array($result->details['kernel_outcome'] ?? null) ? $result->details['kernel_outcome'] : [];
        $status = (string) ($outcome['status'] ?? $result->status);
        $completion = match ($status) {
            'released', 'completed_read_only' => 'passed',
            'held', 'forge_handoff_required' => 'needs_review',
            default => 'blocked',
        };
        $hashes = is_array($outcome['correlated_hashes'] ?? null) ? $outcome['correlated_hashes'] : [];
        $provider = is_array($outcome['provider_receipt'] ?? null) ? $outcome['provider_receipt'] : [];
        $verification = is_array($outcome['evidence_bundle'] ?? null) ? $outcome['evidence_bundle'] : [];

        // Same decision_event_id seed EliteExecutorKernelDevAdapter seals via MutativeDecisionBinder.
        // Project the derived lineage into the producer payload so AAEOS P4 can derive
        // authority_lineage_proof without inventing free caller bools.
        $authorityHash = strtolower(trim($confirmed->authorityHash));
        $authorityLineage = null;
        if (preg_match('/^[a-f0-9]{64}$/', $authorityHash) === 1) {
            $authorityLineage = [
                'authority_ref' => MutativeDecisionBinder::decisionEventId('dev:'.$confirmed->runHash),
                'authority_hash' => $authorityHash,
                'authority_revision' => 1,
                'source' => 'confirmed_dev_run',
            ];
        }

        return new RunExecutionResult(
            completionState: $completion,
            scopeGuardStatus: $completion === 'passed' ? 'passed' : 'blocked',
            verificationStatus: $completion === 'passed' ? 'passed' : 'blocked',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => (string) ($provider['provider'] ?? $contract->providerLock->provider),
                'model_family' => (string) ($provider['model'] ?? $contract->providerLock->modelFamily),
                'provider_calls' => ($provider === [] ? 0 : 1),
                'exit_code' => $completion === 'passed' ? 0 : 1,
                'duration_ms' => (int) ($outcome['elapsed_ms'] ?? 0),
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => array_values(array_map('strval', (array) ($result->details['kernel_outcome']['uncertainties'] ?? []))),
                // O PATCH que o Atlas gerou, exposto SÓ no contexto Rivals. É a
                // resposta do modelo (patch_plan: path+mode+contents), retida no
                // provider_receipt, que existe mesmo quando o merge governado
                // bloqueia (governor_authority_absent) — o sandbox é efêmero, mas
                // isto não. Serve para o harness medir o Atlas pelo PATCH gerado
                // (o grader o aplica e julga); o merge de produção não cabe num
                // benchmark descartável e não é o que se mede. Aditivo e
                // observável: não muda decisão, gate, nem o que é commitado.
                ...(filter_var(getenv('ATLAS_RIVALS_RUNTIME_EXECUTION') ?: false, FILTER_VALIDATE_BOOLEAN)
                    && is_array($provider['patch_plan'] ?? null)
                    ? ['patch_plan' => $provider['patch_plan']]
                    : []),
            ],
            verificationReceiptHash: self::hashOrNull($verification['hash'] ?? null),
            scopeGuardReceiptHash: self::hashOrNull($hashes['release'] ?? null),
            diffHash: self::hashOrNull($hashes['diff'] ?? null),
            reasons: $result->reason === null ? null : array_values(array_filter([
                $result->reason,
                // Sem isto o motivo real de um bloqueio de kernel morre dentro
                // de details e o operador só vê "shared_kernel_execution_failed".
                is_string($result->details['exception'] ?? null) ? 'exception='.$result->details['exception'] : null,
                is_string($result->details['reason'] ?? null) ? 'detail='.mb_substr($result->details['reason'], 0, 400) : null,
            ])),
            authorityLineage: $authorityLineage,
        );
    }

    private static function hashOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
}
