<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

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
final class AtlasVetoPropagationResolver
{
    public const FIELD_FORGE = 'forge';
    public const FIELD_QA = 'qa';
    public const SCHEMA_VERSION = 'atlas.aaeos.veto_propagation.v1';


    public const FIELD_RESOLUTION = 'resolution';

    public const FIELD_PAUSE_SET = 'pause_set';

    public const FIELD_REDIRECT_TO = 'redirect_to';

    public const FIELD_ESCALATION_TARGET = 'escalation_target';

    public const FIELD_OVERRIDE = 'override';

    public const FIELD_MATCHED_RULE = 'matched_rule';

    public const FIELD_REASON = 'reason';

    public const FIELD_ORIGIN_DEPARTMENT = 'origin_department';

    public const FIELD_VETO_KIND = 'veto_kind';

    public const FIELD_REPAIR_ITERATION = 'repair_iteration';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_REVIEW = 'review';
    public const FIELD_OPERATOR = 'operator';
    public const FIELD_PRODUCT = 'product';
    public const FIELD_ARCHITECT = 'architect';
    public const FIELD_DEV = 'dev';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_AUTO_ESCALATED = 'auto_escalated';

    public const RESOLUTION_PROPAGATE_PAUSE = 'propagate_pause';

    public const RESOLUTION_REDIRECT_UPSTREAM = 'redirect_upstream';

    public const RESOLUTION_OVERRIDE_PASS = 'override_pass';

    public const RESOLUTION_NO_MATCH = 'no_match';

    /**
     * repair_iteration value at which (and above) the 4th-iteration
     * auto-escalation to Architect + Operator engages.
     */
    public const REPAIR_LOOP_AUTO_ESCALATION_THRESHOLD = 3;

    public const REASON_OPERATOR_VETO_FINAL_OVERRIDE = 'operator_veto_is_final_override_always_passes';

    public const REASON_SECURITY_VETO_PAUSE_DOWNSTREAM = 'security_veto_propagates_pause_to_dev_forge_delivery';

    public const REASON_ARCHITECT_SPEC_VETO_UPSTREAM = 'architect_spec_veto_redirects_upstream_to_product';

    public const REASON_REVIEW_DELIVERY_VETO_REPAIR = 'review_delivery_veto_redirects_to_dev_forge_for_repair';

    public const REASON_NO_CANONICAL_VETO_RULE = 'no_canonical_veto_rule_matched_origin_and_kind';
    public const FIELD_DEBUG = 'debug';
    public const FIELD_DELIVERY = 'delivery';
    public const FIELD_SECURITY = 'security';
    public const FIELD_ARCHITECT_SPEC_VETO = 'architect_spec_veto';
    public const FIELD_NONE = 'none';
    public const FIELD_OPERATOR_OVERRIDE = 'operator_override';
    public const FIELD_REVIEW_DELIVERY_VETO = 'review_delivery_veto';
    public const FIELD_SECURITY_VETO = 'security_veto';
    public const FIELD_SPEC = 'spec';
    public const FIELD_REPAIR_LOOP_4TH_ITERATION = 'repair_loop_4th_iteration';
    public const FIELD_REPAIR_LOOP_REACHED_4TH_ITERATION_AUTO_ESCALATED_TO_ARCHITECT_AND_OPERATOR = 'repair_loop_reached_4th_iteration_auto_escalated_to_architect_and_operator';

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
            self::FIELD_PRODUCT => [self::FIELD_ARCHITECT],
            self::FIELD_ARCHITECT => [self::FIELD_SECURITY, self::FIELD_FORGE, self::FIELD_DEV],
            self::FIELD_DEV => [self::FIELD_REVIEW],
            self::FIELD_FORGE => [self::FIELD_REVIEW],
            self::FIELD_REVIEW => [self::FIELD_ARCHITECT, self::FIELD_DELIVERY],
            self::FIELD_SECURITY => [self::FIELD_OPERATOR, self::FIELD_ARCHITECT],
            self::FIELD_DELIVERY => [self::FIELD_OPERATOR],
            self::FIELD_OPERATOR => [self::FIELD_MEMORY],
            self::FIELD_QA => [self::FIELD_DEV, self::FIELD_FORGE],
            self::FIELD_DEBUG => [self::FIELD_DEV],
            self::FIELD_MEMORY => [],
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

        $resolution = $rule[self::FIELD_RESOLUTION];
        $pauseSet = $rule[self::FIELD_PAUSE_SET];
        $redirectTo = $rule[self::FIELD_REDIRECT_TO];
        $escalationTarget = $rule[self::FIELD_ESCALATION_TARGET];
        $override = $rule[self::FIELD_OVERRIDE];
        $matchedRule = $rule[self::FIELD_MATCHED_RULE];
        $reason = $rule[self::FIELD_REASON];

