<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class ForgeObraRuntime
{
    public function __construct(
        private readonly ForgeIntakeService $intakes,
        private readonly ForgeLongHorizonStateService $states,
        private readonly ForgeWorkPacketExecutionCycleService $cycles,
        private readonly ?EliteExecutorKernel $kernel = null,
        private readonly ?EngineeringModeExecutionOrderFactory $orders = null,
        private readonly ?AwisExecutionGatePort $workspaceExecutionGate = null,
    ) {}

    public function commission(ForgeCommissioning $commissioning): ForgeObraSnapshot
    {
        $workspaceGate = ($this->workspaceExecutionGate ?? app(AwisExecutionGatePort::class))->gate(
            workspace: $commissioning->workspace,
            mode: 'forge',
            task: $commissioning->prompt,
        );
        if (! (bool) ($workspaceGate['allowed'] ?? false)) {
            throw new InvalidArgumentException('forge_workspace_execution_blocked');
        }

        return DB::transaction(function () use ($commissioning, $workspaceGate): ForgeObraSnapshot {
            $existingIntake = AiForgeIntake::query()
                ->where(function ($query) use ($commissioning): void {
                    $query->where('rich_input_payload->commissioning_hash', $commissioning->commissioningHash);
                    if (DatabaseTableAvailability::hasColumn('ai_forge_intakes', 'commissioning_hash')) {
                        $query->orWhere('commissioning_hash', $commissioning->commissioningHash);
                    }
                })
                ->lockForUpdate()
                ->first();
            if ($existingIntake instanceof AiForgeIntake) {
                $existingState = AiForgeLongHorizonState::query()
                    ->where('intake_id', $existingIntake->id)
                    ->first();
                if (! $existingState instanceof AiForgeLongHorizonState) {
                    throw new InvalidArgumentException('forge_commissioning_state_missing');
                }

                return ForgeObraSnapshot::fromState(
                    $existingState,
                    $commissioning->commissioningHash,
                    $commissioning->productIntentHash,
                    $commissioning->specHash,
                    $commissioning->worldModelSnapshotHash,
                    $commissioning->marketDecisionHash,
                );
            }

            $intake = $this->intakes->intakeFromPrompt($commissioning->prompt, [
                'workspace_slug' => basename(rtrim($commissioning->workspace, '/')), 'risk_band' => self::riskBand($commissioning->riskClass),
                'recommended_forge_mode' => 'obra_intake', 'actor_type' => 'forge_commissioning',
                'workspace_execution_gate' => $workspaceGate,
                'commissioning_hash' => $commissioning->commissioningHash,
                'authority_hash' => $commissioning->authorityHash, 'product_intent_hash' => $commissioning->productIntentHash,
                'spec_hash' => $commissioning->specHash, 'world_model_snapshot_hash' => $commissioning->worldModelSnapshotHash,
                'market_decision_hash' => $commissioning->marketDecisionHash,
                'rich_input_payload' => [
                    'schema_version' => 'atlas.quality_foundry.mode_binding.v1',
                    'workspace' => $commissioning->workspace,
                    'authority_hash' => $commissioning->authorityHash,
                    'commissioning_hash' => $commissioning->commissioningHash,
                    'risk_class' => $commissioning->riskClass,
                    'product_intent_hash' => $commissioning->productIntentHash,
                    'spec_hash' => $commissioning->specHash,
                    'world_model_snapshot_hash' => $commissioning->worldModelSnapshotHash,
                    'market_decision_hash' => $commissioning->marketDecisionHash,
                ],
                'release_policy' => $commissioning->releasePolicy, 'interruption_policy' => $commissioning->interruptionPolicy,
            ]);
            $state = $this->states->initializeForIntake($intake);

            return ForgeObraSnapshot::fromState($state, $commissioning->commissioningHash, $commissioning->productIntentHash,
                $commissioning->specHash, $commissioning->worldModelSnapshotHash, $commissioning->marketDecisionHash);
        });
    }

    private static function riskBand(string $riskClass): string
    {
        return match ($riskClass) {
            'R0', 'R1' => 'low',
            'R2', 'R3' => 'medium',
            'R4' => 'high',
            'R5' => 'critical',
            default => throw new InvalidArgumentException('forge_risk_class_invalid'),
        };
    }

    public function snapshot(ForgeObraId $obra): ForgeObraSnapshot
    {
        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        if (! $state instanceof AiForgeLongHorizonState) {
            throw new InvalidArgumentException('forge_obra_not_found');
        }

        $intake = AiForgeIntake::query()->find($obra->value);
        $binding = is_array($intake?->rich_input_payload) ? $intake->rich_input_payload : [];

        return ForgeObraSnapshot::fromState($state, (string) data_get($state->toArray(), 'commissioning_hash', ''),
            data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
    }

    public function tick(ForgeObraId $obra, ForgeTickBudget $budget): ForgeTickResult
    {
        $intake = AiForgeIntake::query()->find($obra->value);
        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        if (! $intake instanceof AiForgeIntake || ! $state instanceof AiForgeLongHorizonState) {
            throw new InvalidArgumentException('forge_obra_not_found');
        }
        $packet = $this->cycles->selectPacket($intake, $state);
        $binding = is_array($intake->rich_input_payload) ? $intake->rich_input_payload : [];
        $snapshot = ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
        if ($packet === null) {
            return ForgeTickResult::idle($snapshot, 'no_eligible_packet');
        }
        $built = $this->cycles->planExecution($packet, [
            'execution_mode' => $budget->allowProvider ? 'real' : 'safe_simulation', 'lease_seconds' => $budget->leaseSeconds,
            'lease_owner' => 'forge-obra-runtime', 'scope_path' => (string) ($packet->scope ?? 'work-packet/'.$packet->packet_id),
        ]);
        $cycle = $this->cycles->startCycle($intake, $packet, $built, $state);
        $state->refresh();

        if ($budget->allowProvider && $this->kernel !== null) {
            $workspace = (string) data_get($binding, 'workspace', base_path());
            $order = ($this->orders ?? new EngineeringModeExecutionOrderFactory)->make([
                'run_hash' => (string) $cycle->cycle_hash,
                'run_id' => (string) $cycle->uuid,
                'delivery_id' => (string) $packet->packet_id,
                'mode' => 'forge',
                'risk_class' => (string) (data_get($binding, 'risk_class') ?? $this->riskClassFromBand((string) $packet->risk_band)),
                'complexity_band' => 'C3',
                'duration_regime' => 'obra',
                'work_topology' => 'DAG',
                'product_intent_verdict_hash' => (string) data_get($binding, 'product_intent_hash'),
                'spec_hash' => (string) data_get($binding, 'spec_hash'),
                'world_model_snapshot_hash' => (string) data_get($binding, 'world_model_snapshot_hash'),
                'market_decision_hash' => data_get($binding, 'market_decision_hash'),
                'workspace' => $workspace,
                'base_commit' => $this->baseCommit($workspace),
                'allowed_scope' => $this->packetScope($packet),
                'forbidden_scope' => ['.env', '.git'],
                'authority_envelope' => [
                    'kind' => 'forge_commissioned_obra',
                    'authority_hash' => (string) data_get($binding, 'authority_hash', $packet->packet_hash),
                    'lease_id' => (string) data_get($cycle->execution_plan, 'scope_reservation.id', ''),
                    'lease_owner' => (string) data_get($cycle->execution_plan, 'scope_reservation.lease_owner', 'forge-obra-runtime'),
                    'fencing_token' => (int) data_get($cycle->execution_plan, 'scope_reservation.fencing_token', 0),
                ],
                'operator_presence' => 'commissioned',
                'provider_route' => ['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'],
                'mutate' => true,
                'experiment_ref' => 'forge-obra/'.(string) $cycle->uuid,
                'idempotency_key' => (string) data_get($cycle->execution_plan, 'scope_reservation.idempotency_key', 'forge-cycle:'.$cycle->uuid),
            ]);
            $outcome = $this->kernel->execute($order);
            $outcomeArray = $outcome->toArray();
            if ($outcome->status === 'released') {
                $evidence = [['kind' => 'engineering_outcome', 'ref' => 'outcome:'.$outcome->outcomeHash, 'source' => 'elite_executor_kernel']];
                $gate = [
                    'all_passed' => true,
                    'gates' => [['gate_id' => 'elite_executor_kernel', 'status' => 'passed', 'reason' => 'kernel_released']],
                    'execution' => ['status' => 'success', 'evidence_refs' => $evidence, 'kernel_outcome_hash' => $outcome->outcomeHash],
                ];
                $this->cycles->complete($cycle, $evidence, $gate, $state);
                $state->refresh();

                return ForgeTickResult::planned(
                    ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                    (string) $packet->packet_id, (string) $cycle->cycle_id, $outcomeArray,
                );
            }
            $reason = 'kernel_outcome_'.(($outcome->status ?? '') ?: 'blocked');
            $this->cycles->block($cycle, $reason, $state);
            $state->refresh();

            return ForgeTickResult::blocked(
                ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                (string) $packet->packet_id, (string) $cycle->cycle_id, $reason, $outcomeArray,
            );
        }

        return ForgeTickResult::planned(ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')), (string) $packet->packet_id, (string) $cycle->cycle_id);
    }

    /** @return list<string> */
    private function packetScope(AiForgeWorkPacket $packet): array
    {
        $files = array_values(array_filter(array_map('strval', (array) $packet->expected_files)));
        if ($files !== []) {
            return $files;
        }
        $scope = trim((string) $packet->scope, '/');

        return [$scope !== '' && ! str_contains($scope, '..') ? $scope : 'README.md'];
    }

    private function baseCommit(string $workspace): string
    {
        $process = new Process(['git', '-C', $workspace, 'rev-parse', 'HEAD']);
        $process->run();
        $commit = trim($process->getOutput());
        if (! $process->isSuccessful() || preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new InvalidArgumentException('forge_base_commit_unavailable');
        }

        return $commit;
    }

    private function riskClassFromBand(string $band): string
    {
        return match (strtolower($band)) {
            'low' => 'R1', 'medium' => 'R3', 'high' => 'R4', 'critical' => 'R5', default => 'R3',
        };
    }

    public function control(ForgeObraId $obra, ForgeControlCommand $command): ForgeObraSnapshot
    {
        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        if (! $state instanceof AiForgeLongHorizonState) {
            throw new InvalidArgumentException('forge_obra_not_found');
        }
        $reason = 'forge_control_'.$command->command;
        $cycle = $command->command === 'resume'
            ? ['resolve_blockers' => [$reason], 'cycle_id' => 'control-'.$command->command]
            : ['blockers' => [['scope' => 'obra', 'target' => $obra->value, 'reason' => $reason, 'resolved' => false]], 'cycle_id' => 'control-'.$command->command];
        $state = $this->states->recordCycle($state, $cycle);

        $intake = AiForgeIntake::query()->find($obra->value);
        $binding = is_array($intake?->rich_input_payload) ? $intake->rich_input_payload : [];

        return ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
    }
}
