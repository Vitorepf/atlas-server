<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Pure, fail-closed reconciler for the Hermes Executive Mesh.
 *
 * A mesh plan (`atlas.hermes.mesh_plan.v1`, from the planner) fans a parent
 * mission out into child executions. Each child returns one
 * `atlas.hermes.result_packet.v1`. This reconciler aggregates those packets
 * back into a single sealed `atlas.hermes.mesh_reconciliation.v1` so the ATLS
 * Evidence Ledger can prove the fan-in result without trusting Hermes to
 * narrate it.
 *
 * Hermes is an executor/transport only — this reconciler NEVER decides policy,
 * NEVER promotes memory/skills/schedule, and NEVER calls a provider. Memory
 * candidates discovered in child packets are only COUNTED and routed to the
 * Atlas Memory Gate; promotion stays sovereign to Atlas. `reconciliation_authority`
 * is always Atlas and `hermes_mesh_can_decide` is always false.
 *
 * Fail-closed: if the plan is not `mesh_enabled` (mesh_enabled !== true) or no
 * result packets are present, the receipt is `aggregate_status => 'empty'` with
 * `reconciliation_allowed_now => false` and a `blocked_reason`. The allowed flag
 * is true ONLY when the plan is mesh-enabled AND at least one packet is present.
 * No raw goals/prompts/secrets reach the receipt — hashes only.
 */
class HermesMeshReconciler
{
    use HermesAdapterReceipt;

