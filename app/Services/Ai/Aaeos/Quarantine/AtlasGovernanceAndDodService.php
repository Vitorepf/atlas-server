<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI OS - Governance And DoD decider.
 *
 * Pure, deterministic runtime for the governance contract documented in the
 * canonical doc. It enforces four distinct, separately-stated contracts and
 * never softens any of them:
 *
 *  1. Horizontal Layers (doc "Horizontal Layers"): the canonical, ordered set
 *     of ten layers every Atlas AI flow is decomposed into — Intent, Context,
 *     Policy, Decide, Tools, Memory, Validation, Repair, Evidence, Evolution.
 *     The doc rule "if a capability is useful to more than one surface/domain,
 *     move it to Core before expanding behavior" is enforced by the routing in
 *     {@see classifyCapability()}.
 *
 *  2. Anti-Duplication Rules (doc "Anti-Duplication Rules", 8 numbered items):
 *     a proposed capability is routed to exactly the layer/owner the rules
 *     dictate, and any attempt to give an alias its own logic when a canonical
 *     flow already exists is flagged as a duplication violation (rule 8).
 *
 *  3. Ownership (doc "Ownership" table, 9 rows): each layer maps to exactly one
 *     canonical owner. {@see ownerForLayer()} resolves it; an unknown layer is
 *     a governance gap, never a guessed owner.
 *
 *  4. Flow Definition Of Done (doc "Flow Definition Of Done", 11 items): a flow
 *     is "mature" ONLY when every one of the eleven required parts is present —
 *     owner, pipeline, governed context+memory, policy profile, executor, gates,
 *     repair/escalation, evidence packet, learning (when applicable), canonical
 *     docs, and tests preventing duplicated-flow regression. A single missing
 *     part flips the verdict to `not_mature` and names the gaps. This mirrors
 *     the frontmatter `forbidden_changes`: declaring maturity/readiness without
 *     verifiable evidence and green gates is forbidden, so "tests" and "evidence
 *     packet" are first-class DoD parts that cannot be skipped.
 *
 * The service is pure: it consumes already-normalized inputs and emits a
 * verdict. It never runs a harness, reads a doc, or touches a database.
 *
 * @see docs/engineering-knowledge-base/operating-system/governance-and-dod.md
 */
