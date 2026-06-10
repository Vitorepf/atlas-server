<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S155 - L10 R2 long-horizon telos execution-correction planner.
 *
 * Once the operator has CURATED an engineering telos (the multi-year ends), the
 * system is allowed to self-correct the PATH toward it from measured evidence -
 * but it must never edit the ends themselves and it must never execute the
 * correction. This planner reads the curated telos together with measured
 * outcomes and emits correction packets that say "move this target back toward
 * its curated goal, then measure-or-revert". It is pure and read-only: it never
 * touches a provider, never writes state and never performs the corrections it
 * proposes. The packets are proposals only.
 *
 * Blocker rules, applied in this fixed order (fail-closed first):
 *   1. Missing curated telos        -> curated_telos_missing
 *   2. Correction changes final ends -> correction_changes_final_ends
 *   3. Execution side effect asked   -> execution_side_effect_requested
 *
 * drift_from_telos is the fraction (0..1) of the curated telos' target outcomes
 * whose latest measured value is below its curated goal. Each below-goal target
 * yields exactly one correction packet; on-goal targets yield none. When any
 * blocker fires no packet is produced. measured_or_reverted_required is always
 * true: every proposed correction must later be proven by measurement or
 * reverted, mirroring the L8 measured-or-reverted discipline.
 */
