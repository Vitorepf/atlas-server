<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;

/** Canonical unattended supervisor for active Forge Obras. */
class ForgeObraSupervisor
{
    public function __construct(
        private readonly ForgeObraRuntime $runtime,
        private readonly ForgeScopeReservationService $reservations,
    ) {}

    /**
     * Reap expired leases first, then heartbeat every active Obra.
     *
     * @param  list<string>|null  $obraIds
     * @return array<string,mixed>
     */
    public function run(?array $obraIds = null, int $leaseSeconds = 900): array
    {
        $query = AiForgeLongHorizonState::query()->where('status', 'active');
        $ids = array_values(array_filter(array_map('strval', (array) $obraIds), static fn (string $id): bool => trim($id) !== ''));
        if ($ids !== []) {
            $query->whereIn('intake_id', $ids);
        }

        $reaped = $this->reservations->reapExpired();
        $heartbeats = [];
        foreach ($query->pluck('intake_id') as $intakeId) {
            $obra = ForgeObraId::fromString((string) $intakeId);
            $heartbeat = $this->runtime->heartbeat($obra, leaseSeconds: max(1, $leaseSeconds));
            $cycle = AiForgeWorkPacketExecutionCycle::query()
                ->where('intake_id', (string) $intakeId)
                ->where('status', ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING)
                ->orderByDesc('started_at')
                ->first();
            $providerLifecycle = is_array($cycle?->execution_plan)
                && is_array($cycle->execution_plan['provider_lifecycle'] ?? null)
                ? $cycle->execution_plan['provider_lifecycle']
                : [];
            if ($cycle instanceof AiForgeWorkPacketExecutionCycle && $providerLifecycle !== []) {
                $heartbeat['provider_heartbeat'] = $this->runtime->providerHeartbeat(
                    $obra,
                    (string) $cycle->uuid,
                    (int) ($providerLifecycle['fencing_token'] ?? 0),
                );
            }
            if (in_array((string) ($heartbeat['status'] ?? ''), ['stale', 'blocked'], true)) {
                $heartbeat['orphan_recovery'] = $this->runtime->recoverOrphanedCycle(
                    $obra,
                    (string) ($heartbeat['cycle_id'] ?? ''),
                );
            }
            $heartbeats[] = $heartbeat;
        }

        $stale = array_values(array_filter($heartbeats, static fn (array $heartbeat): bool => ($heartbeat['renewed'] ?? false) === false
            && ($heartbeat['status'] ?? '') !== 'idle'));

        return [
            'schema' => 'atlas.forge.supervisor.v1',
            'status' => $stale === [] ? 'ok' : 'attention_required',
            'reaped' => $reaped,
            'heartbeats' => $heartbeats,
            'active_obra_count' => count($heartbeats),
            'stale_heartbeat_count' => count($stale),
        ];
    }
}
