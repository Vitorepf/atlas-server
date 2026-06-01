<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Self-Programming Safety Contract — pure, deterministic safety deciders.
 *
 * Atlas may modify or program itself only when the safety conditions of the
 * contract are explicit and enforceable. This service turns the documented
 * contract into runtime decisions a self-programming loop can call BEFORE it
 * proposes, scopes or applies any change to Atlas itself.
 *
 * It implements, as separate pure methods, every concrete decision table in
 * the doc:
 *
 *   - Required Preconditions       -> preconditions(): the 8 conditions that
 *     must all hold before self-programming is allowed at all.
 *   - Forbidden Mutations          -> classifyMutation(): the 9 mutation
 *     targets that always need a human gate (no autonomous apply).
 *   - Autonomy Shrink Rule         -> autonomyCeiling(): condition -> max
 *     autonomy table. "When uncertainty rises, autonomy falls" — the ceiling
 *     is the LEAST-autonomy outcome across all active conditions.
 *   - Patch Shape                  -> scorePatch(): the 7 patch-shape
 *     properties a self-programming patch should prefer.
 *   - Receipt Scope                -> receiptScopeKeys(): the self_programming_scope
 *     keys the receipt must include.
 *   - Safety Closeout             -> closeout(): the 7 fields every
 *     self-programming closeout must report.
 *
 * And the top-level gate -> decide(): combines preconditions + mutation class +
 * autonomy ceiling into one bounded decision (the maximum autonomy actually
 * permitted for a specific proposed self-programming change).
 *
 * The service is read-only: it never edits files, never applies patches, never
 * calls a provider, never dispatches work, never enables self-programming. It
 * only classifies and emits the bounded decision + evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
 */
final class AtlasSelfProgrammingSafetyContractService
{
    /** Stable evidence schema id this decider emits. */
    public const SCHEMA = 'atlas.self_construction.self_programming_safety_contract.v1';

    /**
     * Autonomy levels, ordered from MOST autonomy (index 0) to LEAST (last).
     * "When uncertainty rises, autonomy falls" — a smaller rank is more
     * autonomy; the contract's ceiling is the level with the LARGEST rank
     * (most restrictive) among all active conditions.
     */
    public const AUTONOMY_EXECUTE = 'execute';            // bounded auto-apply inside scope
    public const AUTONOMY_REPAIR_IN_SCOPE = 'repair_in_scope'; // fix inside scope, else escalate
    public const AUTONOMY_PROPOSE = 'propose_only';       // produce a proposal, do not apply
    public const AUTONOMY_DOCS_SPEC_ONLY = 'docs_spec_only'; // docs/spec only, no code change
    public const AUTONOMY_ASK = 'ask';                    // stop and ask the operator
    public const AUTONOMY_HUMAN_APPROVAL = 'human_approval'; // requires explicit human approval
    public const AUTONOMY_BLOCK = 'block';                // refuse — never proceed

    /**
     * Autonomy ranking (severity of restriction). Larger = less autonomy.
     *
     * @var array<string,int>
     */
    private const AUTONOMY_RANK = [
        self::AUTONOMY_EXECUTE => 0,
        self::AUTONOMY_REPAIR_IN_SCOPE => 1,
        self::AUTONOMY_PROPOSE => 2,
        self::AUTONOMY_DOCS_SPEC_ONLY => 3,
        self::AUTONOMY_ASK => 4,
        self::AUTONOMY_HUMAN_APPROVAL => 5,
        self::AUTONOMY_BLOCK => 6,
    ];

    /**
     * Required Preconditions (doc "Required Preconditions"). All must hold
     * before self-programming is allowed at all. Each maps an input boolean
     * flag to the human-readable precondition it proves.
     *
     * @var array<string,string>
     */
    private const PRECONDITIONS = [
        'docs_current' => 'Canonical docs are current.',
        'context_fresh' => 'Context pack is fresh.',
        'spec_present' => 'Target capability has a Meta-SDD spec.',
        'files_declared' => 'Allowed files and forbidden files are declared.',
        'gates_runnable' => 'Gates exist and are runnable.',
        'rollback_present' => 'Rollback strategy exists.',
        'evidence_known' => 'Evidence requirements are known.',
        'drift_reviewable' => 'Drift detector can run or manual drift review is defined.',
    ];

    /**
     * Forbidden Mutations Without Human Gate (doc "Forbidden Mutations Without
     * Human Gate"). Touching any of these requires a human gate — autonomy is
     * capped at human_approval and auto-apply is never allowed.
     *
     * @var list<string>
     */
    private const FORBIDDEN_MUTATIONS = [
        'provider_model_selection_policy',
        'memory_deletion_promotion_or_privacy_policy',
        'auth_security_boundary',
        'data_exfiltration_boundary',
        'mcp_tool_write_access',
        'production_deployment_behavior',
        'self_improvement_auto_apply',
        'kernel_receipt_or_evidence_ledger_semantics',
        'irreversible_data_change',
    ];