        // Repair-loop auto-escalation: once the repair loop reaches its 4th
        // iteration on a matched veto cycle, escalate to Architect + Operator
        // regardless of the base rule's escalation target.
        if ($autoEscalated && $resolution !== self::RESOLUTION_NO_MATCH) {
            $escalationTarget = $this->reachableTargets([self::FIELD_ARCHITECT, self::FIELD_OPERATOR]);
            $matchedRule = self::FIELD_REPAIR_LOOP_4TH_ITERATION;
            $reason = self::FIELD_REPAIR_LOOP_REACHED_4TH_ITERATION_AUTO_ESCALATED_TO_ARCHITECT_AND_OPERATOR;
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_ORIGIN_DEPARTMENT => $origin,
            self::FIELD_VETO_KIND => $kind,
            self::FIELD_REPAIR_ITERATION => $iteration,
            self::FIELD_RESOLUTION => $resolution,
            self::FIELD_PAUSE_SET => $pauseSet,
            self::FIELD_REDIRECT_TO => $redirectTo,
            self::FIELD_ESCALATION_TARGET => $escalationTarget,
            self::FIELD_OVERRIDE => $override,
            self::FIELD_AUTO_ESCALATED => $autoEscalated,
            self::FIELD_REASON => $reason,
            self::FIELD_MATCHED_RULE => $matchedRule,
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
        if ($origin === self::FIELD_OPERATOR && $kind === self::FIELD_OVERRIDE) {
            return [
                self::FIELD_RESOLUTION => self::RESOLUTION_OVERRIDE_PASS,
                self::FIELD_PAUSE_SET => [],
                self::FIELD_REDIRECT_TO => [],
                self::FIELD_ESCALATION_TARGET => [],
                self::FIELD_OVERRIDE => true,
                self::FIELD_MATCHED_RULE => self::FIELD_OPERATOR_OVERRIDE,
                self::FIELD_REASON => self::REASON_OPERATOR_VETO_FINAL_OVERRIDE,
            ];
        }

        // Security veto -> propagates to Dev/Forge/Delivery (all pause),
        // escalates to Operator. pause_set is computed by keeping only the
        // rule-declared downstream targets that exist in the canonical graph.
        if ($origin === self::FIELD_SECURITY && $kind === self::FIELD_SECURITY) {
            return [
                self::FIELD_RESOLUTION => self::RESOLUTION_PROPAGATE_PAUSE,
                self::FIELD_PAUSE_SET => $this->reachableTargets([self::FIELD_DEV, self::FIELD_FORGE, self::FIELD_DELIVERY]),
                self::FIELD_REDIRECT_TO => [],
                self::FIELD_ESCALATION_TARGET => $this->reachableTargets([self::FIELD_OPERATOR]),
                self::FIELD_OVERRIDE => false,
                self::FIELD_MATCHED_RULE => self::FIELD_SECURITY_VETO,
                self::FIELD_REASON => self::REASON_SECURITY_VETO_PAUSE_DOWNSTREAM,
            ];
        }

        // Architect veto on spec -> back to Product for clarification.
        if ($origin === self::FIELD_ARCHITECT && $kind === self::FIELD_SPEC) {
            return [
                self::FIELD_RESOLUTION => self::RESOLUTION_REDIRECT_UPSTREAM,
                self::FIELD_PAUSE_SET => [],
                self::FIELD_REDIRECT_TO => $this->reachableTargets([self::FIELD_PRODUCT]),
                self::FIELD_ESCALATION_TARGET => [],
                self::FIELD_OVERRIDE => false,
                self::FIELD_MATCHED_RULE => self::FIELD_ARCHITECT_SPEC_VETO,
                self::FIELD_REASON => self::REASON_ARCHITECT_SPEC_VETO_UPSTREAM,
            ];
        }

        // Review veto on delivery -> back to Dev/Forge for repair.
        if ($origin === self::FIELD_REVIEW && $kind === self::FIELD_DELIVERY) {
            return [
                self::FIELD_RESOLUTION => self::RESOLUTION_REDIRECT_UPSTREAM,
                self::FIELD_PAUSE_SET => [],
                self::FIELD_REDIRECT_TO => $this->reachableTargets([self::FIELD_DEV, self::FIELD_FORGE]),
                self::FIELD_ESCALATION_TARGET => [],
                self::FIELD_OVERRIDE => false,
                self::FIELD_MATCHED_RULE => self::FIELD_REVIEW_DELIVERY_VETO,
                self::FIELD_REASON => self::REASON_REVIEW_DELIVERY_VETO_REPAIR,
            ];
        }

        return [
            self::FIELD_RESOLUTION => self::RESOLUTION_NO_MATCH,
            self::FIELD_PAUSE_SET => [],
            self::FIELD_REDIRECT_TO => [],
            self::FIELD_ESCALATION_TARGET => [],
            self::FIELD_OVERRIDE => false,
            self::FIELD_MATCHED_RULE => self::FIELD_NONE,
            self::FIELD_REASON => self::REASON_NO_CANONICAL_VETO_RULE,
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
        return AiValueNormalizer::lowerTrimmedString($value);
    }
}
