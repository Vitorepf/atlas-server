<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * Anti-proxy gate. Blocks Self-Construction work whose evidence is PURELY proxy signals (test_count
 * delta, task_count delta, line_churn delta, rename-only AST diff, self-reported success) before it
 * reaches Strategy or Merge.
 *
 * Proxy signals are NOT inherently rejected — they are accepted ONLY when paired with concrete
 * capability or failure-removal evidence (a non-empty list under `capability_lift_refs` or
 * `failure_removal_refs`). Without that pairing, the proxy is the only signal and the gate refuses.
 *
 * Output (FACTS only): {schema_version, blocked, proxy_categories, blocked_proxy_categories, has_real_lever}
 * No scalar score, no ranking.
 */
final class AtlasGoalValueAntiProxyGate
{
    public const SCHEMA = 'atlas.goal_value.anti_proxy_gate.v1';

    public const PROXY_CATEGORIES = [
        'test_count',
        'task_count',
        'line_churn',
        'rename_only',
        'self_reported_success',
    ];

    /**
     * @param  array<string,mixed>  $signals     proxy signals present in the work (any of PROXY_CATEGORIES => true)
     * @param  array<string,mixed>  $realLevers  {capability_lift_refs: list<string>, failure_removal_refs: list<string>}
     * @return array<string,mixed>
     */
    public function evaluate(array $signals, array $realLevers): array
    {
        $present = [];
        foreach (self::PROXY_CATEGORIES as $cat) {
            if ((bool) ($signals[$cat] ?? false)) {
                $present[] = $cat;
            }
        }

        $capabilityRefs = array_values((array) ($realLevers['capability_lift_refs'] ?? []));
        $failureRefs = array_values((array) ($realLevers['failure_removal_refs'] ?? []));
        $hasRealLever = $capabilityRefs !== [] || $failureRefs !== [];

        $blocked = ! $hasRealLever && $present !== [];
        $blockedCategories = $blocked ? $present : [];

        return [
            'schema_version' => self::SCHEMA,
            'blocked' => $blocked,
            'proxy_categories' => $present,
            'blocked_proxy_categories' => $blockedCategories,
            'has_real_lever' => $hasRealLever,
        ];
    }
}