    /**
     * Substring needles that map a free-form mutation target to a forbidden
     * class. Lets callers pass a path/topic instead of the exact enum.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_NEEDLES = [
        'provider_model' => 'provider_model_selection_policy',
        'model_selection' => 'provider_model_selection_policy',
        'memory_delete' => 'memory_deletion_promotion_or_privacy_policy',
        'memorydelete' => 'memory_deletion_promotion_or_privacy_policy',
        'memory_promotion' => 'memory_deletion_promotion_or_privacy_policy',
        'memorypromotion' => 'memory_deletion_promotion_or_privacy_policy',
        'memory_privacy' => 'memory_deletion_promotion_or_privacy_policy',
        'memoryprivacy' => 'memory_deletion_promotion_or_privacy_policy',
        'privacy_policy' => 'memory_deletion_promotion_or_privacy_policy',
        'privacypolicy' => 'memory_deletion_promotion_or_privacy_policy',
        'auth' => 'auth_security_boundary',
        'security_boundary' => 'auth_security_boundary',
        'exfiltration' => 'data_exfiltration_boundary',
        'data_egress' => 'data_exfiltration_boundary',
        'mcp_write' => 'mcp_tool_write_access',
        'tool_write' => 'mcp_tool_write_access',
        'production_deploy' => 'production_deployment_behavior',
        'prod_deploy' => 'production_deployment_behavior',
        'self_improvement_auto' => 'self_improvement_auto_apply',
        'auto_apply' => 'self_improvement_auto_apply',
        'kernel_receipt' => 'kernel_receipt_or_evidence_ledger_semantics',
        'evidence_ledger' => 'kernel_receipt_or_evidence_ledger_semantics',
        'irreversible' => 'irreversible_data_change',
        'drop_table' => 'irreversible_data_change',
        'data_delete' => 'irreversible_data_change',
    ];

    /**
     * Patch Shape (doc "Patch Shape"). Properties a self-programming patch
     * should prefer; each present property earns one point.
     *
     * @var list<string>
     */
    private const PATCH_SHAPE = [
        'small',
        'reversible',
        'localized',
        'covered_by_focused_tests',
        'linked_to_one_spec',
        'easy_to_review',
        'validated_by_architecture_or_doc_gates',
    ];

    /**
     * Receipt Scope (doc "Receipt Scope"). The self_programming_scope keys the
     * receipt must include.
     *
     * @var list<string>
     */
    private const RECEIPT_SCOPE_KEYS = [
        'max_files_changed',
        'max_runtime_surfaces',
        'allowed_layers',
        'forbidden_layers',
        'allowed_commands',
        'forbidden_commands',
        'rollback_strategy',
        'evidence_required',
    ];

    /**
     * Safety Closeout (doc "Safety Closeout"). The fields every self-programming
     * closeout must report.
     *
     * @var list<string>
     */
    private const CLOSEOUT_FIELDS = [
        'what_changed',
        'why_safe',
        'gates_run',
        'evidence_recorded',
        'what_not_touched',
        'residual_risk',
        'next_recommended_maturity_step',
    ];

    /**
     * Required Preconditions gate.
     *
     * @param array<string,mixed> $signals boolean flags keyed by PRECONDITIONS
     * @return array{
     *   schema:string,
     *   all_met:bool,
     *   met:list<string>,
     *   unmet:list<string>,
     *   missing_count:int,
     *   self_programming_allowed:bool,
     *   detail:array<string,bool>
     * }
     */
    public function preconditions(array $signals): array
    {
        $detail = [];
        $met = [];
        $unmet = [];
        foreach (self::PRECONDITIONS as $key => $_label) {
            $ok = (bool) ($signals[$key] ?? false);
            $detail[$key] = $ok;
            if ($ok) {
                $met[] = $key;
            } else {
                $unmet[] = $key;
            }
        }
        $allMet = $unmet === [];

        return [
            'schema' => self::SCHEMA,
            'all_met' => $allMet,
            'met' => $met,
            'unmet' => $unmet,
            'missing_count' => count($unmet),
            // Self-programming is allowed AT ALL only when every precondition holds.
            'self_programming_allowed' => $allMet,
            'detail' => $detail,
        ];
    }