final class L10TelosExecutionCorrectionPlanner
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.telos_execution_correction_plan.v1';

    private const BLOCKER_CURATED_TELOS_MISSING = 'curated_telos_missing';

    private const BLOCKER_CORRECTION_CHANGES_FINAL_ENDS = 'correction_changes_final_ends';

    private const BLOCKER_EXECUTION_SIDE_EFFECT_REQUESTED = 'execution_side_effect_requested';

    /**
     * Plan evidence-based corrections toward a curated engineering telos.
     *
     * @param  array<string, mixed>  $telos
     * @param  array<string, mixed>  $outcomes
     * @return array{
     *     schema_version: string,
     *     correction_packets: list<array{target_id: string, goal: float, measured: float, gap: float, action: string, changes_final_ends: false, measured_or_reverted_required: true}>,
     *     drift_from_telos: float,
     *     measured_or_reverted_required: true,
     *     blockers: list<string>
     * }
     */
    public function plan(array $telos, array $outcomes): array
    {
        $targets = $this->curatedTargets($telos);
        $measuredByTarget = $this->measuredByTarget($outcomes);

        $blockers = [];

        if (! $this->hasCuratedTelos($telos)) {
            $blockers[] = self::BLOCKER_CURATED_TELOS_MISSING;
        }

        if ($this->correctionChangesFinalEnds($telos, $outcomes)) {
            $blockers[] = self::BLOCKER_CORRECTION_CHANGES_FINAL_ENDS;
        }

        if ($this->executionSideEffectRequested($outcomes)) {
            $blockers[] = self::BLOCKER_EXECUTION_SIDE_EFFECT_REQUESTED;
        }

        $drift = $this->driftFromTelos($targets, $measuredByTarget);

        $correctionPackets = $blockers === []
            ? $this->correctionPackets($targets, $measuredByTarget)
            : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'correction_packets' => $correctionPackets,
            'drift_from_telos' => $drift,
            'measured_or_reverted_required' => true,
            'blockers' => $blockers,
        ];
    }

    /**
     * A curated telos exists only with an explicit curated_telos_id and an
     * explicit curation flag. Either missing leaves the path uncorrectable.
     *
     * @param  array<string, mixed>  $telos
     */
    private function hasCuratedTelos(array $telos): bool
    {
        $id = $telos['curated_telos_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return false;
        }

        return ($telos['operator_curated'] ?? false) === true
            || ($telos['curated'] ?? false) === true;
    }

    /**
     * The curated, immutable ends of the telos: a normalised set of final-ends
     * identifiers the operator owns. Corrections may never add, drop or rewrite
     * any of them.
     *
     * @param  array<string, mixed>  $telos
     * @return list<string>
     */
    private function finalEnds(array $telos): array
    {
        return AreaFocusStringListNormalizer::preserveStrings($telos['final_ends'] ?? []);
    }

    /**
     * Detect a correction that would change the curated final ends. A correction
     * changes ends when an outcome carries a proposed_final_ends set that differs
     * from the telos' curated final ends, or when it explicitly flags an ends
     * mutation. Path/value corrections never set these and are allowed.
     *
     * @param  array<string, mixed>  $telos
     * @param  array<string, mixed>  $outcomes
     */
    private function correctionChangesFinalEnds(array $telos, array $outcomes): bool
    {
        $curatedEnds = $this->finalEnds($telos);

        foreach ($this->records($outcomes) as $record) {
            if (($record['changes_final_ends'] ?? false) === true) {
                return true;
            }

            if (($record['mutates_final_ends'] ?? false) === true) {
                return true;
            }

            if (! array_key_exists('proposed_final_ends', $record)) {
                continue;
            }

            $proposed = AreaFocusStringListNormalizer::preserveStrings($record['proposed_final_ends']);
            if ($this->endsDiffer($curatedEnds, $proposed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two final-ends sets differ when they do not contain exactly the same
     * identifiers (order-independent). A proposal that restates the curated ends
     * verbatim is not a change.
     *
     * @param  list<string>  $curated
     * @param  list<string>  $proposed
     */
    private function endsDiffer(array $curated, array $proposed): bool
    {
        $left = AreaFocusStringListNormalizer::uniqueStringValues($curated);
        $right = AreaFocusStringListNormalizer::uniqueStringValues($proposed);

        sort($left);
        sort($right);

        return $left !== $right;
    }

    /**
     * This planner is forbidden from producing execution. An outcome that asks
     * the planner to execute, apply or auto-run the correction (rather than just
     * plan it) is rejected so no execution side effect can leak in.
     *
     * @param  array<string, mixed>  $outcomes
     */
    private function executionSideEffectRequested(array $outcomes): bool
    {
        if (($outcomes['execute_corrections'] ?? false) === true) {
            return true;
        }

        if (($outcomes['apply_now'] ?? false) === true) {
            return true;
        }

        foreach ($this->records($outcomes) as $record) {
            if (($record['execute'] ?? false) === true) {
                return true;
            }

            if (($record['apply_now'] ?? false) === true) {
                return true;
            }

            $mode = $record['mode'] ?? null;
            if (is_string($mode) && ($mode === 'execute' || $mode === 'apply')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Curated target outcomes of the telos, each a goal value to converge on.
     * Targets without a usable identifier are ignored. Keys are kept as explicit
     * strings so the list<string> ordering contract is never broken by int key
     * coercion.
     *
     * @param  array<string, mixed>  $telos
     * @return list<array{target_id: string, goal: float}>
     */
    private function curatedTargets(array $telos): array
    {
        $raw = $telos['target_outcomes'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $targets = [];
        foreach ($raw as $key => $value) {
            $targetId = $this->targetId($key, $value);
            if ($targetId === '') {
                continue;
            }

            $goal = $this->goalValue($key, $value);
            if ($goal === null) {
                continue;
            }

            $targets[] = [
                'target_id' => $targetId,
                'goal' => $goal,
            ];
        }

        return $targets;
    }

    /**
     * Latest measured value per target id from the outcomes evidence. A later
     * record for the same target id overwrites an earlier one.
     *
     * @param  array<string, mixed>  $outcomes
     * @return array<string, float>
     */
    private function measuredByTarget(array $outcomes): array
    {
        $measured = [];
        foreach ($this->records($outcomes) as $record) {
            $targetId = $this->targetId(null, $record);
            if ($targetId === '') {
                continue;
            }

            $value = $this->measuredValue($record);
            if ($value === null) {
                continue;
            }

            $measured[$targetId] = $value;
        }

        return $measured;
    }

    /**
     * drift_from_telos: the fraction of curated targets whose latest measured
     * value is strictly below its goal. A target with no measurement counts as
     * fully drifted (the path is unproven there). Always within [0.0, 1.0].
     *
     * @param  list<array{target_id: string, goal: float}>  $targets
     * @param  array<string, float>  $measuredByTarget
     */
    private function driftFromTelos(array $targets, array $measuredByTarget): float
    {
        $total = count($targets);
        if ($total === 0) {
            return 0.0;
        }

        $drifting = 0;
        foreach ($targets as $target) {
            if ($this->targetIsBelowGoal($target, $measuredByTarget)) {
                $drifting++;
            }
        }

        $drift = $drifting / $total;

        return AreaFocusScalarNormalizer::clampUnit($drift);
    }

    /**
     * One correction packet per below-goal target, ordered as the targets are
     * declared. Each packet proposes nudging the target back toward its curated
     * goal and is explicitly marked as not changing the ends and as requiring
     * measure-or-revert. On-goal targets produce no packet.
     *
     * @param  list<array{target_id: string, goal: float}>  $targets
     * @param  array<string, float>  $measuredByTarget
     * @return list<array{target_id: string, goal: float, measured: float, gap: float, action: string, changes_final_ends: false, measured_or_reverted_required: true}>
     */
    private function correctionPackets(array $targets, array $measuredByTarget): array
    {
        $packets = [];
        foreach ($targets as $target) {
            if (! $this->targetIsBelowGoal($target, $measuredByTarget)) {
                continue;
            }

            $goal = $target['goal'];
            $measured = $measuredByTarget[$target['target_id']] ?? 0.0;
            $gap = $goal - $measured;

            $packets[] = [
                'target_id' => $target['target_id'],
                'goal' => $goal,
                'measured' => $measured,
                'gap' => $gap,
                'action' => 'correct_path_toward_goal',
                'changes_final_ends' => false,
                'measured_or_reverted_required' => true,
            ];
        }

        return $packets;
    }

    /**
     * A target is below goal when it has no measurement at all, or its latest
     * measured value is strictly below the curated goal.
     *
     * @param  array{target_id: string, goal: float}  $target
     * @param  array<string, float>  $measuredByTarget
     */
    private function targetIsBelowGoal(array $target, array $measuredByTarget): bool
    {
        if (! array_key_exists($target['target_id'], $measuredByTarget)) {
            return true;
        }

        return $measuredByTarget[$target['target_id']] < $target['goal'];
    }

    /**
     * Normalise an outcomes payload to a list of record arrays. Accepts a bare
     * list of records or an outcomes.records nesting.
     *
     * @param  array<string, mixed>  $outcomes
     * @return list<array<string, mixed>>
     */
    private function records(array $outcomes): array
    {
        $raw = $outcomes['records'] ?? $outcomes;
        if (! is_array($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }

        return $records;
    }

    /**
     * Resolve the target identifier from a map key or a record's own fields.
     *
     * @param  int|string|null  $key
     * @param  array<string, mixed>|mixed  $value
     */
    private function targetId($key, $value): string
    {
        if (is_array($value)) {
            $candidate = $value['target_id'] ?? $value['id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        if (is_string($key) && $key !== '') {
            return $key;
        }

        return '';
    }

    /**
     * Resolve a curated goal value from a map entry. A scalar map value is the
     * goal directly; an array entry carries an explicit goal/target field.
     *
     * @param  int|string  $key
     * @param  array<string, mixed>|mixed  $value
     */
    private function goalValue($key, $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return $this->floatOrNull($value['goal'] ?? $value['target'] ?? null);
        }

        return null;
    }

    /**
     * Latest measured value carried by an outcome record.
     *
     * @param  array<string, mixed>  $record
     */
    private function measuredValue(array $record): ?float
    {
        return $this->floatOrNull(
            $record['measured'] ?? $record['value'] ?? $record['latest'] ?? null
        );
    }

    /**
     * @param  mixed  $value
     */
    private function floatOrNull($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
