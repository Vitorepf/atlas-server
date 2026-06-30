<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Pure compiler. Produces a 24/7 autonomy soak plan from a runtime snapshot.
 *
 * Standard steady-state criteria evaluated:
 *   queue_pressure_below_threshold    — pressure < 0.60
 *   poison_ratio_below_threshold      — poison_ratio < 0.20
 *   no_zombie_workers                 — zombie_count === 0
 *   at_least_one_active_worker        — active_workers >= 1
 *   auto_merge_enabled                — auto_merge_enabled === true
 *   merge_success_rate_above_threshold — merge_success_rate >= 0.90
 *
 * Soak parameters:
 *   All pass → 24 h / 50-task green streak.
 *   Any warn → 48 h / 100-task streak.
 *   Any fail  → 72 h / 200-task streak (not ready).
 *
 * AC2 — fail-closed on human dependency:
 *   Any entry in criteria_overrides whose name/text contains a human-dependency
 *   keyword (human, operator, manual, external_provider, requires_approval) is
 *   immediately classified as requires_human=true, added to disqualifiers, and
 *   sets fail_closed_reason. The soak is NOT ready when fail_closed_reason != null.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasSelfConstructionAutonomySoakPlanCompiler
{
    public const SCHEMA = 'atlas.self_construction.completion.autonomy_soak_plan_compiler.v1';

    private const HUMAN_KEYWORDS = ['human', 'operator', 'manual', 'external_provider', 'requires_approval'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $queue  = is_array($facts['queue_health']  ?? null) ? $facts['queue_health']  : [];
        $worker = is_array($facts['worker_health'] ?? null) ? $facts['worker_health'] : [];
        $merge  = is_array($facts['merge_health']  ?? null) ? $facts['merge_health']  : [];

        $criteria      = [];
        $disqualifiers = [];

        // Standard criteria.
        $criteria[] = $this->crit('queue_pressure_below_threshold',
            (float) ($queue['pressure'] ?? 1.0) < 0.60);
        $criteria[] = $this->crit('poison_ratio_below_threshold',
            (float) ($queue['poison_ratio'] ?? 1.0) < 0.20);
        $criteria[] = $this->crit('no_zombie_workers',
            (int) ($worker['zombie_count'] ?? 1) === 0);
        $criteria[] = $this->crit('at_least_one_active_worker',
            (int) ($worker['active_workers'] ?? 0) >= 1);
        $criteria[] = $this->crit('auto_merge_enabled',
            (bool) ($merge['auto_merge_enabled'] ?? false));
        $criteria[] = $this->crit('merge_success_rate_above_threshold',
            (float) ($merge['merge_success_rate'] ?? 0.0) >= 0.90);

        // Collect standard failures into disqualifiers.
        foreach ($criteria as $c) {
            if ($c['status'] === 'fail') {
                $disqualifiers[] = 'criterion_failed:' . $c['criterion'];
            }
        }

        // Custom criteria — AC2 human-dependency check.
        $failClosedReason = null;
        foreach ((array) ($facts['criteria_overrides'] ?? []) as $cc) {
            $name         = strtolower(trim((string) ($cc['name'] ?? '')));
            $requiresHuman = $this->hasHumanDependency($name) || (bool) ($cc['requires_human'] ?? false);

            if ($requiresHuman) {
                $disqualifiers[]  = 'criterion_requires_human_dependency:' . $name;
                $failClosedReason = $failClosedReason ?? "criterion '$name' requires human or external intervention";
                $criteria[]       = ['criterion' => $name, 'status' => 'fail', 'requires_human' => true];
            } else {
                $criteria[] = $this->crit($name, (bool) ($cc['passing'] ?? false));
            }
        }

        $failCount = count(array_filter($criteria, static fn (array $c): bool => $c['status'] === 'fail'));
        $warnCount = count(array_filter($criteria, static fn (array $c): bool => $c['status'] === 'warn'));

        [$soakHours, $greenStreak] = $this->soakParams($failCount, $warnCount, $failClosedReason !== null);

        return [
            'schema_version'               => self::SCHEMA,
            'soak_duration_hours'          => $soakHours,
            'required_green_streak_tasks'  => $greenStreak,
            'steady_state_criteria'        => $criteria,
            'disqualifiers'                => $disqualifiers,
            'is_soak_ready'                => $failCount === 0 && $failClosedReason === null,
            'fail_closed_reason'           => $failClosedReason,
        ];
    }

    /** @return array<string,mixed> */
    private function crit(string $criterion, bool $passing): array
    {
        return ['criterion' => $criterion, 'status' => $passing ? 'pass' : 'fail', 'requires_human' => false];
    }

    /** @return array{float, int} */
    private function soakParams(int $failCount, int $warnCount, bool $forcedClose): array
    {
        if ($forcedClose || $failCount > 0) {
            return [72.0, 200];
        }
        if ($warnCount > 0) {
            return [48.0, 100];
        }

        return [24.0, 50];
    }

    private function hasHumanDependency(string $text): bool
    {
        foreach (self::HUMAN_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }
}