    /**
     * Forbidden Mutations classifier. Given a proposed mutation target (an enum
     * value or a free-form path/topic), decide whether it is one of the nine
     * mutations that always require a human gate.
     *
     * @return array{
     *   schema:string,
     *   target:string,
     *   forbidden:bool,
     *   forbidden_class:string,
     *   requires_human_gate:bool,
     *   auto_apply_allowed:bool
     * }
     */
    public function classifyMutation(string $target): array
    {
        $needle = $this->normalize($target);
        $class = '';

        if (in_array($needle, self::FORBIDDEN_MUTATIONS, true)) {
            $class = $needle;
        } else {
            foreach (self::FORBIDDEN_NEEDLES as $fragment => $mapped) {
                if (str_contains($needle, $fragment)) {
                    $class = $mapped;
                    break;
                }
            }
        }

        $forbidden = $class !== '';

        return [
            'schema' => self::SCHEMA,
            'target' => $needle,
            'forbidden' => $forbidden,
            'forbidden_class' => $class,
            // A forbidden mutation always needs a human gate and can never auto-apply.
            'requires_human_gate' => $forbidden,
            'auto_apply_allowed' => ! $forbidden,
        ];
    }

    /**
     * Autonomy Shrink Rule table. Maps the active risk conditions to the
     * maximum autonomy permitted. The contract is "when uncertainty rises,
     * autonomy falls": the ceiling is the LEAST-autonomy (highest rank) level
     * among all conditions that are currently true. With no risk condition the
     * ceiling is full execute.
     *
     * Conditions (doc table):
     *   docs_stale                -> propose_only
     *   context_missing           -> ask
     *   high_risk                 -> human_approval
     *   no_tests                  -> docs_spec_only
     *   no_rollback               -> no execution (block)
     *   forbidden_files_involved  -> block
     *   validation_failed         -> repair_in_scope (else escalate)
     *
     * @param array<string,mixed> $conditions boolean flags
     * @return array{
     *   schema:string,
     *   ceiling:string,
     *   ceiling_rank:int,
     *   may_execute:bool,
     *   active_conditions:list<string>,
     *   reasons:list<string>
     * }
     */
    public function autonomyCeiling(array $conditions): array
    {
        $map = [
            'docs_stale' => self::AUTONOMY_PROPOSE,
            'context_missing' => self::AUTONOMY_ASK,
            'high_risk' => self::AUTONOMY_HUMAN_APPROVAL,
            'no_tests' => self::AUTONOMY_DOCS_SPEC_ONLY,
            'no_rollback' => self::AUTONOMY_BLOCK,
            'forbidden_files_involved' => self::AUTONOMY_BLOCK,
            'validation_failed' => self::AUTONOMY_REPAIR_IN_SCOPE,
        ];

        $ceiling = self::AUTONOMY_EXECUTE;
        $active = [];
        $reasons = [];
        foreach ($map as $condition => $level) {
            if ((bool) ($conditions[$condition] ?? false)) {
                $active[] = $condition;
                $reasons[] = $condition.'=>'.$level;
                // Keep the most restrictive (largest rank) level seen so far.
                if (self::AUTONOMY_RANK[$level] > self::AUTONOMY_RANK[$ceiling]) {
                    $ceiling = $level;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'ceiling' => $ceiling,
            'ceiling_rank' => self::AUTONOMY_RANK[$ceiling],
            // Only the unrestricted top level may auto-execute.
            'may_execute' => $ceiling === self::AUTONOMY_EXECUTE,
            'active_conditions' => $active,
            'reasons' => $reasons,
        ];
    }

    /**
     * Patch Shape scoring. A self-programming patch should prefer the seven
     * documented properties; this scores how many hold and whether the patch
     * is well-shaped (all properties present).
     *
     * @param array<string,mixed> $patch boolean flags keyed by PATCH_SHAPE
     * @return array{
     *   schema:string,
     *   score:int,
     *   max_score:int,
     *   well_shaped:bool,
     *   present:list<string>,
     *   missing:list<string>
     * }
     */
    public function scorePatch(array $patch): array
    {
        $present = [];
        $missing = [];
        foreach (self::PATCH_SHAPE as $property) {
            if ((bool) ($patch[$property] ?? false)) {
                $present[] = $property;
            } else {
                $missing[] = $property;
            }
        }
        $max = count(self::PATCH_SHAPE);

        return [
            'schema' => self::SCHEMA,
            'score' => count($present),
            'max_score' => $max,
            'well_shaped' => count($present) === $max,
            'present' => $present,
            'missing' => $missing,
        ];
    }

    /**
     * The self_programming_scope keys a receipt must include (doc "Receipt
     * Scope"). Given a candidate scope map, report which required keys are
     * present.
     *
     * @param array<string,mixed> $scope
     * @return array{
     *   schema:string,
     *   required_keys:list<string>,
     *   present_keys:list<string>,
     *   missing_keys:list<string>,
     *   complete:bool
     * }
     */
    public function receiptScopeKeys(array $scope = []): array
    {
        $present = [];
        $missing = [];
        foreach (self::RECEIPT_SCOPE_KEYS as $key) {
            if (array_key_exists($key, $scope)) {
                $present[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'required_keys' => self::RECEIPT_SCOPE_KEYS,
            'present_keys' => $present,
            'missing_keys' => $missing,
            'complete' => $missing === [],
        ];
    }

    /**
     * Safety Closeout assembler (doc "Safety Closeout"). Every self-programming
     * closeout must report the seven fields; this reports which are filled and
     * whether the closeout is complete enough to be valid.
     *
     * @param array<string,mixed> $report values keyed by CLOSEOUT_FIELDS
     * @return array{
     *   schema:string,
     *   required_fields:list<string>,
     *   reported_fields:list<string>,
     *   missing_fields:list<string>,
     *   complete:bool
     * }
     */
    public function closeout(array $report): array
    {
        $reported = [];
        $missing = [];
        foreach (self::CLOSEOUT_FIELDS as $field) {
            $value = $report[$field] ?? null;
            $filled = is_string($value) ? trim($value) !== '' : ($value !== null && $value !== [] && $value !== false);
            if ($filled) {
                $reported[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'required_fields' => self::CLOSEOUT_FIELDS,
            'reported_fields' => $reported,
            'missing_fields' => $missing,
            'complete' => $missing === [],
        ];
    }

    /**
     * Top-level bounded decision for one proposed self-programming change.
     *
     * Combines, in order:
     *   1. Required Preconditions — if any precondition is unmet the contract
     *      forbids self-programming; autonomy collapses to docs/spec only at
     *      best and the change is not permitted to apply.
     *   2. Forbidden Mutations — a forbidden mutation target caps autonomy at
     *      human_approval (never auto-apply).
     *   3. Autonomy Shrink Rule — the least-autonomy ceiling across all active
     *      risk conditions.
     *
     * The final ceiling is the MOST restrictive of these three inputs, because
     * "when uncertainty rises, autonomy falls". Auto-apply is allowed only when
     * the final ceiling is `execute`.
     *
     * @param array<string,mixed> $request {
     *   preconditions: array<string,bool>,
     *   mutation_target: string,
     *   conditions: array<string,bool>
     * }
     * @return array<string,mixed>
     */
    public function decide(array $request): array
    {
        $preconditions = $this->preconditions((array) ($request['preconditions'] ?? []));
        $mutation = $this->classifyMutation((string) ($request['mutation_target'] ?? ''));
        $shrink = $this->autonomyCeiling((array) ($request['conditions'] ?? []));

        $reasons = [];
        $candidate = $shrink['ceiling'];
        if ($shrink['active_conditions'] !== []) {
            $reasons[] = 'autonomy_shrink:'.$shrink['ceiling'];
        }

        // Forbidden mutation -> at least human_approval.
        if ($mutation['forbidden']) {
            $candidate = $this->mostRestrictive($candidate, self::AUTONOMY_HUMAN_APPROVAL);
            $reasons[] = 'forbidden_mutation:'.$mutation['forbidden_class'];
        }

        // Unmet preconditions -> the contract bars self-programming. Best case
        // is docs/spec only; if a hard condition already blocks, it stays block.
        if (! $preconditions['all_met']) {
            $candidate = $this->mostRestrictive($candidate, self::AUTONOMY_DOCS_SPEC_ONLY);
            $reasons[] = 'preconditions_unmet:'.implode(',', $preconditions['unmet']);
        }

        $mayAutoApply = $candidate === self::AUTONOMY_EXECUTE
            && $preconditions['all_met']
            && ! $mutation['forbidden'];

        // Self-programming may proceed in SOME bounded form unless the ceiling
        // is an outright block.
        $mayProceed = $candidate !== self::AUTONOMY_BLOCK;

        return [
            'schema' => self::SCHEMA,
            'autonomy_ceiling' => $candidate,
            'autonomy_ceiling_rank' => self::AUTONOMY_RANK[$candidate],
            'may_auto_apply' => $mayAutoApply,
            'may_proceed' => $mayProceed,
            'requires_human_gate' => $mutation['requires_human_gate']
                || $candidate === self::AUTONOMY_HUMAN_APPROVAL,
            'preconditions_all_met' => $preconditions['all_met'],
            'preconditions_unmet' => $preconditions['unmet'],
            'mutation_forbidden' => $mutation['forbidden'],
            'mutation_forbidden_class' => $mutation['forbidden_class'],
            'shrink_active_conditions' => $shrink['active_conditions'],
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /** Return the more restrictive (less autonomy) of two levels. */
    private function mostRestrictive(string $a, string $b): string
    {
        return self::AUTONOMY_RANK[$a] >= self::AUTONOMY_RANK[$b] ? $a : $b;
    }

    /** Normalize a free-form target to a lower_snake comparison key. */
    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
