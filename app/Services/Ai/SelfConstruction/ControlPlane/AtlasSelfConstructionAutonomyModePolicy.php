<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure Control Plane policy that decides the ALLOWED autonomy mode for a Self-Construction cycle from
 * readiness, risk and rollback FACTS. Never executes anything.
 *
 * READINESS FACTS (each = bool ready + optional blockers):
 *   { task_fabric:{ready,blockers}, maestro:{ready,blockers}, native_worker:{ready,blockers},
 *     verification_court:{ready,blockers}, merge_governor:{ready,blockers},
 *     knowledge_sync:{ready,blockers}, rollback:{ready,blockers},
 *     server_side_verification:{ready,blockers},
 *     operator_overrides:{force_disabled?:bool, force_observe?:bool} }
 *
 * MODES (escalating capability):
 *   disabled            — Control Plane disabled or operator-force-disabled.
 *   observe             — at least one critical organ unready or operator-force-observe.
 *   propose             — basic organs ready (task_fabric + maestro + verification_court).
 *   execute_guarded     — propose + (native_worker ready) + (rollback ready) — execution allowed under guard.
 *   execute_continuous  — execute_guarded + server_side_verification ready + knowledge_sync ready.
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (blockers sorted).
 *   - PURE.
 *   - NEVER grants execute_continuous unless ALL of: native_worker, server_side_verification, rollback,
 *     knowledge_sync are ready.
 */
final class AtlasSelfConstructionAutonomyModePolicy
{
    public const SCHEMA = 'atlas.controlplane.autonomy_mode_policy.v1';

    public const MODE_DISABLED = 'disabled';

    public const MODE_OBSERVE = 'observe';

    public const MODE_PROPOSE = 'propose';

    public const MODE_EXECUTE_GUARDED = 'execute_guarded';

