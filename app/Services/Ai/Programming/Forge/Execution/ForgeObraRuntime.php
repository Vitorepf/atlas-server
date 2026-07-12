<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ForgeObraRuntime
{
    public function __construct(
        private readonly ForgeIntakeService $intakes,
        private readonly ForgeLongHorizonStateService $states,
        private readonly ForgeWorkPacketExecutionCycleService $cycles,
    ) {}

    public function commission(ForgeCommissioning $commissioning): ForgeObraSnapshot
    {
        return DB::transaction(function () use ($commissioning): ForgeObraSnapshot {
            $intake = $this->intakes->intakeFromPrompt($commissioning->prompt, [
                'workspace_slug' => basename(rtrim($commissioning->workspace, '/')), 'risk_band' => self::riskBand($commissioning->riskClass),
                'recommended_forge_mode' => 'obra_intake', 'actor_type' => 'forge_commissioning',
                'workspace_execution_gate' => [
                    'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
                    'mode' => 'forge', 'workspace_id' => basename(rtrim($commissioning->workspace, '/')),
                    'allowed' => true, 'status' => 'passed', 'blockers' => [],
                    'required_contracts' => ['awco_execution_readiness' => true],
                ],
                'authority_hash' => $commissioning->authorityHash, 'product_intent_hash' => $commissioning->productIntentHash,
                'spec_hash' => $commissioning->specHash, 'world_model_snapshot_hash' => $commissioning->worldModelSnapshotHash,
                'market_decision_hash' => $commissioning->marketDecisionHash,
                'rich_input_payload' => [
                    'schema_version' => 'atlas.quality_foundry.mode_binding.v1',
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
        if (! $state instanceof AiForgeLongHorizonState) throw new InvalidArgumentException('forge_obra_not_found');

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
        if (! $intake instanceof AiForgeIntake || ! $state instanceof AiForgeLongHorizonState) throw new InvalidArgumentException('forge_obra_not_found');
        $packet = $this->cycles->selectPacket($intake, $state);
        $binding = is_array($intake->rich_input_payload) ? $intake->rich_input_payload : [];
        $snapshot = ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
        if ($packet === null) return ForgeTickResult::idle($snapshot, 'no_eligible_packet');
        $built = $this->cycles->planExecution($packet, [
            'execution_mode' => $budget->allowProvider ? 'real' : 'safe_simulation', 'lease_seconds' => $budget->leaseSeconds,
            'lease_owner' => 'forge-obra-runtime', 'scope_path' => (string) ($packet->scope ?? 'work-packet/'.$packet->packet_id),
        ]);
        $cycle = $this->cycles->startCycle($intake, $packet, $built, $state);
        $state->refresh();

        return ForgeTickResult::planned(ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')), (string) $packet->packet_id, (string) $cycle->cycle_id);
    }

    public function control(ForgeObraId $obra, ForgeControlCommand $command): ForgeObraSnapshot
    {
        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        if (! $state instanceof AiForgeLongHorizonState) throw new InvalidArgumentException('forge_obra_not_found');
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
