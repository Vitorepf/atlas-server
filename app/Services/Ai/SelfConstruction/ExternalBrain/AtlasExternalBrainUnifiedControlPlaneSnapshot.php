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
     *   external_brain_state?:array<string,mixed>,
     *   muscle_state?:array<string,mixed>,
     *   proof_state?:array<string,mixed>,
     *   task_fabric_state?:array<string,mixed>,
     *   queue_state?:array<string,mixed>,
     *   evidence_refs?:list<string>,
     * }  $facts
     * @return array{
     *   schema:string,
     *   recommended_decision:string,
     *   recommended_next_decision:string,
     *   stop_go_verdict:string,
     *   routing_reason:string,
     *   external_brain_state:array<string,mixed>,
     *   muscle_state:array<string,mixed>,
     *   proof_state:array<string,mixed>,
     *   task_fabric_state:array<string,mixed>,
     *   queue_state:array<string,mixed>,
     *   evidence_refs:list<string>,
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
            return $this->envelope('close_provider_dependency', 'red', 'provider_independence failing → close dependency', $facts);
        }

        // Red blockers → stop
        if ($redBlockers > 0) {
            return $this->envelope('resolve_red_blockers', 'red', "{$redBlockers} red blockers must be resolved", $facts);
        }

        // Stale evidence → refresh before anything else
        if ($staleEvidence > 0) {
            return $this->envelope('refresh_evidence', $stopGo, "{$staleEvidence} stale evidence items must be refreshed", $facts);
        }

        // Maturity gaps with no red/yellow → compile gap chain
        if ($maturityGaps > 0 && $redBlockers === 0 && $yellowBlockers === 0) {
            return $this->envelope('compile_gap_chain', $stopGo, "{$maturityGaps} maturity gaps need structured gap-chain compilation", $facts);
        }

        // Yellow blockers → harden task fabric
        if ($yellowBlockers > 0) {
            return $this->envelope('harden_task_fabric', $stopGo, "{$yellowBlockers} yellow blockers indicate task-fabric weakness", $facts);
        }

        // Default: create more tasks (only when nothing more specific applies)
        return $this->envelope('create_more_tasks', $stopGo, 'no specific blockers — continue origination', $facts);
    }

    private function envelope(string $nextDecision, string $stopGo, string $reason, array $facts): array
    {
        $subsystemStates = $this->extractSubsystemStates($facts);

        return [
            'schema' => self::SCHEMA,
            'recommended_decision' => $nextDecision,
            'recommended_next_decision' => $nextDecision,
            'stop_go_verdict' => $stopGo,
            'routing_reason' => $reason,
            'external_brain_state' => $subsystemStates['external_brain_state'],
            'muscle_state' => $subsystemStates['muscle_state'],
            'proof_state' => $subsystemStates['proof_state'],
            'task_fabric_state' => $subsystemStates['task_fabric_state'],
            'queue_state' => $subsystemStates['queue_state'],
            'evidence_refs' => $subsystemStates['evidence_refs'],
        ];
    }

    /**
     * Extract provider-safe subsystem states from facts.
     * Only includes provider-safe ids, hashes and summaries — no raw prompts, traces, or secrets.
     *
     * @return array{external_brain_state:array<string,mixed>, muscle_state:array<string,mixed>, proof_state:array<string,mixed>, task_fabric_state:array<string,mixed>, queue_state:array<string,mixed>, evidence_refs:list<string>}
     */
    private function extractSubsystemStates(array $facts): array
    {
        $externalBrainState = $this->safeExtract($facts['external_brain_state'] ?? [], [
            'autonomy_level', 'maturity_risk', 'proof_gates', 'task_lanes',
        ]);
        $muscleState = $this->safeExtract($facts['muscle_state'] ?? [], [
            'active_workers', 'tasks_in_flight', 'yield_rate', 'give_back_rate',
        ]);
        $proofState = $this->safeExtract($facts['proof_state'] ?? [], [
            'evidence_freshness', 'stale_evidence_count', 'cert_gate_status',
        ]);
        $taskFabricState = $this->safeExtract($facts['task_fabric_state'] ?? [], [
            'queue_depth', 'blocked_count', 'malformed_count', 'repair_rate',
        ]);
        $queueState = $this->safeExtract($facts['queue_state'] ?? [], [
            'pending', 'in_progress', 'completed', 'failed',
        ]);
        $evidenceRefs = array_values(array_filter(
            (array) ($facts['evidence_refs'] ?? []),
            fn ($ref) => is_string($ref) && strlen($ref) > 0,
        ));

        return [
            'external_brain_state' => $externalBrainState,
            'muscle_state' => $muscleState,
            'proof_state' => $proofState,
            'task_fabric_state' => $taskFabricState,
            'queue_state' => $queueState,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * Extract only provider-safe keys from a state array.
     * Filters out provider-sensitive fields (raw_prompt, provider_trace, conversation_text, secret).
     */
    private function safeExtract(array $state, array $allowedKeys): array
    {
        $sensitiveKeys = ['raw_prompt', 'provider_trace', 'conversation_text', 'secret'];
        $result = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $state)) {
                $result[$key] = $this->sanitizeValue($state[$key]);
            }
        }
        return $result;
    }

    /**
     * Sanitize a single value to ensure provider safety.
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->sanitizeValue($v), $value);
        }
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '...[truncated]';
        }
        return $value;
    }
}
