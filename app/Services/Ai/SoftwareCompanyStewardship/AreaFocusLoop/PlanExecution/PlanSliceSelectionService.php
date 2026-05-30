<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Pilar 1 · Plan Execution · next-ready-slice selection.
 *
 * Pure read over a decomposed_plan.v1 + a plan_completion_ledger.v1 rollup. Picks the
 * earliest-in-sequence slice that is (a) not yet delivered and (b) has all its
 * dependencies delivered. This is what the plan-driven loop runner builds next.
 *
 * Honest terminal states (never fabricated):
 *  - KIND_PLAN_COMPLETE : every slice delivered.
 *  - KIND_SLICE_READY   : a buildable slice exists now.
 *  - KIND_BLOCKED       : undelivered slices remain but none is ready (a slice is hard-
 *                         blocked, or the only undelivered slices are waiting on
 *                         predecessors that are themselves not progressing).
 *  - KIND_EMPTY_PLAN    : the plan has no slices.
 */
final class PlanSliceSelectionService
{
    public const SELECTION_SCHEMA = 'atlas.plan_execution.slice_selection.v1';

    public const KIND_SLICE_READY = 'slice_ready';

    public const KIND_PLAN_COMPLETE = 'plan_complete';

    public const KIND_BLOCKED = 'blocked';

    public const KIND_EMPTY_PLAN = 'empty_plan';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $decomposedPlan  decomposed_plan.v1
     * @param  array<string,mixed>  $rollup          plan_completion_ledger.v1 rollup
     * @return array<string,mixed>                   slice_selection.v1
     */
    public function selectNext(array $decomposedPlan, array $rollup): array
    {
        $slices = $this->orderedSlices($decomposedPlan);
        $sliceStates = is_array($rollup['slice_states'] ?? null) ? $rollup['slice_states'] : [];

        if ($slices === []) {
            return $this->result(self::KIND_EMPTY_PLAN, null, 'plan_has_no_slices', $decomposedPlan);
        }

        $undelivered = 0;
        $hardBlocked = [];
        foreach ($slices as $slice) {
            $sid = (string) $slice['slice_id'];
            $row = is_array($sliceStates[$sid] ?? null) ? $sliceStates[$sid] : [];
            $state = (string) ($row['state'] ?? 'planned');

            if ($state === self::STATE_DELIVERED) {
                continue;
            }
            $undelivered++;
            if ($state === self::STATE_BLOCKED) {
                $hardBlocked[] = $sid;
            }

            // Trust the rollup's dependency gate when present; otherwise compute it.
            $depsSatisfied = array_key_exists('dependency_satisfied', $row)
                ? (bool) $row['dependency_satisfied']
                : $this->dependenciesDelivered($slice, $sliceStates);

            if ($state !== self::STATE_BLOCKED && $depsSatisfied) {
                return $this->result(self::KIND_SLICE_READY, $slice, 'slice_ready', $decomposedPlan);
            }
        }

        if ($undelivered === 0) {
            return $this->result(self::KIND_PLAN_COMPLETE, null, 'all_slices_delivered', $decomposedPlan);
        }

        $reason = $hardBlocked !== []
            ? 'slice_blocked:'.implode(',', $hardBlocked)
            : 'no_ready_slice_dependency_wait';

        return $this->result(self::KIND_BLOCKED, null, $reason, $decomposedPlan);
    }

    /**
     * @param  array<string,mixed>  $decomposedPlan
     * @return list<array<string,mixed>>
     */
    private function orderedSlices(array $decomposedPlan): array
    {
        $slices = is_array($decomposedPlan['slices'] ?? null) ? $decomposedPlan['slices'] : [];
        $clean = [];
        foreach ($slices as $slice) {
            if (is_array($slice) && (string) ($slice['slice_id'] ?? '') !== '') {
                $clean[] = $slice;
            }
        }
        usort($clean, static function (array $a, array $b): int {
            return ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0));
        });

        return $clean;
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $sliceStates
     */
    private function dependenciesDelivered(array $slice, array $sliceStates): bool
    {
        foreach ((array) ($slice['depends_on'] ?? []) as $dep) {
            $depId = (string) $dep;
            if ($depId === '') {
                continue;
            }
            $depRow = is_array($sliceStates[$depId] ?? null) ? $sliceStates[$depId] : [];
            if ((string) ($depRow['state'] ?? 'planned') !== self::STATE_DELIVERED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>|null  $slice
     * @param  array<string,mixed>  $decomposedPlan
     * @return array<string,mixed>
     */
    private function result(string $kind, ?array $slice, string $reason, array $decomposedPlan): array
    {
        return [
            'schema_version' => self::SELECTION_SCHEMA,
            'kind' => $kind,
            'reason' => $reason,
            'plan_id' => (string) ($decomposedPlan['plan_id'] ?? ''),
            'slice_id' => $slice !== null ? (string) ($slice['slice_id'] ?? '') : null,
            'finding_id' => $slice !== null ? (string) ($slice['finding_id'] ?? ($slice['slice_id'] ?? '')) : null,
            'slice' => $slice,
        ];
    }
}