    private const MAX_CHILDREN = 64;

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array<string,mixed>>  $resultPackets
     * @return array<string,mixed>
     */
    public function reconcile(array $plan, array $resultPackets): array
    {
        $meshEnabled = ($plan['mesh_enabled'] ?? null) === true;
        $planChildren = $this->planChildren($plan);
        $packets = $this->normalizePackets($resultPackets);

        $children = $this->reconcileChildren($planChildren, $packets);

        $completed = $this->countByStatus($children, 'completed');
        $failed = $this->countByStatus($children, 'failed');
        $missing = $this->countByStatus($children, 'missing');

        $memoryCandidateCount = 0;
        $evidenceRefs = [];
        foreach ($packets as $packet) {
            $memoryCandidateCount += $this->memoryCandidateCount($packet);
            $evidenceHash = $this->evidenceRef($packet);
            if ($evidenceHash !== null) {
                $evidenceRefs[] = $evidenceHash;
            }
        }

        $reconciliationAllowedNow = $meshEnabled && $packets !== [];
        $aggregateStatus = $this->aggregateStatus($reconciliationAllowedNow, $children, $completed, $failed);
        $blockedReason = $this->blockedReason($meshEnabled, $packets);

        $receipt = [
            'schema_version' => 'atlas.hermes.mesh_reconciliation.v1',
            'reconciler' => 'hermes_mesh_reconciler',
            'reconciliation_authority' => 'atlas',
            'canonical_memory' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'memory_promotion_requires_atlas_memory_gate' => true,
            'authority' => 'atlas',
            'mesh_plan_id' => SharedHermesCheckpointPolicySeam::boundedString($plan['mesh_plan_id'] ?? $plan['plan_id'] ?? null, 190),
            'mission_id' => SharedHermesCheckpointPolicySeam::boundedString($plan['mission_id'] ?? null, 190),
            'mission_hash' => SharedHermesCheckpointPolicySeam::boundedString($plan['mission_hash'] ?? null, 190),
            'mesh_enabled' => $meshEnabled,
            'reconciliation_allowed_now' => $reconciliationAllowedNow,
            'aggregate_status' => $aggregateStatus,
            'children_total' => count($children),
            'children_completed' => $completed,
            'children_failed' => $failed,
            'children_missing' => $missing,
            'children' => $children,
            'memory_candidate_count' => $memoryCandidateCount,
            'memory_promotion_allowed_now' => false,
            'evidence_refs' => array_values($evidenceRefs),
            'blocked_reason' => $blockedReason,
        ];

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<int,array<string,mixed>>  $planChildren
     * @param  array<int,array<string,mixed>>  $packets
     * @return array<int,array<string,mixed>>
     */
    private function reconcileChildren(array $planChildren, array $packets): array
    {
        // The number of child slots is the larger of what the plan declared and
        // what packets actually arrived, so an extra packet is never silently
        // dropped and a planned child without a packet is reported as 'missing'.
        $slots = max(count($planChildren), count($packets));
        $slots = min($slots, self::MAX_CHILDREN);

        $children = [];
        for ($index = 0; $index < $slots; $index++) {
            $planChild = $planChildren[$index] ?? null;
            $packet = $packets[$index] ?? null;

            $children[] = [
                'child_index' => $index,
                'child_id' => $this->childId($planChild, $packet, $index),
                'status' => $this->childStatus($packet),
                'has_packet' => $packet !== null,
                'result_hash' => $packet !== null ? SharedHermesCheckpointPolicySeam::boundedString($packet['result_hash'] ?? null, 190) : null,
            ];
        }

        return $children;
    }

    /**
     * A packet with output present => 'completed'; a present-but-empty packet
     * => 'failed'; no packet at all => 'missing'.
     *
     * @param  array<string,mixed>|null  $packet
     */
    private function childStatus(?array $packet): string
    {
        if ($packet === null) {
            return 'missing';
        }

        return $this->packetHasOutput($packet) ? 'completed' : 'failed';
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function packetHasOutput(array $packet): bool
    {
        $responseHash = data_get($packet, 'output.response_hash');
        if (is_string($responseHash) && trim($responseHash) !== '') {
            return true;
        }

        $responseBytes = data_get($packet, 'output.response_bytes');

        return is_numeric($responseBytes) && (int) $responseBytes > 0;
    }

    /**
     * @param  array<int,array<string,mixed>>  $children
     */
    private function aggregateStatus(bool $reconciliationAllowedNow, array $children, int $completed, int $failed): string
    {
        if (! $reconciliationAllowedNow || $children === []) {
            return 'empty';
        }

        $total = count($children);

        if ($completed === $total) {
            return 'all_completed';
        }

        // Every non-missing child failed (and none completed) => all_failed.
        if ($completed === 0 && $failed > 0) {
            return 'all_failed';
        }

        return 'partial';
    }

    /**
     * @param  array<int,array<string,mixed>>  $packets
     */
    private function blockedReason(bool $meshEnabled, array $packets): ?string
    {
        if (! $meshEnabled) {
            return 'mesh_plan_not_enabled';
        }

        if ($packets === []) {
            return 'no_result_packets';
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $children
     */
    private function countByStatus(array $children, string $status): int
    {
        $count = 0;
        foreach ($children as $child) {
            if (($child['status'] ?? null) === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<int,array<string,mixed>>
     */
    private function planChildren(array $plan): array
    {
        $children = $plan['children'] ?? $plan['child_missions'] ?? null;
        if (! is_array($children)) {
            return [];
        }

        $normalized = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $normalized[] = $child;
            }
        }

        return array_slice(array_values($normalized), 0, self::MAX_CHILDREN);
    }

    /**
     * @param  array<int,array<string,mixed>>  $resultPackets
     * @return array<int,array<string,mixed>>
     */
    private function normalizePackets(array $resultPackets): array
    {
        $packets = [];
        foreach ($resultPackets as $packet) {
            if (is_array($packet)) {
                $packets[] = $packet;
            }
        }

        return array_slice(array_values($packets), 0, self::MAX_CHILDREN);
    }

    /**
     * @param  array<string,mixed>|null  $planChild
     * @param  array<string,mixed>|null  $packet
     */
    private function childId(?array $planChild, ?array $packet, int $index): ?string
    {
        $fromPlan = $planChild !== null
            ? SharedHermesCheckpointPolicySeam::boundedString($planChild['child_id'] ?? $planChild['mission_id'] ?? null, 190)
            : null;
        if ($fromPlan !== null) {
            return $fromPlan;
        }

        $fromPacket = $packet !== null
            ? SharedHermesCheckpointPolicySeam::boundedString($packet['result_id'] ?? $packet['mission_id'] ?? null, 190)
            : null;
        if ($fromPacket !== null) {
            return $fromPacket;
        }

        return 'hermes_mesh_child_'.$index;
    }

    /**
     * Sum of any memory-gate candidate counts found in a child packet. This is a
     * routing tally for the Atlas Memory Gate ONLY — nothing is promoted here.
     *
     * @param  array<string,mixed>  $packet
     */
    private function memoryCandidateCount(array $packet): int
    {
        $count = data_get($packet, 'memory_gate.candidate_count');
        if (is_numeric($count)) {
            return max(0, (int) $count);
        }

        $candidates = data_get($packet, 'memory_gate.candidates');
        if (is_array($candidates)) {
            return count($candidates);
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function evidenceRef(array $packet): ?string
    {
        $evidence = data_get($packet, 'evidence_packet');
        if (! is_array($evidence) || $evidence === []) {
            return null;
        }

        return $this->hashValue($evidence);
    }
}
