<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Groups blocked or quarantined task packets into four lanes with bounded next actions so muscles
 * do not waste another lease on permanently broken packets.
 *
 * LANES (classified in priority order: retire > operator_only > respec > wait):
 *   retire        — permanently broken; must NOT be requeued
 *                   triggers: is_self_target OR poison_attempt_count >= POISON_THRESHOLD
 *   operator_only — requires human decision; not automatable
 *                   triggers: requires_operator_decision OR has_sensitive_data
 *   respec        — needs a spec rewrite before it can be attempted
 *                   triggers: spec_is_ambiguous OR out_of_scope_files
 *   wait          — temporarily blocked; retry when dependency resolves
 *
 * INPUT: list<packet>
 *   packet:
 *     packet_id:                  string
 *     is_self_target?:            bool   (default false)
 *     poison_attempt_count?:      int    (default 0)
 *     requires_operator_decision?: bool  (default false)
 *     has_sensitive_data?:        bool   (default false)
 *     spec_is_ambiguous?:         bool   (default false)
 *     out_of_scope_files?:        bool   (default false)
 *     blocker_description?:       string
 *
 * OUTPUT:
 *   { schema, lanes:{ retire, operator_only, respec, wait },
 *     total_quarantined, do_not_requeue_count }
 *
 *   Each lane entry: { packet_id, do_not_requeue:bool, reason:string, next_action:string }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasMaestroQuarantineBurnDownScheduler
{
    public const SCHEMA = 'atlas.maestro.health.quarantine_burn_down_scheduler.v1';

    public const LANE_RETIRE        = 'retire';
    public const LANE_OPERATOR_ONLY = 'operator_only';
    public const LANE_RESPEC        = 'respec';
    public const LANE_WAIT          = 'wait';

    /** Number of poison attempts beyond which a packet is retired. */
    public const POISON_THRESHOLD = 3;

    /** Weight applied to unblock_leverage when ranking families for burn-down — a small,
     *  low-recovery family that unblocks a lot of downstream work can still outrank a large,
     *  low-value family with no leverage. */
    private const LEVERAGE_WEIGHT = 0.5;

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,mixed>
     */
    public function schedule(array $packets): array
    {
        $lanes = [
            self::LANE_RETIRE        => [],
            self::LANE_OPERATOR_ONLY => [],
            self::LANE_RESPEC        => [],
            self::LANE_WAIT          => [],
        ];

        $doNotRequeueCount = 0;

        foreach ($packets as $packet) {
            if (! is_array($packet) || ! isset($packet['packet_id'])) {
                continue;
            }

            $packetId      = (string) $packet['packet_id'];
            $isSelfTarget  = (bool) ($packet['is_self_target'] ?? false);
            $poisonCount   = max(0, (int) ($packet['poison_attempt_count'] ?? 0));
            $needsOperator = (bool) ($packet['requires_operator_decision'] ?? false);
            $hasSensitive  = (bool) ($packet['has_sensitive_data'] ?? false);
            $specAmbiguous = (bool) ($packet['spec_is_ambiguous'] ?? false);
            $outOfScope    = (bool) ($packet['out_of_scope_files'] ?? false);
            $blockerDesc   = (string) ($packet['blocker_description'] ?? '');

            // Priority 1: retire.
            if ($isSelfTarget || $poisonCount >= self::POISON_THRESHOLD) {
                $reason = $isSelfTarget
                    ? 'forbidden_self_target'
                    : sprintf('repeated_poison:attempts=%d', $poisonCount);

                $lanes[self::LANE_RETIRE][] = [
                    'packet_id'       => $packetId,
                    'do_not_requeue'  => true,
                    'reason'          => $reason,
                    'next_action'     => 'atlas:task:retire --packet='.$packetId,
                ];
                $doNotRequeueCount++;
                continue;
            }

            // Priority 2: operator_only.
            if ($needsOperator || $hasSensitive) {
                $reason = $needsOperator ? 'requires_operator_decision' : 'contains_sensitive_data';
                $lanes[self::LANE_OPERATOR_ONLY][] = [
                    'packet_id'      => $packetId,
                    'do_not_requeue' => false,
                    'reason'         => $reason,
                    'next_action'    => 'atlas:notify:operator --packet='.$packetId,
                ];
                continue;
            }

            // Priority 3: respec.
            if ($specAmbiguous || $outOfScope) {
                $reason = $specAmbiguous ? 'spec_is_ambiguous' : 'out_of_scope_files_in_spec';
                $lanes[self::LANE_RESPEC][] = [
                    'packet_id'      => $packetId,
                    'do_not_requeue' => false,
                    'reason'         => $reason,
                    'next_action'    => 'atlas:task:respec --packet='.$packetId,
                ];
                continue;
            }

            // Default: wait.
            $waitAction = $blockerDesc !== ''
                ? 'atlas:task:wait --packet='.$packetId.' --until='.str_replace(' ', '_', $blockerDesc)
                : 'atlas:task:wait --packet='.$packetId;

            $lanes[self::LANE_WAIT][] = [
                'packet_id'      => $packetId,
                'do_not_requeue' => false,
                'reason'         => $blockerDesc !== '' ? 'dependency_not_ready:'.$blockerDesc : 'temporarily_blocked',
                'next_action'    => $waitAction,
            ];
        }

        $total = array_sum(array_map('count', $lanes));

        return [
            'schema'               => self::SCHEMA,
            'lanes'                => $lanes,
            'total_quarantined'    => $total,
            'do_not_requeue_count' => $doNotRequeueCount,
        ];
    }

    /**
     * Rank quarantined packet FAMILIES by expected claimable recovery — packet_count weighted by
     * recovery_confidence — instead of raw poison count, so a small high-confidence family outranks
     * a large low-confidence one. Families that cannot safely produce a claimable replacement task
     * (poisoned beyond threshold, or zero recovery confidence) get a retire action instead of respec.
     *
     * @param  list<array{family_id?:string, packet_count?:int, poison_count?:int, recovery_confidence?:float, safe_to_respec?:bool, unblock_leverage?:float}>  $families
     * @return array{schema:string, ranked:list<array<string,mixed>>}
     */
    public function rankFamiliesByClaimableRecovery(array $families): array
    {
        $ranked = [];

        foreach ($families as $family) {
            if (! is_array($family) || ! isset($family['family_id'])) {
                continue;
            }

            $familyId = (string) $family['family_id'];
            $packetCount = max(0, (int) ($family['packet_count'] ?? 0));
            $poisonCount = max(0, (int) ($family['poison_count'] ?? 0));
            $recoveryConfidence = max(0.0, min(1.0, (float) ($family['recovery_confidence'] ?? 0.0)));
            $safeToRespec = (bool) ($family['safe_to_respec'] ?? true);
            $unblockLeverage = max(0.0, (float) ($family['unblock_leverage'] ?? 0.0));

            $expectedClaimableRecovery = round($packetCount * $recoveryConfidence, 4);
            // Ranking score: raw expected recovery plus a leverage bonus, so a small family that
            // unblocks a lot of downstream work can outrank a larger, low-leverage family.
            $combinedScore = round($expectedClaimableRecovery + $unblockLeverage * self::LEVERAGE_WEIGHT, 4);

            $canRecover = $safeToRespec && $poisonCount < self::POISON_THRESHOLD && $recoveryConfidence > 0.0;

            $ranked[] = [
                'family_id'                   => $familyId,
                'packet_count'                => $packetCount,
                'poison_count'                => $poisonCount,
                'recovery_confidence'         => $recoveryConfidence,
                'unblock_leverage'            => $unblockLeverage,
                'expected_claimable_recovery' => $expectedClaimableRecovery,
                'combined_score'              => $combinedScore,
                'action'                      => $canRecover ? 'respec' : 'retire',
                'reason'                      => $canRecover
                    ? 'recoverable_family:expected_claimable_recovery='.$expectedClaimableRecovery
                    : ($poisonCount >= self::POISON_THRESHOLD
                        ? 'repeated_poison:attempts='.$poisonCount
                        : ($recoveryConfidence <= 0.0 ? 'zero_recovery_confidence' : 'unsafe_to_respec')),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int =>
            $b['combined_score'] <=> $a['combined_score']
                ?: strcmp($a['family_id'], $b['family_id']));

        return [
            'schema' => self::SCHEMA,
            'ranked' => $ranked,
        ];
    }

    /**
     * Emit the burn_down_wave: for each ranked family, the coarse repair_strategy and risk level
     * a muscle needs to act on cleanup, without re-deriving the underlying ranking logic.
     *
     * @param  list<array{family_id?:string, packet_count?:int, poison_count?:int, recovery_confidence?:float, safe_to_respec?:bool, unblock_leverage?:float}>  $families
     * @return array{schema:string, burn_down_wave:list<array{family:string, repair_strategy:string, expected_recovered:float, risk:string}>}
     */
    public function planBurnDownWave(array $families): array
    {
        $ranked = $this->rankFamiliesByClaimableRecovery($families)['ranked'];

        $wave = [];
        foreach ($ranked as $r) {
            $repairStrategy = $r['action'] === 'retire' ? 'no_repair_retire' : 'respec_with_root_cause_fix';
            $risk = $r['poison_count'] >= self::POISON_THRESHOLD
                ? 'high'
                : ($r['recovery_confidence'] < 0.4 ? 'medium' : 'low');

            $wave[] = [
                'family'             => $r['family_id'],
                'repair_strategy'    => $repairStrategy,
                'expected_recovered' => $r['expected_claimable_recovery'],
                'risk'               => $risk,
            ];
        }

        return [
            'schema'         => self::SCHEMA,
            'burn_down_wave' => $wave,
        ];
    }
}
