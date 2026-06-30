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
 * Output (FACTS only): {schema_version, blocked, proxy_categories, blocked_proxy_categories, has_real_lever, real_lever_families}
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
        'wrapper_only',
        'scaffold_only_test',
        'command_surface_only',
        'doc_only_claim',
        'template_farm_batch',
    ];

    /** Maps each proxy category to a concrete anti-proxy reason code for rejected tasks. */
    private const ANTI_PROXY_REASON_CODES = [
        'test_count'           => 'test_count:add_capability_lift_or_failure_removal_ref',
        'task_count'           => 'task_count:add_capability_lift_or_failure_removal_ref',
        'line_churn'           => 'line_churn:add_capability_lift_or_failure_removal_ref',
        'rename_only'          => 'rename_only:add_capability_lift_or_failure_removal_ref',
        'self_reported_success'=> 'self_reported_success:replace_with_falsifiable_test_gate',
        'wrapper_only'         => 'wrapper_only:add_real_behavior_capability_ref',
        'scaffold_only_test'   => 'scaffold_only_test:add_real_capability_lift_ref',
        'command_surface_only' => 'command_surface_only:add_behavior_observable_ref',
        'doc_only_claim'       => 'doc_only_claim:add_capability_lift_or_failure_removal_ref',
        'template_farm_batch'  => 'template_farm_batch:add_unique_capability_lift_ref_per_item',
    ];

    /** Maps each lever family to its falsifiable evidence demand. */
    private const EVIDENCE_DEMANDS = [
        'capability_lift' => 'capability_lift:prove_with_green_test_gate_and_behavior_observable',
        'failure_removal' => 'failure_removal:prove_with_red_to_green_trace_and_receipt',
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

        $capabilityRefs = $this->normalizeRefs((array) ($realLevers['capability_lift_refs'] ?? []));
        $failureRefs = $this->normalizeRefs((array) ($realLevers['failure_removal_refs'] ?? []));
        $hasRealLever = $capabilityRefs !== [] || $failureRefs !== [];

        $families = [];
        if ($capabilityRefs !== []) {
            $families[] = 'capability_lift';
        }
        if ($failureRefs !== []) {
            $families[] = 'failure_removal';
        }

        $blocked = ! $hasRealLever && $present !== [];
        $blockedCategories = $blocked ? $present : [];

        // Concrete anti-proxy reason codes for rejected tasks.
        $antiProxyReasonCodes = [];
        if ($blocked) {
            foreach ($blockedCategories as $cat) {
                $antiProxyReasonCodes[] = self::ANTI_PROXY_REASON_CODES[$cat] ?? ($cat.':add_capability_lift_or_failure_removal_ref');
            }
            sort($antiProxyReasonCodes, SORT_STRING);
        }

        // Falsifiable evidence demand for accepted tasks (per lever family).
        $falsifiableEvidenceDemand = [];
        if ($hasRealLever) {
            foreach ($families as $family) {
                $falsifiableEvidenceDemand[] = self::EVIDENCE_DEMANDS[$family] ?? ($family.':prove_with_falsifiable_gate');
            }
        }

        return [
            'schema_version'             => self::SCHEMA,
            'blocked'                    => $blocked,
            'proxy_categories'           => $present,
            'blocked_proxy_categories'   => $blockedCategories,
            'has_real_lever'             => $hasRealLever,
            'real_lever_families'        => $families,
            'anti_proxy_reason_codes'    => $antiProxyReasonCodes,
            'falsifiable_evidence_demand'=> $falsifiableEvidenceDemand,
        ];
    }

    /** @return list<string> */
    private function normalizeRefs(array $refs): array
    {
        $seen = [];
        foreach ($refs as $ref) {
            $s = trim((string) $ref);
            if ($s !== '') {
                $seen[$s] = true;
            }
        }
        $out = array_keys($seen);
        sort($out);

        return $out;
    }
}
