<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure composer that summarizes the readiness of every Self-Construction organ into one FACTS-only
 * Control-Plane envelope.
 *
 * Output: {schema_version, all_ready, ready_organs, blocked_organs, missing_organs, next_required_organs}
 * NO scalar score, NO ranking. Deterministic order: organs always appear in CANONICAL_ORGANS order.
 *
 * CRITICAL organs (required for continuous 24/7 autonomy) — their absence FAILS CLOSED into missing_organs:
 *   - cortex, goal_value, strategy, architecture, task_fabric, maestro, native_worker,
 *     verification_court, merge_governor, knowledge_sync, learning_transfer.
 *
 * Per-organ status fact shape: {status: 'ready'|'blocked'|'degraded', reason?:string, blockers?:list<string>}.
 */
final class AtlasSelfConstructionOrganReadinessComposer
{
    public const SCHEMA = 'atlas.self_construction.organ_readiness.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_MISSING = 'missing';

    public const CANONICAL_ORGANS = [
        'cortex',
        'goal_value',
        'strategy',
        'architecture',
        'task_fabric',
        'maestro',
        'native_worker',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
    ];

    public const CRITICAL_ORGANS = self::CANONICAL_ORGANS; // every canonical organ is critical for continuous autonomy

    /**
     * @param  array<string,array<string,mixed>>  $organFacts  organ_id => {status, reason?, blockers?}
     * @return array<string,mixed>
     */
    public function compose(array $organFacts): array
    {
        $ready = [];
        $blocked = [];
        $degraded = [];
        $missing = [];
        $nextRequired = [];

        foreach (self::CANONICAL_ORGANS as $organ) {
            $row = $organFacts[$organ] ?? null;
            if (! is_array($row) || ! isset($row['status'])) {
                $missing[] = $organ;
                if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                    $nextRequired[] = $organ;
                }

                continue;
            }
            $status = (string) $row['status'];
            switch ($status) {
                case self::STATUS_READY:
                    $evidenceBlockers = $this->evidenceFloorBlockers($row);
                    if ($evidenceBlockers !== []) {
                        $blocked[] = [
                            'organ' => $organ,
                            'reason' => 'evidence_floor_not_met',
                            'blockers' => $evidenceBlockers,
                        ];
                        if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                            $nextRequired[] = $organ;
                        }
                        break;
                    }
                    if ((string) ($row['freshness_status'] ?? '') === 'stale') {
                        $degraded[] = ['organ' => $organ, 'reason' => 'evidence_stale'];
                        $nextRequired[] = $organ;
                        break;
                    }
                    $ready[] = $organ;
                    break;
                case self::STATUS_BLOCKED:
                    $blocked[] = [
                        'organ' => $organ,
                        'reason' => (string) ($row['reason'] ?? 'blocked_no_reason'),
                        'blockers' => array_values((array) ($row['blockers'] ?? [])),
                    ];
                    if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                        $nextRequired[] = $organ;
                    }
                    break;
                case self::STATUS_DEGRADED:
                    $degraded[] = [
                        'organ' => $organ,
                        'reason' => (string) ($row['reason'] ?? 'degraded_no_reason'),
                    ];
                    $nextRequired[] = $organ;
                    break;
                default:
                    $missing[] = $organ;
                    $nextRequired[] = $organ;
            }
        }

        $total = count(self::CANONICAL_ORGANS);

        $readinessRatio = $total > 0 ? round(count($ready) / $total, 4) : 0.0;

        $topBlockers = [];
        foreach ($blocked as $b) {
            foreach ((array) $b['blockers'] as $blocker) {
                $topBlockers[] = (string) $blocker;
            }
        }
        $topBlockers = array_values(array_unique($topBlockers));
        sort($topBlockers, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'all_ready' => $blocked === [] && $missing === [] && $degraded === [],
            'ready_organs' => $ready,
            'blocked_organs' => $blocked,
            'degraded_organs' => $degraded,
            'missing_organs' => $missing,
            'next_required_organs' => array_values(array_unique($nextRequired)),
            'readiness_ratio' => $readinessRatio,
            'top_blockers' => $topBlockers,
        ];
    }

    /** @return list<string> */
    private function evidenceFloorBlockers(array $row): array
    {
        $blockers = [];
        $refs = array_values(array_filter(array_map('strval', (array) ($row['evidence_refs'] ?? []))));
        if ($refs === []) {
            $blockers[] = 'evidence_floor_missing:evidence_refs';
        }
        if (trim((string) ($row['last_verified_at'] ?? '')) === '') {
            $blockers[] = 'evidence_floor_missing:last_verified_at';
        }
        if (! array_key_exists('freshness_status', $row)) {
            $blockers[] = 'evidence_floor_missing:freshness_status';
        }

        return $blockers;
    }
}
