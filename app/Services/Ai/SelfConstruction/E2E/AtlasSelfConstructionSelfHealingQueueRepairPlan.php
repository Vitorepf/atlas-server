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

    /**
     * @param  list<array{packet_id?:string, malformed?:bool, repeated_returns?:int, missing_dependency?:string, stuck?:bool, scope_repaired?:bool, emergency_kind?:string}>  $packets
     * @return array{schema:string, actions:list<array{bucket:string, packet_id:string, action:string, reason:string}>, summary:array<string,int>}
     */
    public function plan(array $packets): array
    {
        $actions = [];
        $summary = [];

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
