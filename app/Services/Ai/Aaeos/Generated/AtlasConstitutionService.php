<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Self-Construction Constitution decider.
 *
 * Pure, deterministic enforcement of the Self-Construction Constitution:
 * "Atlas may build Atlas only under constitutional limits." Given a proposed
 * self-construction operation it returns the single governed verdict —
 * `allow`, `allow_with_human_review` or `block` — plus the specific
 * constitutional reasons, so a critical mutation can never pass unseen.
 *
 * Concrete rules grounded in the doc:
 *   - "May Do" / "Must Not Do"        → closed allowlist / denylist of intents;
 *     a "Must Not Do" intent is a hard block (e.g. "create parallel Kernel").
 *   - "Construction Boundary"         → 12 fields a self-construction op MUST
 *     declare (target layer … residual risk); any missing field blocks.
 *   - "Structural Contract Gate"      → for the listed structural subsystems the
 *     mandatory order is contract doc -> schema -> invariants -> examples ->
 *     gates -> read-only runtime; coding before the contract is complete is a
 *     hard stop ("it must stop coding and write the contract first").
 *   - "Human Gate Rules"              → 9 change kinds force human review.
 *   - "Success Definition"            → succeeds only when all 8 criteria hold.
 *   - "Authority Order"               → ranked chain for conflict routing.
 *
 * The service NEVER mutates code, calls a provider, runs a gate or touches the
 * database. It emits the verdict plus an audit receipt; callers decide whether
 * to admit the operation, route it to human review or block it.
 *
 * @see docs/engineering-knowledge-base/self-construction/constitution.md
 */
