<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ScopeExpansion;

/**
 * Pure planner that converts a ready scope candidate + readiness verdict + lane facts into a concrete
 * Atlas project-lane admission plan. SUPPORTS:
 *   - lane_type = atlas_internal  (Atlas-internal scope lane)
 *   - lane_type = external_project (external project stewardship lane)
 *
 * NEVER executes anything: no file writes, no queue writes, no providers, no git, no subprocesses,
 * no operator handoff. The output is a DEFINITION the runtime layer may later realise.
 */
final class AtlasSelfConstructionScopeExpansionLaneAdmissionPlan
{
    public const SCHEMA = 'atlas.self_construction.scope_expansion_lane_admission_plan.v1';

    public const STATUS_READY = 'ready';
    public const STATUS_HELD = 'held';
    public const STATUS_REJECTED = 'rejected';

    public const TOPOLOGY_REQUIRED = 'shared_local_main_with_scope_lock';

    /**
     * @param  array<string,mixed>  $candidate {id, label, lane_type}
     * @param  array<string,mixed>  $readiness output of the readiness gate
     * @param  array<string,mixed>  $laneFacts {project_id, lane_type, queue_namespace, allowed_roots,
     *                                          forbidden_roots, execution_topology, existing_lane_roots}
     * @return array<string,mixed>
     */
    public function plan(array $candidate, array $readiness, array $laneFacts): array
    {
        if ((string) ($readiness['status'] ?? '') !== 'ready') {
            return $this->envelope(self::STATUS_HELD, [
                'candidate_id' => (string) ($candidate['id'] ?? ''),
                'reason' => 'readiness_not_ready:'.(string) ($readiness['status'] ?? ''),
            ]);
        }

        $blockers = [];

        $projectId = (string) ($laneFacts['project_id'] ?? '');
        if ($projectId === '') {
            $blockers[] = 'project_id_missing';
        }
        $laneType = (string) ($laneFacts['lane_type'] ?? ($candidate['lane_type'] ?? ''));
        if (! in_array($laneType, ['atlas_internal', 'external_project'], true)) {
            $blockers[] = 'lane_type_invalid:'.$laneType;
        }
        $namespace = (string) ($laneFacts['queue_namespace'] ?? '');
        if ($namespace === '') {
            $blockers[] = 'queue_namespace_missing';
        }
        $topology = (string) ($laneFacts['execution_topology'] ?? '');
        if ($topology !== self::TOPOLOGY_REQUIRED) {
            $blockers[] = 'execution_topology_unexpected:'.$topology;
        }

        $allowedRoots = array_values(array_map('strval', (array) ($laneFacts['allowed_roots'] ?? [])));
        $forbiddenRoots = array_values(array_map('strval', (array) ($laneFacts['forbidden_roots'] ?? [])));
        if ($allowedRoots === []) {
            $blockers[] = 'allowed_roots_empty';
        }
        if (! (bool) ($laneFacts['quality_floor_met'] ?? true)) {
            $blockers[] = 'quality_floor_not_met';
        }
        if (! (bool) ($laneFacts['rollback_ready'] ?? true)) {
            $blockers[] = 'rollback_not_ready';
        }

        $existing = (array) ($laneFacts['existing_lane_roots'] ?? []);
        foreach ($allowedRoots as $root) {
            foreach ($existing as $otherLane => $otherRoots) {
                foreach ((array) $otherRoots as $otherRoot) {
                    if ($this->rootsOverlap($root, (string) $otherRoot)) {
                        $blockers[] = 'allowed_root_crosses_lane:'.$root.':conflicts_with:'.(string) $otherLane;
                    }
                }
            }
        }

        if ($blockers !== []) {
            sort($blockers, SORT_STRING);

            return $this->envelope(self::STATUS_REJECTED, [
                'candidate_id' => (string) ($candidate['id'] ?? ''),
                'project_id' => $projectId,
                'lane_type' => $laneType,
                'blockers' => $blockers,
            ]);
        }

        $laneId = 'lane:'.$projectId.':'.(string) ($candidate['id'] ?? '');
        $plan = [
            'candidate_id' => (string) ($candidate['id'] ?? ''),
            'project_id' => $projectId,
            'lane_id' => $laneId,
            'lane_type' => $laneType,
            'queue_namespace' => $namespace,
            'allowed_roots' => $allowedRoots,
            'forbidden_roots' => $forbiddenRoots,
            'execution_topology' => $topology,
            'verification_hooks' => ['atlas.verification_court.evaluate'],
            'release_hooks' => ['atlas.release_governor.preflight', 'atlas.release_governor.commit'],
            'receipt_hooks' => ['atlas.receipts.append'],
            'rollback_hooks' => ['atlas.rollback.apply'],
            'knowledge_sync_hooks' => ['atlas.engineering.knowledge.sync', 'atlas.engineering.knowledge.index-code'],
            'requires_operator_handoff' => false,
            'lane_priority' => $laneType === 'atlas_internal' ? 1 : 2,
        ];

        return $this->envelope(self::STATUS_READY, $plan);
    }

    private function rootsOverlap(string $a, string $b): bool
    {
        $a = rtrim($a, '/');
        $b = rtrim($b, '/');
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_starts_with($a.'/', $b.'/') || str_starts_with($b.'/', $a.'/');
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function envelope(string $status, array $body): array
    {
        $envelope = ['schema_version' => self::SCHEMA, 'status' => $status] + $body;
        ksort($envelope);
        $envelope['admission_plan_hash'] = hash('sha256', (string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $envelope;
    }
}