final class AtlasGovernanceAndDodService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.governance_and_dod.v1';

    /**
     * The ten Horizontal Layers, in strict doc order.
     *
     * @var list<string>
     */
    public const HORIZONTAL_LAYERS = [
        'intent',
        'context',
        'policy',
        'decide',
        'tools',
        'memory',
        'validation',
        'repair',
        'evidence',
        'evolution',
    ];

    /**
     * Ownership table (doc "Ownership"). Layer key => canonical owner.
     * Keys cover both the horizontal layers and the cross-cutting concerns the
     * doc table names (domain/intent, programming, engineering harness, docs).
     *
     * @var array<string,string>
     */
    public const OWNERSHIP = [
        'domain' => 'Atlas AI Core',
        'intent' => 'Atlas AI Core',
        'policy' => 'Atlas Decide + policy profiles',
        'decide' => 'Atlas Decide + policy profiles',
        'context' => 'Context assembly + Open Brain',
        'programming' => 'Atlas AI Programming',
        'engineering_harness' => 'Engineering Harness',
        'memory' => 'Memory Core / Open Brain',
        'tools' => 'Super Tool Runtime',
        'evidence' => 'Domain evidence packet',
        'documentation' => 'Engineering Knowledge Base',
    ];

    /**
     * The eleven Flow Definition Of Done parts, in doc order. `learning` is the
     * only conditionally-required part (doc: "learning when applicable").
     *
     * @var list<string>
     */
    public const DOD_PARTS = [
        'domain_and_owner',
        'canonical_pipeline',
        'governed_context_and_memory',
        'policy_profile',
        'executor',
        'gates',
        'repair_escalation',
        'evidence_packet',
        'learning',
        'canonical_docs',
        'regression_tests',
    ];

    /** Closed verdict set for the Flow DoD. */
    public const DOD_MATURE = 'mature';
    public const DOD_NOT_MATURE = 'not_mature';

    /** Closed routing verdict set for capability classification. */
    public const ROUTE_CORE = 'core';
    public const ROUTE_POLICY_DECIDE = 'policy_decide';
    public const ROUTE_EVIDENCE = 'evidence_required';
    public const ROUTE_PROGRAMMING = 'programming';
    public const ROUTE_PRIVACY = 'privacy_provider_safety';
    public const ROUTE_TOOL_RUNTIME = 'tool_runtime';
    public const ROUTE_FORGE_SHARED = 'forge_shared_gate';
    public const ROUTE_DUPLICATION_VIOLATION = 'duplication_violation';
    public const ROUTE_SURFACE_LOCAL = 'surface_local';

    /**
     * Classify a proposed capability against the 8 Anti-Duplication Rules and
     * route it to the layer/owner it must live in.
     *
     * Rules are evaluated in a fixed priority order so the verdict is
     * deterministic. The single most important governance failure — an alias
     * re-implementing logic that a canonical flow already owns (rule 8) — wins
     * over everything else, because that is the duplication the whole doc exists
     * to prevent.
     *
     * @param array<string,mixed> $cap
     *   is_alias: bool                 // this capability is an alias/shortcut
     *   canonical_flow_exists: bool    // a canonical flow already implements it
     *   implements_own_logic: bool     // the alias carries its own logic
     *   surfaces: list<string>|int     // surfaces/domains it serves (rule 1)
     *   decides_provider_model_permission_gate: bool  // rule 2
     *   state_changing: bool           // rule 3
     *   is_code_work: bool             // rule 4
     *   sensitive_memory: bool         // rule 5
     *   is_local_tool: bool            // rule 6 (registry/normalizer applies)
     *   forge_gate_relevant_to_dev: bool   // rule 7
     *   shared_or_justified: bool          // rule 7 escape hatch
     *
     * @return array{
     *   schema: string,
     *   route: string,
     *   owner: ?string,
     *   rule: int,
     *   is_violation: bool,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function classifyCapability(array $cap): array
    {
        $reasons = [];

        $isAlias = (bool) ($cap['is_alias'] ?? false);
        $canonicalExists = (bool) ($cap['canonical_flow_exists'] ?? false);
        $ownLogic = (bool) ($cap['implements_own_logic'] ?? false);

        // Rule 8 (highest priority): an alias must not implement its own logic
        // when a canonical flow already exists. This is a duplication violation.
        if ($isAlias && $canonicalExists && $ownLogic) {
            $reasons[] = 'rule8:alias_reimplements_canonical_flow';

            return $this->route(self::ROUTE_DUPLICATION_VIOLATION, null, 8, true, $reasons);
        }

        // Rule 1: multi-surface features belong to Core.
        if ($this->surfaceCount($cap['surfaces'] ?? null) > 1) {
            $reasons[] = 'rule1:multi_surface_belongs_to_core';

            return $this->route(self::ROUTE_CORE, self::OWNERSHIP['domain'], 1, false, $reasons);
        }

        // Rule 2: provider/model/permission/gate decisions pass through
        // Policy/Decide.
        if ((bool) ($cap['decides_provider_model_permission_gate'] ?? false)) {
            $reasons[] = 'rule2:provider_model_permission_gate_via_policy_decide';

            return $this->route(self::ROUTE_POLICY_DECIDE, self::OWNERSHIP['policy'], 2, false, $reasons);
        }

        // Rule 4: code work enters Programming.
        if ((bool) ($cap['is_code_work'] ?? false)) {
            $reasons[] = 'rule4:code_work_enters_programming';

            return $this->route(self::ROUTE_PROGRAMMING, self::OWNERSHIP['programming'], 4, false, $reasons);
        }

        // Rule 5: sensitive memory passes privacy/provider-safety.
        if ((bool) ($cap['sensitive_memory'] ?? false)) {
            $reasons[] = 'rule5:sensitive_memory_passes_privacy_provider_safety';

            return $this->route(self::ROUTE_PRIVACY, self::OWNERSHIP['memory'], 5, false, $reasons);
        }

        // Rule 6: local tools enter Tool Runtime when registry/normalizer
        // applies.
        if ((bool) ($cap['is_local_tool'] ?? false)) {
            $reasons[] = 'rule6:local_tool_enters_tool_runtime';

            return $this->route(self::ROUTE_TOOL_RUNTIME, self::OWNERSHIP['tools'], 6, false, $reasons);
        }

        // Rule 7: Forge gates relevant to Dev must be shared or explicitly
        // justified. If relevant-to-dev but neither shared nor justified, that
        // is a governance violation.
        if ((bool) ($cap['forge_gate_relevant_to_dev'] ?? false)) {
            if ((bool) ($cap['shared_or_justified'] ?? false)) {
                $reasons[] = 'rule7:forge_dev_gate_shared_or_justified';

                return $this->route(self::ROUTE_FORGE_SHARED, self::OWNERSHIP['engineering_harness'], 7, false, $reasons);
            }
            $reasons[] = 'rule7:forge_dev_gate_not_shared_or_justified';

            return $this->route(self::ROUTE_DUPLICATION_VIOLATION, null, 7, true, $reasons);
        }

        // Rule 3: state-changing tasks need evidence. Evaluated after the
        // ownership-routing rules because evidence is an obligation layered on
        // top of wherever the task lives, but for a plain state-changing task
        // with no other classification it routes to the evidence requirement.
        if ((bool) ($cap['state_changing'] ?? false)) {
            $reasons[] = 'rule3:state_changing_task_needs_evidence';

            return $this->route(self::ROUTE_EVIDENCE, self::OWNERSHIP['evidence'], 3, false, $reasons);
        }

        // Nothing matched: a single-surface, non-state-changing, non-code,
        // non-sensitive capability may stay local to its surface.
        $reasons[] = 'no_rule_matched:surface_local';

        return $this->route(self::ROUTE_SURFACE_LOCAL, null, 0, false, $reasons);
    }

    /**
     * Resolve the canonical owner of a layer (doc Ownership table). An unknown
     * layer is reported as a governance gap, never a guessed owner.
     *
     * @return array{
     *   schema: string,
     *   layer: string,
     *   owner: ?string,
     *   known: bool,
     *   auditable: true
     * }
     */
    public function ownerForLayer(string $layer): array
    {
        $key = strtolower(trim($layer));
        $owner = self::OWNERSHIP[$key] ?? null;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'layer' => $key,
            'owner' => $owner,
            'known' => $owner !== null,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate a flow against the eleven-part Flow Definition Of Done.
     *
     * A flow is `mature` only when every required part is present. `learning`
     * is required ONLY when the flow declares it applicable
     * (`learning_applicable: true`, the doc's "learning when applicable");
     * otherwise it is excluded from the required set and never counts as a gap.
     *
     * Per the frontmatter forbidden_changes ("declarar ... maturidade ou
     * prontidao sem evidencia verificavel e gates verdes"), the `evidence_packet`
     * and `regression_tests` parts are mandatory and cannot be waived.
     *
     * @param array<string,mixed> $flow
     *   parts: array<string,bool>   // keyed by DOD_PARTS keys; true = present
     *   learning_applicable: bool   // does the doc's "when applicable" apply?
     *
     * @return array{
     *   schema: string,
     *   verdict: string,
     *   is_mature: bool,
     *   required_parts: list<string>,
     *   present_parts: list<string>,
     *   missing_parts: list<string>,
     *   total_required: int,
     *   present_count: int,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateFlowDod(array $flow): array
    {
        $partsInput = is_array($flow['parts'] ?? null) ? $flow['parts'] : [];
        $learningApplicable = (bool) ($flow['learning_applicable'] ?? false);

        $required = [];
        foreach (self::DOD_PARTS as $part) {
            if ($part === 'learning' && ! $learningApplicable) {
                // Doc: "learning when applicable" — not required otherwise.
                continue;
            }
            $required[] = $part;
        }

        $present = [];
        $missing = [];
        foreach ($required as $part) {
            if ($this->partPresent($partsInput[$part] ?? null)) {
                $present[] = $part;
            } else {
                $missing[] = $part;
            }
        }

        $isMature = $missing === [];

        $reasons = [];
        if ($isMature) {
            $reasons[] = 'dod:all_required_parts_present';
        } else {
            foreach ($missing as $part) {
                $reasons[] = 'dod_missing:' . $part;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $isMature ? self::DOD_MATURE : self::DOD_NOT_MATURE,
            'is_mature' => $isMature,
            'required_parts' => $required,
            'present_parts' => $present,
            'missing_parts' => $missing,
            'total_required' => count($required),
            'present_count' => count($present),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: is the flow mature per the Flow DoD?
     *
     * @param array<string,mixed> $flow
     */
    public function isFlowMature(array $flow): bool
    {
        return $this->evaluateFlowDod($flow)['is_mature'];
    }

    /**
     * Return the canonical horizontal-layer pipeline in doc order.
     *
     * @return array{
     *   schema: string,
     *   layers: list<string>,
     *   count: int,
     *   auditable: true
     * }
     */
    public function horizontalLayers(): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'layers' => self::HORIZONTAL_LAYERS,
            'count' => count(self::HORIZONTAL_LAYERS),
            'auditable' => true,
        ];
    }

    /**
     * Build a routing verdict envelope.
     *
     * @param list<string> $reasons
     * @return array{
     *   schema: string, route: string, owner: ?string, rule: int,
     *   is_violation: bool, reasons: list<string>, auditable: true
     * }
     */
    private function route(string $route, ?string $owner, int $rule, bool $isViolation, array $reasons): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'route' => $route,
            'owner' => $owner,
            'rule' => $rule,
            'is_violation' => $isViolation,
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Count distinct surfaces/domains a capability serves. Accepts a list of
     * names or a pre-counted integer.
     *
     * @param mixed $surfaces
     */
    private function surfaceCount(mixed $surfaces): int
    {
        if (is_int($surfaces)) {
            return max(0, $surfaces);
        }
        if (is_array($surfaces)) {
            $distinct = [];
            foreach ($surfaces as $s) {
                if (is_string($s) && trim($s) !== '') {
                    $distinct[strtolower(trim($s))] = true;
                }
            }

            return count($distinct);
        }

        return 0;
    }

    /**
     * A DoD part is present only when explicitly asserted true. A missing part
     * or a falsey value is a gap — maturity is never assumed.
     *
     * @param mixed $value
     */
    private function partPresent(mixed $value): bool
    {
        return $value === true;
    }
}
