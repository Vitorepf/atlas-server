<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

/**
 * Pure resolver for cross-department veto propagation.
 *
 * VARIANT A — graph-reachability + repair-loop escalation.
 *
 * Deterministically computes how a veto raised by an origin department
 * propagates across the canonical department transition graph, by evaluating
 * the canonized veto rules (cross-department choreography) against an encoded
 * adjacency graph: the pause set is derived from graph reachability of the
 * rule-declared downstream targets, escalation/redirect targets are selected by
 * rule, and a repair-loop auto-escalation fires on the 4th iteration
 * (repair_iteration >= 3 -> Architect + Operator).
 *
 * The class holds no state of its own; every returned field is computed from
 * the method inputs. Identical inputs always yield identical output.
 */
final class AtlasAaeosVetoPropagationResolver
{
    private const SCHEMA_VERSION = 'atlas.aaeos.veto_propagation.v1';

    /**
     * repair_iteration value at which (and above) the 4th-iteration
     * auto-escalation to Architect + Operator engages.
     */
    private const REPAIR_LOOP_AUTO_ESCALATION_THRESHOLD = 3;

    /**
     * Encoded canonical department adjacency (mermaid stateDiagram transitions).
     * Each key is a lowercase department id; the value is the ordered list of
     * directly reachable downstream department ids.
     *
     * @return array<string, list<string>>
     */
    public function canonicalTransitions(): array
    {
        return [
            'product' => ['architect'],
            'architect' => ['security', 'forge', 'dev'],
            'dev' => ['review'],
            'forge' => ['review'],
            'review' => ['architect', 'delivery'],
            'security' => ['operator', 'architect'],
            'delivery' => ['operator'],
            'operator' => ['memory'],
            'qa' => ['dev', 'forge'],
            'debug' => ['dev'],
            'memory' => [],
        ];
    }

    /**
     * Resolve the propagation effect of a veto.
     *
     * @return array{
     *     schema_version: 'atlas.aaeos.veto_propagation.v1',
     *     origin_department: string,
     *     veto_kind: string,
     *     repair_iteration: int,
     *     resolution: 'propagate_pause'|'redirect_upstream'|'override_pass'|'no_match',
     *     pause_set: list<string>,
     *     redirect_to: list<string>,
     *     escalation_target: list<string>,
     *     override: bool,
     *     auto_escalated: bool,
     *     reason: string,
     *     matched_rule: string
     * }
     */
    public function resolve(string $originDepartment, string $vetoKind, int $repairIteration = 0): array
    {
        $origin = $this->normalize($originDepartment);
        $kind = $this->normalize($vetoKind);
        $iteration = max(0, $repairIteration);

        $rule = $this->matchRule($origin, $kind);
        $autoEscalated = $iteration >= self::REPAIR_LOOP_AUTO_ESCALATION_THRESHOLD;

        $resolution = $rule['resolution'];
        $pauseSet = $rule['pause_set'];
        $redirectTo = $rule['redirect_to'];
        $escalationTarget = $rule['escalation_target'];
        $override = $rule['override'];
        $matchedRule = $rule['matched_rule'];
        $reason = $rule['reason'];

        // Repair-loop auto-escalation: once the repair loop reaches its 4th
        // iteration on a matched veto cycle, escalate to Architect + Operator
        // regardless of the base rule's escalation target.
        if ($autoEscalated && $resolution !== 'no_match') {
            $escalationTarget = $this->reachableTargets(['architect', 'operator']);
            $matchedRule = 'repair_loop_4th_iteration';
            $reason = 'repair_loop_reached_4th_iteration_auto_escalated_to_architect_and_operator';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'origin_department' => $origin,
            'veto_kind' => $kind,
            'repair_iteration' => $iteration,
            'resolution' => $resolution,
            'pause_set' => $pauseSet,
            'redirect_to' => $redirectTo,
            'escalation_target' => $escalationTarget,
            'override' => $override,
            'auto_escalated' => $autoEscalated,
            'reason' => $reason,
            'matched_rule' => $matchedRule,
        ];
    }

    /**
     * Evaluate the canonized veto rules against the canonical transition graph.
     *
     * @return array{
     *     resolution: 'propagate_pause'|'redirect_upstream'|'override_pass'|'no_match',
     *     pause_set: list<string>,
     *     redirect_to: list<string>,
     *     escalation_target: list<string>,
     *     override: bool,
     *     matched_rule: string,
     *     reason: string
     * }
     */
    private function matchRule(string $origin, string $kind): array
    {
        // Operator veto -> override final (always passes).
        if ($origin === 'operator' && $kind === 'override') {
            return [
                'resolution' => 'override_pass',
                'pause_set' => [],
                'redirect_to' => [],
                'escalation_target' => [],
                'override' => true,
                'matched_rule' => 'operator_override',
                'reason' => 'operator_veto_is_final_override_always_passes',
            ];
        }

        // Security veto -> propagates to Dev/Forge/Delivery (all pause),
        // escalates to Operator. pause_set is computed by keeping only the
        // rule-declared downstream targets that exist in the canonical graph.
        if ($origin === 'security' && $kind === 'security') {
            return [
                'resolution' => 'propagate_pause',
                'pause_set' => $this->reachableTargets(['dev', 'forge', 'delivery']),
                'redirect_to' => [],
                'escalation_target' => $this->reachableTargets(['operator']),
                'override' => false,
                'matched_rule' => 'security_veto',
                'reason' => 'security_veto_propagates_pause_to_dev_forge_delivery',
            ];
        }

        // Architect veto on spec -> back to Product for clarification.
        if ($origin === 'architect' && $kind === 'spec') {
            return [
                'resolution' => 'redirect_upstream',
                'pause_set' => [],
                'redirect_to' => $this->reachableTargets(['product']),
                'escalation_target' => [],
                'override' => false,
                'matched_rule' => 'architect_spec_veto',
                'reason' => 'architect_spec_veto_redirects_upstream_to_product',
            ];
        }

        // Review veto on delivery -> back to Dev/Forge for repair.
        if ($origin === 'review' && $kind === 'delivery') {
            return [
                'resolution' => 'redirect_upstream',
                'pause_set' => [],
                'redirect_to' => $this->reachableTargets(['dev', 'forge']),
                'escalation_target' => [],
                'override' => false,
                'matched_rule' => 'review_delivery_veto',
                'reason' => 'review_delivery_veto_redirects_to_dev_forge_for_repair',
            ];
        }

        return [
            'resolution' => 'no_match',
            'pause_set' => [],
            'redirect_to' => [],
            'escalation_target' => [],
            'override' => false,
            'matched_rule' => 'none',
            'reason' => 'no_canonical_veto_rule_matched_origin_and_kind',
        ];
    }

    /**
     * Keep only the candidate department ids that are real nodes in the
     * canonical transition graph (graph reachability validation), preserving
     * the candidate order and removing duplicates.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function reachableTargets(array $candidates): array
    {
        $graph = $this->canonicalTransitions();
        $resolved = [];

        foreach ($candidates as $candidate) {
            $department = $this->normalize($candidate);

            if ($department === '' || in_array($department, $resolved, true)) {
                continue;
            }

            if (! $this->isGraphNode($graph, $department)) {
                continue;
            }

            $resolved[] = $department;
        }

        return $resolved;
    }

    /**
     * A department is a node in the graph if it is a vertex key or appears as a
     * reachable target of some vertex.
     *
     * @param  array<string, list<string>>  $graph
     */
    private function isGraphNode(array $graph, string $department): bool
    {
        if (array_key_exists($department, $graph)) {
            return true;
        }

        foreach ($graph as $targets) {
            if (in_array($department, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
