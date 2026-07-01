<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\E2E;

/**
 * Self-healing queue REPAIR PLANNER. Classifies queue packets into buckets so the queue can heal
 * itself (Atlas-native next tasks) without permanent human rescue. EMERGENCY bucket is operator
 * VISIBILITY only — operator does not approve normal repairs.
 *
 * BUCKETS (per packet):
 *   quarantine          — repeated returns >= QUARANTINE_THRESHOLD
 *   template_repair     — malformed packet (missing required template fields)
 *   dependency_rewrite  — dependency missing or stuck
 *   scope_repair_done   — flagged as already scope-repaired (no action needed; deterministic noop)
 *   operator_visible    — operator-emergency (e.g. constitution edit attempt) — VISIBILITY ONLY
 *
 * INPUT (per packet):
 *   { packet_id, malformed?:bool, repeated_returns?:int, missing_dependency?:string,
 *     stuck?:bool, scope_repaired?:bool, emergency_kind?:string }
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (actions sorted by bucket+packet_id).
 *   - NORMAL repair actions are Atlas-native next tasks (cancel / rewrite / new_packet).
 *   - operator_visible bucket carries action='visibility_only' (NO automatic action).
 *   - PURE — no I/O.
 */
final class AtlasSelfConstructionSelfHealingQueueRepairPlan
{
    public const SCHEMA = 'atlas.selfconstruction.queue_repair_plan.v1';

    public const QUARANTINE_THRESHOLD = 3;

    public const BUCKET_QUARANTINE = 'quarantine';

    public const BUCKET_TEMPLATE = 'template_repair';

    public const BUCKET_DEPENDENCY = 'dependency_rewrite';

    public const BUCKET_SCOPE_REPAIRED = 'scope_repair_done';

    public const BUCKET_OPERATOR_VISIBLE = 'operator_visible';

    public const BUCKET_TOP_UP = 'queue_top_up';

    public const BUCKET_RESPEC = 'respec';

    public const GIVE_BACK_RESPEC_THRESHOLD = 3;

    public const BUCKET_REFUSED = 'refused';

    public const BUCKET_RETIRE = 'retire';

    public const BUCKET_REQUEUE = 'requeue';

    /** malformed packets that survived this many prior repair attempts are respec-or-retired, not re-templated forever. */
    public const MALFORMED_RESPEC_OR_RETIRE_THRESHOLD = 2;

    /** claimable_per_active_worker at/below this is a worker-floor breach. */
    public const WORKER_FLOOR_THRESHOLD = 2.0;

    /**
     * @param  list<array{packet_id?:string, malformed?:bool, malformed_repair_attempts?:int, repeated_returns?:int, missing_dependency?:string, stuck?:bool, scope_repaired?:bool, emergency_kind?:string, recoverable_blocked_family?:string, has_implementation_scope?:bool, has_runnable_acceptance?:bool, prefer_top_up?:bool, duplicate_of?:string, is_stale?:bool, stale_reason?:string, released_recoverable?:bool}>  $packets
     * @param  array{claimable_per_active_worker?: float}  $context
     * @return array{schema:string, actions:list<array{bucket:string, packet_id:string, action:string, reason:string}>, summary:array<string,int>}
     */
    public function plan(array $packets, array $context = []): array
    {
        $actions = [];
        $summary = [];

        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $context) && $context['claimable_per_active_worker'] !== null
            ? (float) $context['claimable_per_active_worker']
            : null;
        $lowWorkerFloor = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::WORKER_FLOOR_THRESHOLD;

