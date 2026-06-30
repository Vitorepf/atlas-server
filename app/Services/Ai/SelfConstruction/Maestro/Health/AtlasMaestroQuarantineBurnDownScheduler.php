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
}