final class AtlasConstitutionService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.self_construction.constitution.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_HUMAN_REVIEW = 'allow_with_human_review';
    public const VERDICT_BLOCK = 'block';

    /**
     * "May Do" — the closed allowlist of permitted self-construction intents.
     *
     * @var list<string>
     */
    public const MAY_DO = [
        'identify_gaps',
        'research_state_of_the_art',
        'update_canonical_docs',
        'create_ap_spec_plan_task',
        'implement_small_reversible_block',
        'run_tests_and_gates',
        'record_evidence',
        'detect_drift_and_propose',
        'propose_learning_improvement',
    ];

    /**
     * "Must Not Do" — the closed denylist. Any of these as the operation intent
     * is an unconditional constitutional block.
     *
     * @var list<string>
     */
    public const MUST_NOT_DO = [
        'mutate_core_policy_without_ap',
        'create_parallel_kernel',
        'create_parallel_memory',
        'create_parallel_provider',
        'create_parallel_runtime',
        'create_parallel_daemon',
        'implement_structural_core_before_contract',
        'treat_chat_memory_as_truth',
        'auto_promote_research_to_runtime',
        'bypass_decision_receipt',
        'bypass_evidence_ledger',
        'bypass_architecture_validation',
        'hide_failed_gate_by_changing_spec',
        'expand_scope_for_adjacent_feature',
        'self_approve_critical_learning',
    ];

    /**
     * "Construction Boundary" — the 12 fields a self-construction operation MUST
     * declare. A missing (or empty) field blocks the operation.
     *
     * @var list<string>
     */
    public const BOUNDARY_FIELDS = [
        'target_layer',
        'target_capability',
        'owner',
        'risk',
        'current_maturity',
        'desired_maturity',
        'allowed_actions',
        'forbidden_actions',
        'gates',
        'rollback',
        'evidence',
        'residual_risk',
    ];

    /**
     * "Structural Contract Gate" — the structural core subsystems that must be
     * documentation-first.
     *
     * @var list<string>
     */
    public const STRUCTURAL_SUBSYSTEMS = [
        'ai_implementation_packet',
        'work_splitter',
        'scope_validator',
        'evidence_ledger',
        'spec_drift_detector',
        'memory_os',
        'research_os',
        'sdd_core',
        'self_construction_runtime',
    ];

    /**
     * Mandatory ordering for a structural contract. Each later stage requires
     * every earlier stage to be complete first.
     *
     * @var list<string>
     */
    public const CONTRACT_ORDER = [
        'contract_doc',
        'schema',
        'invariants',
        'examples',
        'gates',
        'read_only_runtime',
    ];

    /**
     * "Human Gate Rules" — change kinds that make human review mandatory.
     *
     * @var list<string>
     */
    public const HUMAN_GATE_CHANGES = [
        'autonomy_level',
        'provider_model_decision_policy',
        'memory_promotion_or_deletion_policy',
        'security_boundary',
        'user_data_handling',
        'code_execution_policy',
        'mcp_tool_write_access',
        'production_release_behavior',
        'self_improvement_mutation_rules',
    ];

    /**
     * "Success Definition" — the 8 criteria that ALL must hold for a completed
     * self-construction to count as a success.
     *
     * @var list<string>
     */
    public const SUCCESS_CRITERIA = [
        'documented',
        'specified',
        'implemented',
        'tested',
        'evidenced',
        'indexed',
        'drift_checked',
        'reversible_or_accepted_irreversible',
    ];

    /**
     * "Authority Order" — the constitutional chain, highest authority first.
     * Strategic self-construction conflicts resolve top-down through this chain.
     *
     * @var list<string>
     */
    public const AUTHORITY_ORDER = [
        'thesis_constitutional_fixed_point',
        'self_construction_constitution',
        'canonical_architecture_index',
        'kernel_decision_receipt_evidence_ledger',
        'documentation_os_knowledge_governance',
        'sdd_research_cognitive_runtime',
        'domain_docs_and_implementation_plans',
    ];

    /**
     * Evaluate one proposed self-construction operation against the constitution.
     *
     * @param array<string,mixed> $operation
     *        intent            : string  one of MAY_DO / MUST_NOT_DO (default '')
     *        boundary          : array   the Construction Boundary declaration
     *        structural_target : string  structural subsystem id, or '' if none
     *        contract_stage    : string  CONTRACT_ORDER stage about to start
     *        contract_complete : array   completed contract stages (list<string>)
     *        change_kinds      : list    Human-Gate change kinds this op touches
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluate(array $operation): array
    {
        $intent = AtlasAaeosValueNormalizer::lowerString($operation['intent'] ?? null);
        $structuralTarget = AtlasAaeosValueNormalizer::lowerString($operation['structural_target'] ?? null);
        $contractStage = AtlasAaeosValueNormalizer::lowerString($operation['contract_stage'] ?? null);
        $contractComplete = $this->normalizeList($operation['contract_complete'] ?? []);
        $changeKinds = $this->normalizeList($operation['change_kinds'] ?? []);
        $boundary = is_array($operation['boundary'] ?? null) ? $operation['boundary'] : [];

        $blocks = [];
        $reviewReasons = [];

        // Rule 1 — "Must Not Do" intents are an unconditional block.
        if (in_array($intent, self::MUST_NOT_DO, true)) {
            $blocks[] = "must_not_do:{$intent}";
        }

        // Rule 2 — fail-closed: the intent must be an explicit "May Do" verb.
        // An unknown or empty intent is not admitted (constitution is an
        // allowlist, not a denylist-only).
        $intentRecognized = in_array($intent, self::MAY_DO, true)
            || in_array($intent, self::MUST_NOT_DO, true);
        if (! $intentRecognized) {
            $blocks[] = $intent === ''
                ? 'intent_missing'
                : "intent_not_in_may_do:{$intent}";
        }

        // Rule 3 — Construction Boundary: every one of the 12 fields must be
        // declared and non-empty, else block.
        $missingBoundary = $this->missingBoundaryFields($boundary);
        foreach ($missingBoundary as $field) {
            $blocks[] = "boundary_field_missing:{$field}";
        }

        // Rule 4 — Structural Contract Gate: if the operation targets a
        // structural subsystem and is about to code (the read_only_runtime
        // stage) before the contract chain (doc->schema->invariants->examples
        // ->gates) is complete, it must stop and write the contract first.
        if ($structuralTarget !== '' && in_array($structuralTarget, self::STRUCTURAL_SUBSYSTEMS, true)) {
            $missingStages = $this->missingPrerequisiteStages($contractStage, $contractComplete);
            foreach ($missingStages as $stage) {
                $blocks[] = "structural_contract_incomplete:{$stage}";
            }
        }

        // Rule 5 — Human Gate Rules: any listed change kind forces human review
        // (it does not block, but it cannot be auto-admitted).
        foreach ($changeKinds as $kind) {
            if (in_array($kind, self::HUMAN_GATE_CHANGES, true)) {
                $reviewReasons[] = "human_gate:{$kind}";
            }
        }

        // Decide. Blocks dominate; then human review; otherwise allow.
        if ($blocks !== []) {
            $verdict = self::VERDICT_BLOCK;
        } elseif ($reviewReasons !== []) {
            $verdict = self::VERDICT_HUMAN_REVIEW;
        } else {
            $verdict = self::VERDICT_ALLOW;
        }

        $reviewRequired = $verdict !== self::VERDICT_ALLOW;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'intent' => $intent,
            'intent_recognized' => $intentRecognized,
            'structural_target' => $structuralTarget,
            'missing_boundary_fields' => $missingBoundary,
            'review_required' => $reviewRequired,
            'block_reasons' => $blocks,
            'review_reasons' => $reviewReasons,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this operation be admitted autonomously now?
     * (false => caller must route to human review or block.)
     *
     * @param array<string,mixed> $operation
     */
    public function mayProceedAutonomously(array $operation): bool
    {
        return $this->evaluate($operation)['verdict'] === self::VERDICT_ALLOW;
    }

    /**
     * "Success Definition": a completed self-construction succeeds only when ALL
     * 8 criteria hold. Returns the verdict plus the precise missing criteria.
     *
     * @param array<string,mixed> $completion criterion => bool (any subset)
     * @return array<string,mixed>
     */
    public function evaluateSuccess(array $completion): array
    {
        $missing = [];
        foreach (self::SUCCESS_CRITERIA as $criterion) {
            if (($completion[$criterion] ?? false) !== true) {
                $missing[] = $criterion;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'succeeded' => $missing === [],
            'missing_criteria' => $missing,
            'required_criteria' => self::SUCCESS_CRITERIA,
            'auditable' => true,
        ];
    }

    /**
     * Resolve which authority owns a strategic self-construction conflict.
     * Returns the highest-ranked (lowest index) authority among the candidates,
     * because the constitution routes conflicts top-down through the chain.
     *
     * @param list<string> $candidates competing authorities
     * @return array<string,mixed>
     */
    public function resolveAuthority(array $candidates): array
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($this->normalizeList($candidates) as $candidate) {
            $rank = array_search($candidate, self::AUTHORITY_ORDER, true);
            if ($rank !== false && $rank < $bestRank) {
                $bestRank = $rank;
                $best = $candidate;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'resolved_authority' => $best,
            'rank' => $best === null ? null : $bestRank,
            'authority_order' => self::AUTHORITY_ORDER,
            'auditable' => true,
        ];
    }

    /**
     * @param array<string,mixed> $boundary
     * @return list<string> the declared-boundary fields that are missing/empty
     */
    private function missingBoundaryFields(array $boundary): array
    {
        $missing = [];
        foreach (self::BOUNDARY_FIELDS as $field) {
            if (! $this->fieldPresent($boundary[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * For a structural subsystem, return the prerequisite contract stages that
     * are NOT yet complete for the stage about to start. Empty target stage =>
     * default to the most advanced stage (read_only_runtime) so a bare
     * structural attempt with no completed contract is fully blocked.
     *
     * @param list<string> $complete
     * @return list<string>
     */
    private function missingPrerequisiteStages(string $stage, array $complete): array
    {
        $targetIndex = array_search($stage, self::CONTRACT_ORDER, true);
        if ($targetIndex === false) {
            // Unknown/empty stage => treat as the last stage (coding runtime),
            // which requires the entire contract chain to be complete first.
            $targetIndex = count(self::CONTRACT_ORDER) - 1;
        }

        $missing = [];
        for ($i = 0; $i < $targetIndex; $i++) {
            $required = self::CONTRACT_ORDER[$i];
            if (! in_array($required, $complete, true)) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    private function fieldPresent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== false;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = strtolower(trim($item));
            }
        }

        return array_values($clean);
    }
}