    public const MODE_EXECUTE_CONTINUOUS = 'execute_continuous';

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, mode:string, blockers:list<string>, reasons:list<string>, readiness_summary:array<string,bool>}
     */
    public function decide(array $facts): array
    {
        $blockers = [];
        $reasons = [];

        $override = is_array($facts['operator_overrides'] ?? null) ? $facts['operator_overrides'] : [];
        if (! empty($override['force_disabled'])) {
            $reasons[] = 'operator_force_disabled';

            return $this->envelope(self::MODE_DISABLED, [], $reasons, $facts);
        }

        $taskFabric = $this->readyOf($facts, 'task_fabric', $blockers);
        $maestro = $this->readyOf($facts, 'maestro', $blockers);
        $nativeWorker = $this->readyOf($facts, 'native_worker', $blockers);
        $verCourt = $this->readyOf($facts, 'verification_court', $blockers);
        $mergeGovernor = $this->readyOf($facts, 'merge_governor', $blockers);
        $knowledgeSync = $this->readyOf($facts, 'knowledge_sync', $blockers);
        $rollback = $this->readyOf($facts, 'rollback', $blockers);
        $serverVer = $this->readyOf($facts, 'server_side_verification', $blockers);

        $summary = [
            'task_fabric' => $taskFabric,
            'maestro' => $maestro,
            'native_worker' => $nativeWorker,
            'verification_court' => $verCourt,
            'merge_governor' => $mergeGovernor,
            'knowledge_sync' => $knowledgeSync,
            'rollback' => $rollback,
            'server_side_verification' => $serverVer,
        ];

        if (! empty($override['force_observe'])) {
            $reasons[] = 'operator_force_observe';

            return $this->envelope(self::MODE_OBSERVE, $blockers, $reasons, $facts, $summary);
        }

        // Dependency guard: human, operator, or external-provider dependency blocks execute_guarded
        // and execute_continuous — keeps the Atlas-native runtime honest.
        $depBlockers = $this->extractDependencyBlockers($facts);
        foreach ($depBlockers as $b) {
            $blockers[] = $b;
        }
        $hasDependencyBlock = $depBlockers !== [];

        $basicReady = $taskFabric && $maestro && $verCourt && $mergeGovernor;
        $guardedReady = $basicReady && $nativeWorker && $rollback;
        $continuousReady = $guardedReady && $serverVer && $knowledgeSync;

        $staleEvidence = (bool) ($facts['stale_evidence_detected'] ?? false);
        if ($staleEvidence && $continuousReady) {
            $blockers[] = 'stale_evidence:downgrade_to_guarded';
            $reasons[] = 'execute_guarded:stale_evidence_downgrade';

            return $this->envelope(self::MODE_EXECUTE_GUARDED, $blockers, $reasons, $facts, $summary);
        }

        // Queue-health guard: dirty serving metrics block execute_continuous without operator/external input.
        $queueHealthBlockers = $this->extractQueueHealthBlockers($facts);
        foreach ($queueHealthBlockers as $b) {
            $blockers[] = $b;
        }
        if ($queueHealthBlockers !== [] && $continuousReady) {
            $reasons[] = 'execute_guarded:queue_health_dirty';

            return $this->envelope(self::MODE_EXECUTE_GUARDED, $blockers, $reasons, $facts, $summary);
        }

        $mode = self::MODE_OBSERVE;
        if (! $hasDependencyBlock && $continuousReady) {
            $mode = self::MODE_EXECUTE_CONTINUOUS;
            $reasons[] = 'all_organs_ready';
        } elseif (! $hasDependencyBlock && $guardedReady) {
            $mode = self::MODE_EXECUTE_GUARDED;
            $reasons[] = 'execute_guarded:native_worker+rollback_ready';
        } elseif ($basicReady) {
            $mode = self::MODE_PROPOSE;
            $reasons[] = $hasDependencyBlock ? 'propose:dependency_block' : 'propose:basic_organs_ready';
        } else {
            $reasons[] = $hasDependencyBlock ? 'observe:dependency_block' : 'observe:basic_organs_unready';
        }

        return $this->envelope($mode, $blockers, $reasons, $facts, $summary);
    }

    /**
     * Returns named blockers for each dirty queue-health condition.
     * Pure — no I/O, no operator prompt, no external provider.
     *
     * @param  array<string,mixed>  $facts
     * @return list<string>
     */
    private function extractQueueHealthBlockers(array $facts): array
    {
        $qh = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];
        $blockers = [];
        if ((int) ($qh['malformed_count'] ?? 0) > 0) {
            $blockers[] = 'queue_health:malformed_count_positive';
        }
        if ((int) ($qh['recoverable_total'] ?? 0) > 0) {
            $blockers[] = 'queue_health:recoverable_lease_backlog';
        }
        if ((bool) ($qh['queue_disk_mismatch'] ?? false)) {
            $blockers[] = 'queue_health:queue_disk_mismatch';
        }
        if ((int) ($qh['poison_packets'] ?? 0) > 0) {
            $blockers[] = 'queue_health:poison_packets_active';
        }

        return $blockers;
    }

    /**
     * Returns named blockers for each dependency kind present in facts.
     * Checks top-level keys AND the nested `dependency_facts` map.
     *
     * @param  array<string,mixed>  $facts
     * @return list<string>
     */
    private function extractDependencyBlockers(array $facts): array
    {
        $nested = is_array($facts['dependency_facts'] ?? null) ? $facts['dependency_facts'] : [];
        $blockers = [];
        foreach (['human_dependency', 'operator_dependency', 'external_provider_dependency'] as $dep) {
            if (! empty($facts[$dep]) || ! empty($nested[$dep])) {
                $blockers[] = 'dependency:'.$dep;
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  &$blockers
     */
    private function readyOf(array $facts, string $organ, array &$blockers): bool
    {
        $row = is_array($facts[$organ] ?? null) ? $facts[$organ] : [];
        $ready = (bool) ($row['ready'] ?? false);
        if (! $ready) {
            $sub = (array) ($row['blockers'] ?? []);
            if ($sub === []) {
                $blockers[] = $organ.':not_ready';
            } else {
                foreach ($sub as $b) {
                    $blockers[] = $organ.':'.(string) $b;
                }
            }
        }

        return $ready;
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $facts
     * @param  array<string,bool>  $summary
     * @return array{schema:string, mode:string, blockers:list<string>, reasons:list<string>, readiness_summary:array<string,bool>}
     */
    private function envelope(string $mode, array $blockers, array $reasons, array $facts, array $summary = []): array
    {
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'blockers' => $blockers,
            'reasons' => $reasons,
            'readiness_summary' => $summary,
        ];
    }
}
