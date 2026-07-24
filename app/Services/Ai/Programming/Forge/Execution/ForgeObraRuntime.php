<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\Forge\AtlasForgeProviderLifecycleAdapter;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeProviderLifecyclePort;
use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionPort;
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
        private readonly ?ForgeWorkPacketExecutionPort $kernelExecution = null,
        private readonly ?AwisExecutionGatePort $workspaceExecutionGate = null,
        private readonly ?ForgeScopeReservationService $scopeReservations = null,
    ) {}

    /**
     * Non-mutating commissioning projection for control-plane admission.
     * Persistence and packet execution remain exclusively in commission/tick.
     *
     * @return array<string,mixed>
     */
    public function commissioningContract(ForgeCommissioning $commissioning): array
    {
        return [
            'schema' => 'atlas.forge.native_commissioning.v1',
            'status' => 'prepared',
            'commissioning_owner' => ForgeCommissioning::class,
            'runtime_owner' => self::class,
            'runtime_owner_invoked' => true,
            'commissioning_ref' => $commissioning->commissioningHash,
            'authority_status' => 'commissioning_only',
            'execution_requested' => false,
            'mutation_authorized' => false,
        ];
    }

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
                'work_packets' => $commissioning->workPackets,
                'runtime_mode' => 'forge',
                'forge_frozen_context' => true,
                'frozen_context_hash' => hash('sha256', json_encode([
                    'authority_hash' => $commissioning->authorityHash,
                    'product_intent_hash' => $commissioning->productIntentHash,
                    'spec_hash' => $commissioning->specHash,
                    'world_model_snapshot_hash' => $commissioning->worldModelSnapshotHash,
                    'market_decision_hash' => $commissioning->marketDecisionHash,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
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
        $binding = is_array($intake->rich_input_payload) ? $intake->rich_input_payload : [];
        $snapshot = ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));

        $controlBlocker = $this->activeControlBlocker($state);
        if ($controlBlocker !== null) {
            return ForgeTickResult::blocked($snapshot, 'control', 'control-'.$controlBlocker, $controlBlocker);
        }

        $heartbeat = $this->heartbeat($obra, leaseSeconds: $budget->leaseSeconds);
        if (in_array((string) ($heartbeat['status'] ?? ''), ['stale', 'blocked'], true)) {
            return ForgeTickResult::blocked(
                $snapshot,
                (string) ($heartbeat['packet_id'] ?? 'unknown'),
                (string) ($heartbeat['cycle_id'] ?? 'heartbeat'),
                (string) ($heartbeat['reason'] ?? 'lease_heartbeat_rejected'),
            );
        }

        $packet = $this->cycles->selectPacket($intake, $state);
        if ($packet === null) {
            return ForgeTickResult::idle($snapshot, 'no_eligible_packet');
        }
        $built = $this->cycles->planExecution($packet, [
            'execution_mode' => $budget->allowProvider ? 'real' : 'safe_simulation', 'lease_seconds' => $budget->leaseSeconds,
            'lease_owner' => 'forge-obra-runtime', 'scope_path' => (string) ($packet->scope ?? 'work-packet/'.$packet->packet_id),
        ]);
        $cycle = $this->cycles->startCycle($intake, $packet, $built, $state);
        $state->refresh();

        $kernelExecution = $this->kernelExecution;
        if ($budget->allowProvider && $kernelExecution === null && ! app()->bound(ForgeWorkPacketExecutionPort::class)) {
            $reason = 'elite_executor_kernel_unavailable';
            $this->cycles->block($cycle, $reason, $state);
            $state->refresh();

            return ForgeTickResult::blocked(
                ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                (string) $packet->packet_id,
                (string) $cycle->uuid,
                $reason,
            );
        }

        if ($budget->allowProvider) {
            $providerStart = $this->providerStart($obra, (string) $cycle->uuid);
            if (($providerStart['status'] ?? null) !== 'started') {
                $reason = 'provider_start_'.((string) ($providerStart['reason'] ?? 'rejected'));
                $this->cycles->block($cycle, $reason, $state);
                $state->refresh();

                return ForgeTickResult::blocked(
                    ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                    (string) $packet->packet_id, (string) $cycle->uuid, $reason,
                );
            }
            $providerFence = (int) ($providerStart['fencing_token'] ?? 0);
            $workspace = (string) data_get($binding, 'workspace', base_path());
            $outcome = $this->cycles->executeRealCycle(
                $intake,
                $packet,
                $cycle,
                $workspace,
                $this->baseCommit($workspace),
                'forge-obra-runtime',
                ['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'],
            );
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
                $this->providerPoll($obra, (string) $cycle->uuid, $providerFence);

                return ForgeTickResult::planned(
                    ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                    (string) $packet->packet_id, (string) $cycle->uuid, $outcomeArray,
                );
            }
            $reason = 'kernel_outcome_'.(($outcome->status ?? '') ?: 'blocked');
            $this->cycles->block($cycle, $reason, $state);
            $state->refresh();
            $this->providerPoll($obra, (string) $cycle->uuid, $providerFence);

            return ForgeTickResult::blocked(
                ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'), data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')),
                (string) $packet->packet_id, (string) $cycle->uuid, $reason, $outcomeArray,
            );
        }

        return ForgeTickResult::planned(ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash')), (string) $packet->packet_id, (string) $cycle->uuid);
    }

    /** Start the provider-side lifecycle for a persisted real cycle exactly once. */
    public function providerStart(ForgeObraId $obra, ?string $cycleId = null): array
    {
        $cycle = $this->providerCycle($obra, $cycleId);
        if (! $cycle instanceof AiForgeWorkPacketExecutionCycle) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'no_cycle'];
        }
        $plan = (array) ($cycle->execution_plan ?? []);
        $reservation = (array) ($plan['scope_reservation'] ?? []);
        $fence = (int) ($reservation['fencing_token'] ?? 0);
        $current = (array) ($plan['provider_lifecycle'] ?? []);
        if (($current['status'] ?? null) === 'started') {
            if ($fence !== (int) ($current['fencing_token'] ?? -1)) {
                return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_fencing_token_mismatch'];
            }

            $current['schema'] = 'atlas.forge.provider_lifecycle.v1';
            $current['replayed'] = true;

            return $current;
        }
        if ($cycle->execution_mode !== ForgeWorkPacketExecutionCycleCanon::MODE_REAL) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'provider_lifecycle_requires_real_cycle'];
        }
        if ($cycle->status !== ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING || $fence < 1) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_start_authority_missing'];
        }

        $providerPort = $this->providerPort()->start([
            'provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry',
            'cycle_id' => (string) $cycle->uuid, 'fencing_token' => $fence,
            'scope_reservation' => $reservation,
        ]);
        if (($providerPort['status'] ?? null) !== 'ready') {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'provider_port_'.((string) ($providerPort['reason'] ?? 'rejected'))];
        }

        $lifecycle = [
            'schema' => 'atlas.forge.provider_lifecycle.v1',
            'status' => 'started',
            'provider_execution_id' => 'forge-provider:'.$cycle->uuid,
            'provider' => 'atlas_kernel',
            'model' => 'shared_quality_foundry',
            'cycle_id' => (string) $cycle->uuid,
            'fencing_token' => $fence,
            'started_at' => now()->toIso8601String(),
            'last_heartbeat_at' => now()->toIso8601String(),
            'replayed' => false,
            'provider_port' => $providerPort,
        ];
        $this->cycles->recordProviderLifecycle($cycle, $lifecycle);

        return $lifecycle;
    }

    /** Poll the durable provider lifecycle without re-executing the Kernel. */
    public function providerPoll(ForgeObraId $obra, ?string $cycleId, int $fencingToken): array
    {
        $cycle = $this->providerCycle($obra, $cycleId);
        if (! $cycle instanceof AiForgeWorkPacketExecutionCycle) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'no_cycle'];
        }
        $lifecycle = (array) data_get($cycle->execution_plan, 'provider_lifecycle', []);
        if ($lifecycle === []) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'provider_not_started'];
        }
        if ($fencingToken !== (int) ($lifecycle['fencing_token'] ?? -1)) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_fencing_token_mismatch'];
        }
        $providerPort = $this->providerPort()->poll([
            'provider' => (string) ($lifecycle['provider'] ?? 'atlas_kernel'),
            'cycle_id' => (string) $cycle->uuid, 'fencing_token' => $fencingToken,
        ]);
        if (($providerPort['status'] ?? null) !== 'ready') {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'provider_port_'.((string) ($providerPort['reason'] ?? 'rejected'))];
        }
        if ($cycle->status !== ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING) {
            $lifecycle['status'] = $cycle->outcome_status ?: $cycle->status;
        } elseif (($lifecycle['status'] ?? null) === 'started') {
            $lifecycle['status'] = 'running';
        }

        return $lifecycle + ['polled_at' => now()->toIso8601String()];
    }

    /** Record a fenced provider heartbeat; it never starts or re-runs provider work. */
    public function providerHeartbeat(ForgeObraId $obra, ?string $cycleId, int $fencingToken): array
    {
        $cycle = $this->providerCycle($obra, $cycleId);
        $lifecycle = $cycle instanceof AiForgeWorkPacketExecutionCycle
            ? (array) data_get($cycle->execution_plan, 'provider_lifecycle', [])
            : [];
        if ($lifecycle === [] || $fencingToken !== (int) ($lifecycle['fencing_token'] ?? -1)) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_fencing_token_mismatch'];
        }
        $providerPort = $this->providerPort()->heartbeat([
            'provider' => (string) ($lifecycle['provider'] ?? 'atlas_kernel'),
            'cycle_id' => (string) $cycle->uuid, 'fencing_token' => $fencingToken,
        ]);
        if (($providerPort['status'] ?? null) !== 'ready') {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_port_'.((string) ($providerPort['reason'] ?? 'rejected'))];
        }
        $lifecycle['last_heartbeat_at'] = now()->toIso8601String();
        $updated = $this->cycles->recordProviderLifecycle($cycle, $lifecycle);

        return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'ok', 'cycle_id' => (string) $updated->uuid, 'fencing_token' => $fencingToken, 'last_heartbeat_at' => $lifecycle['last_heartbeat_at']];
    }

    /** Cancel a provider lifecycle once and persist the cancellation through the cycle owner. */
    public function providerCancel(ForgeObraId $obra, ?string $cycleId, int $fencingToken, string $reason): array
    {
        $cycle = $this->providerCycle($obra, $cycleId);
        if (! $cycle instanceof AiForgeWorkPacketExecutionCycle) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'blocked', 'reason' => 'no_cycle'];
        }
        $lifecycle = (array) data_get($cycle->execution_plan, 'provider_lifecycle', []);
        if ($fencingToken !== (int) ($lifecycle['fencing_token'] ?? -1)) {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_fencing_token_mismatch'];
        }
        $providerPort = $this->providerPort()->cancel([
            'provider' => (string) ($lifecycle['provider'] ?? 'atlas_kernel'),
            'cycle_id' => (string) $cycle->uuid, 'fencing_token' => $fencingToken, 'reason' => $reason,
        ]);
        if (($providerPort['status'] ?? null) !== 'ready') {
            return ['schema' => 'atlas.forge.provider_lifecycle.v1', 'status' => 'stale', 'reason' => 'provider_port_'.((string) ($providerPort['reason'] ?? 'rejected'))];
        }
        if (($lifecycle['status'] ?? null) === 'cancelled') {
            $lifecycle['replayed'] = true;

            return $lifecycle;
        }
        $lifecycle['status'] = 'cancelled';
        $lifecycle['reason'] = trim($reason) !== '' ? trim($reason) : 'provider_cancelled';
        $lifecycle['cancelled_at'] = now()->toIso8601String();
        $this->cycles->recordProviderLifecycle($cycle, $lifecycle);

        return $lifecycle + ['replayed' => false];
    }

    private function providerCycle(ForgeObraId $obra, ?string $cycleId): ?AiForgeWorkPacketExecutionCycle
    {
        $query = AiForgeWorkPacketExecutionCycle::query()->where('intake_id', $obra->value)->orderByDesc('started_at');
        if ($cycleId !== null && trim($cycleId) !== '') {
            $query->where(function ($builder) use ($cycleId): void {
                $builder->where('uuid', $cycleId)->orWhere('id', $cycleId);
            });
        }

        return $query->first();
    }

    private function providerPort(): ForgeProviderLifecyclePort
    {
        return app()->bound(ForgeProviderLifecyclePort::class)
            ? app(ForgeProviderLifecyclePort::class)
            : new AtlasForgeProviderLifecycleAdapter(app(AtlasForgeProviderInvocationDriverRouter::class));
    }

    /**
     * Renew the live scope lease for the currently running packet.
     *
     * This is the supervisor heartbeat seam: it carries the persisted owner,
     * token and fencing number from the cycle plan back to the canonical lease
     * owner. Missing or stale fencing data fails closed and never creates a
     * replacement lease.
     *
     * @return array<string,mixed>
     */
    public function heartbeat(ForgeObraId $obra, ?string $cycleId = null, int $leaseSeconds = 900): array
    {
        $query = AiForgeWorkPacketExecutionCycle::query()
            ->where('intake_id', $obra->value)
            ->where('status', ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING)
            ->where('execution_mode', ForgeWorkPacketExecutionCycleCanon::MODE_REAL)
            ->orderByDesc('started_at');
        if ($cycleId !== null && trim($cycleId) !== '') {
            $query->where(function ($builder) use ($cycleId): void {
                $builder->where('uuid', $cycleId)->orWhere('id', $cycleId);
            });
        }

        $cycle = $query->first();
        if (! $cycle instanceof AiForgeWorkPacketExecutionCycle) {
            return [
                'schema' => 'atlas.forge.heartbeat.v1',
                'status' => 'idle',
                'renewed' => false,
                'reason' => 'no_running_cycle',
            ];
        }

        $executionPlan = (array) ($cycle->execution_plan ?? []);
        $reservation = is_array($executionPlan['scope_reservation'] ?? null)
            ? $executionPlan['scope_reservation']
            : [];
        $required = ['id', 'lease_owner', 'lease_token', 'fencing_token'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $reservation) || trim((string) $reservation[$field]) === '') {
                return [
                    'schema' => 'atlas.forge.heartbeat.v1',
                    'status' => 'blocked',
                    'renewed' => false,
                    'cycle_id' => (string) $cycle->uuid,
                    'reason' => 'scope_reservation_binding_missing:'.$field,
                ];
            }
        }

        $result = ($this->scopeReservations ?? app(ForgeScopeReservationService::class))->renew(
            id: (string) $reservation['id'],
            owner: (string) $reservation['lease_owner'],
            token: (string) $reservation['lease_token'],
            fence: (int) $reservation['fencing_token'],
            leaseSeconds: max(1, $leaseSeconds),
        );

        return [
            'schema' => 'atlas.forge.heartbeat.v1',
            'status' => ($result['renewed'] ?? false) === true ? 'ok' : 'stale',
            'renewed' => (bool) ($result['renewed'] ?? false),
            'cycle_id' => (string) $cycle->uuid,
            'packet_id' => (string) $cycle->work_packet_canonical_id,
            'reservation' => $result['reservation'] ?? null,
            'reason' => ($result['renewed'] ?? false) === true ? null : 'lease_fencing_or_expiry_rejected',
        ];
    }

    /**
     * Convert an orphaned running cycle into a terminal blocked cycle.
     *
     * The supervisor calls this only after the lease heartbeat has failed. It
     * is deliberately idempotent: a cycle already terminal is reported as
     * already_recovered and never receives a second transition.
     *
     * @return array<string,mixed>
     */
    public function recoverOrphanedCycle(ForgeObraId $obra, ?string $cycleId = null): array
    {
        $query = AiForgeWorkPacketExecutionCycle::query()
            ->where('intake_id', $obra->value)
            ->where('status', ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING)
            ->orderByDesc('started_at');
        if ($cycleId !== null && trim($cycleId) !== '') {
            $query->where(function ($builder) use ($cycleId): void {
                $builder->where('uuid', $cycleId)->orWhere('id', $cycleId);
            });
        }

        $cycle = $query->first();
        if (! $cycle instanceof AiForgeWorkPacketExecutionCycle) {
            return [
                'schema' => 'atlas.forge.orphan_recovery.v1',
                'status' => 'idle',
                'recovered' => false,
                'reason' => 'no_running_cycle',
            ];
        }

        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        $blocked = $this->cycles->block($cycle, 'scope_lease_lost_orphan_recovery', $state);

        return [
            'schema' => 'atlas.forge.orphan_recovery.v1',
            'status' => 'recovered',
            'recovered' => true,
            'cycle_id' => (string) $blocked->uuid,
            'packet_id' => (string) $blocked->work_packet_canonical_id,
            'reason' => (string) $blocked->failure_reason,
        ];
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

    public function control(ForgeObraId $obra, ForgeControlCommand $command): ForgeObraSnapshot
    {
        $state = AiForgeLongHorizonState::query()->where('intake_id', $obra->value)->first();
        if (! $state instanceof AiForgeLongHorizonState) {
            throw new InvalidArgumentException('forge_obra_not_found');
        }
        $reason = 'forge_control_'.$command->command;
        $intake = AiForgeIntake::query()->find($obra->value);
        $binding = is_array($intake?->rich_input_payload) ? $intake->rich_input_payload : [];

        $controlBlockerReasons = $this->controlBlockerReasons($state);
        $alreadyApplied = $command->command === 'resume'
            ? ! $this->hasUnresolvedBlocker($state, $controlBlockerReasons)
            : $this->hasUnresolvedBlocker($state, [$reason]);
        if ($alreadyApplied) {
            return ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
                data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
        }

        $cycle = $command->command === 'resume'
            ? ['resolve_blockers' => $controlBlockerReasons, 'cycle_id' => 'control-'.$command->command]
            : ['blockers' => [['scope' => 'obra', 'target' => $obra->value, 'reason' => $reason, 'resolved' => false]], 'cycle_id' => 'control-'.$command->command];
        $state = $this->states->recordCycle($state, $cycle);

        return ForgeObraSnapshot::fromState($state, '', data_get($binding, 'product_intent_hash'), data_get($binding, 'spec_hash'),
            data_get($binding, 'world_model_snapshot_hash'), data_get($binding, 'market_decision_hash'));
    }

    /** @return list<string> */
    private function controlBlockerReasons(AiForgeLongHorizonState $state): array
    {
        return ['forge_control_pause', 'forge_control_drain'];
    }

    private function activeControlBlocker(AiForgeLongHorizonState $state): ?string
    {
        foreach (['forge_control_cancel', 'forge_control_pause', 'forge_control_drain'] as $reason) {
            if ($this->hasUnresolvedBlocker($state, [$reason])) {
                return $reason;
            }
        }

        return null;
    }

    /** @param list<string> $reasons */
    private function hasUnresolvedBlocker(AiForgeLongHorizonState $state, array $reasons): bool
    {
        foreach ((array) $state->blockers as $blocker) {
            if (is_array($blocker)
                && ($blocker['resolved'] ?? false) !== true
                && in_array((string) ($blocker['reason'] ?? ''), $reasons, true)) {
                return true;
            }
        }

        return false;
    }
}