        foreach ($packets as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = trim((string) ($p['packet_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            // EMERGENCY — operator visibility only, highest precedence so it's never auto-handled.
            $emergency = trim((string) ($p['emergency_kind'] ?? ''));
            if ($emergency !== '') {
                $actions[] = ['bucket' => self::BUCKET_OPERATOR_VISIBLE, 'packet_id' => $id, 'action' => 'visibility_only', 'reason' => 'emergency:'.$emergency];
                $summary[self::BUCKET_OPERATOR_VISIBLE] = ($summary[self::BUCKET_OPERATOR_VISIBLE] ?? 0) + 1;

                continue;
            }

            // Duplicate or stale packets are dead weight — retire them with an explicit reason,
            // never silently re-serve. Checked before recoverable/quarantine signals since a
            // packet flagged duplicate/stale is terminal regardless of other symptoms.
            $duplicateOf = trim((string) ($p['duplicate_of'] ?? ''));
            if ($duplicateOf !== '') {
                $actions[] = ['bucket' => self::BUCKET_RETIRE, 'packet_id' => $id, 'action' => 'retire_packet', 'reason' => 'duplicate_of:'.$duplicateOf];
                $summary[self::BUCKET_RETIRE] = ($summary[self::BUCKET_RETIRE] ?? 0) + 1;

                continue;
            }
            if ((bool) ($p['is_stale'] ?? false)) {
                $staleReason = trim((string) ($p['stale_reason'] ?? ''));
                $actions[] = ['bucket' => self::BUCKET_RETIRE, 'packet_id' => $id, 'action' => 'retire_packet', 'reason' => 'stale'.($staleReason !== '' ? ':'.$staleReason : '')];
                $summary[self::BUCKET_RETIRE] = ($summary[self::BUCKET_RETIRE] ?? 0) + 1;

                continue;
            }

            // A recoverable packet whose lease was released (worker gave it back cleanly, not
            // poisoned) goes straight back into the claimable pool — Atlas-native, no respec needed.
            if ((bool) ($p['released_recoverable'] ?? false)) {
                $actions[] = ['bucket' => self::BUCKET_REQUEUE, 'packet_id' => $id, 'action' => 'requeue_packet', 'reason' => 'recoverable_lease_released'];
                $summary[self::BUCKET_REQUEUE] = ($summary[self::BUCKET_REQUEUE] ?? 0) + 1;

                continue;
            }

            // Malformed packets that have already exhausted repeated template-repair attempts
            // are respec-or-retired instead of being re-templated forever (fail closed, never
            // muscle-served): >= threshold prior attempts routes here; below threshold falls
            // through unchanged to the existing template_repair path.
            $malformedAttempts = (int) ($p['malformed_repair_attempts'] ?? 0);
            if ((bool) ($p['malformed'] ?? false) && $malformedAttempts >= self::MALFORMED_RESPEC_OR_RETIRE_THRESHOLD) {
                $actions[] = ['bucket' => self::BUCKET_RESPEC, 'packet_id' => $id, 'action' => 'enqueue_respec_packet', 'reason' => 'malformed_repair_exhausted_respec_or_retire:'.$malformedAttempts];
                $summary[self::BUCKET_RESPEC] = ($summary[self::BUCKET_RESPEC] ?? 0) + 1;

                continue;
            }

            // Low claimable_per_active_worker: convert a RECOVERABLE blocked family into a
            // concrete respec/top-up repair plan — but fail closed (refuse, never guess) when
            // the packet has no implementation scope or no runnable acceptance to repair from.
            $recoverableFamily = trim((string) ($p['recoverable_blocked_family'] ?? ''));
            if ($lowWorkerFloor && $recoverableFamily !== '') {
                $hasImplementationScope = (bool) ($p['has_implementation_scope'] ?? false);
                $hasRunnableAcceptance = (bool) ($p['has_runnable_acceptance'] ?? false);

                if (! $hasImplementationScope || ! $hasRunnableAcceptance) {
                    $actions[] = [
                        'bucket' => self::BUCKET_REFUSED,
                        'packet_id' => $id,
                        'action' => 'refuse_repair',
                        'reason' => 'missing_implementation_scope_or_runnable_acceptance',
                    ];
                    $summary[self::BUCKET_REFUSED] = ($summary[self::BUCKET_REFUSED] ?? 0) + 1;

                    continue;
                }

                $preferTopUp = (bool) ($p['prefer_top_up'] ?? false);
                $bucket = $preferTopUp ? self::BUCKET_TOP_UP : self::BUCKET_RESPEC;
                $action = $preferTopUp ? 'enqueue_top_up_packet' : 'enqueue_respec_packet';
                $actions[] = [
                    'bucket' => $bucket,
                    'packet_id' => $id,
                    'action' => $action,
                    'reason' => 'worker_floor_low_recoverable_family:'.$recoverableFamily,
                ];
                $summary[$bucket] = ($summary[$bucket] ?? 0) + 1;

                continue;
            }

            // Low servable depth: Atlas-native top-up, no operator required.
            if ((bool) ($p['low_servable_depth'] ?? false)) {
                $actions[] = ['bucket' => self::BUCKET_TOP_UP, 'packet_id' => $id, 'action' => 'enqueue_top_up_packet', 'reason' => 'low_servable_depth'];
                $summary[self::BUCKET_TOP_UP] = ($summary[self::BUCKET_TOP_UP] ?? 0) + 1;

                continue;
            }

            // Repeated give_back family: respec the family to unblock the queue.
            $giveBackFamily = trim((string) ($p['give_back_family'] ?? ''));
            $repeatedGiveBacks = (int) ($p['repeated_give_backs'] ?? 0);
            if ($giveBackFamily !== '' && $repeatedGiveBacks >= self::GIVE_BACK_RESPEC_THRESHOLD) {
                $actions[] = ['bucket' => self::BUCKET_RESPEC, 'packet_id' => $id, 'action' => 'enqueue_respec_packet', 'reason' => 'give_back_family:'.$giveBackFamily.':count:'.$repeatedGiveBacks];
                $summary[self::BUCKET_RESPEC] = ($summary[self::BUCKET_RESPEC] ?? 0) + 1;

                continue;
            }

            $repeated = (int) ($p['repeated_returns'] ?? 0);
            if ($repeated >= self::QUARANTINE_THRESHOLD) {
                $actions[] = ['bucket' => self::BUCKET_QUARANTINE, 'packet_id' => $id, 'action' => 'cancel_until_respec', 'reason' => 'repeated_returns:'.$repeated];
                $summary[self::BUCKET_QUARANTINE] = ($summary[self::BUCKET_QUARANTINE] ?? 0) + 1;

                continue;
            }

            if ((bool) ($p['malformed'] ?? false)) {
                $actions[] = ['bucket' => self::BUCKET_TEMPLATE, 'packet_id' => $id, 'action' => 'enqueue_template_repair_packet', 'reason' => 'malformed_packet_template'];
                $summary[self::BUCKET_TEMPLATE] = ($summary[self::BUCKET_TEMPLATE] ?? 0) + 1;

                continue;
            }

            $missingDep = (string) ($p['missing_dependency'] ?? '');
            $stuck = (bool) ($p['stuck'] ?? false);
            if ($missingDep !== '' || $stuck) {
                $reason = $missingDep !== '' ? 'missing_dependency:'.$missingDep : 'stuck';
                $actions[] = ['bucket' => self::BUCKET_DEPENDENCY, 'packet_id' => $id, 'action' => 'enqueue_dependency_rewrite_packet', 'reason' => $reason];
                $summary[self::BUCKET_DEPENDENCY] = ($summary[self::BUCKET_DEPENDENCY] ?? 0) + 1;

                continue;
            }

            if ((bool) ($p['scope_repaired'] ?? false)) {
                $actions[] = ['bucket' => self::BUCKET_SCOPE_REPAIRED, 'packet_id' => $id, 'action' => 'no_action', 'reason' => 'scope_already_repaired'];
                $summary[self::BUCKET_SCOPE_REPAIRED] = ($summary[self::BUCKET_SCOPE_REPAIRED] ?? 0) + 1;
            }
        }

        usort($actions, static fn (array $a, array $b): int => strcmp($a['bucket'].'|'.$a['packet_id'], $b['bucket'].'|'.$b['packet_id']));
        ksort($summary);

        return [
            'schema' => self::SCHEMA,
            'actions' => $actions,
            'summary' => $summary,
        ];
    }
}
