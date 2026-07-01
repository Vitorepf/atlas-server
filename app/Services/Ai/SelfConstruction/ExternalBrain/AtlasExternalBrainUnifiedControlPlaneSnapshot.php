<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure snapshot that routes final-readiness blockers to specific closure actions
 * instead of generic "create more tasks" or "monitor".
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainUnifiedControlPlaneSnapshot
{
    public const SCHEMA = 'atlas.external_brain.unified_control_plane_snapshot.v1';

    /**
     * @param  array{
     *   stop_go_verdict?:string,
     *   stale_evidence_count?:int,
     *   provider_independence?:string,
     *   maturity_gap_count?:int,
     *   red_blocker_count?:int,
     *   yellow_blocker_count?:int,
     * }  $facts
     * @return array{
     *   schema:string,
     *   recommended_next_decision:string,
     *   stop_go_verdict:string,
     *   routing_reason:string,
     * }
     */
    public function snapshot(array $facts): array
    {
        $stopGo = (string) ($facts['stop_go_verdict'] ?? 'green');
        $staleEvidence = (int) ($facts['stale_evidence_count'] ?? 0);
        $providerIndependence = (string) ($facts['provider_independence'] ?? 'passing');
        $maturityGaps = (int) ($facts['maturity_gap_count'] ?? 0);
        $redBlockers = (int) ($facts['red_blocker_count'] ?? 0);
        $yellowBlockers = (int) ($facts['yellow_blocker_count'] ?? 0);

        // Provider dependency failing → red, route to close dependency
        if ($providerIndependence === 'failing') {
            return $this->envelope('close_provider_dependency', 'red', 'provider_independence failing → close dependency');
        }

        // Red blockers → stop
        if ($redBlockers > 0) {
            return $this->envelope('resolve_red_blockers', 'red', "{$redBlockers} red blockers must be resolved");
        }

        // Stale evidence → refresh before anything else
        if ($staleEvidence > 0) {
            return $this->envelope('refresh_evidence', $stopGo, "{$staleEvidence} stale evidence items must be refreshed");
        }

        // Maturity gaps with no red/yellow → compile gap chain
        if ($maturityGaps > 0 && $redBlockers === 0 && $yellowBlockers === 0) {
            return $this->envelope('compile_gap_chain', $stopGo, "{$maturityGaps} maturity gaps need structured gap-chain compilation");
        }

        // Yellow blockers → harden task fabric
        if ($yellowBlockers > 0) {
            return $this->envelope('harden_task_fabric', $stopGo, "{$yellowBlockers} yellow blockers indicate task-fabric weakness");
        }

        // Default: create more tasks (only when nothing more specific applies)
        return $this->envelope('create_more_tasks', $stopGo, 'no specific blockers — continue origination');
    }

    private function envelope(string $nextDecision, string $stopGo, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'recommended_next_decision' => $nextDecision,
            'stop_go_verdict' => $stopGo,
            'routing_reason' => $reason,
        ];
    }
}
