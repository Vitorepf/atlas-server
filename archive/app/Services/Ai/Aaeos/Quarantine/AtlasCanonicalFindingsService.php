<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Architecture-audit canonical-findings decider.
 *
 * Pure, deterministic runtime for the canonical findings doc. Given one
 * observed flow / finding (where business logic lives, whether it duplicates an
 * existing capability, whether Decide executes, whether a surface adapter drove
 * flow, etc.) it classifies the finding against the doc's eight Canonical Truths
 * and six observed Disorders, then applies the "Remaining Audit Discipline" to
 * emit the single promotion target — Core, Domain, Runtime or Tool Runtime — and
 * a verdict (clean | correct | promote | block).
 *
 * Contract (from the doc):
 *   - "Canonical Truths": Atlas is the surface; providers are replaceable
 *     engines. Memory belongs to Atlas. Context Pack is a hashable artifact,
 *     not improvised prompt text. Engineering needs contract before code. Tools
 *     must be governed and evidenced. The 5x multiplier comes from the harness,
 *     not model worship. Profile resolves before provider/model. Decide emits a
 *     receipt; it does not execute.
 *   - "Disorder Observed" -> "Correction": surface became flow; competing
 *     context concepts; gates at several levels; repair with multiple
 *     semantics; Decide drifting into execution; rich docs without a
 *     consolidation map.
 *   - "Remaining Audit Discipline": when a new duplicated flow appears, do NOT
 *     patch the duplicate in place — identify the shared owner and promote the
 *     capability to Core, Domain, Runtime or Tool Runtime.
 *
 * The service NEVER executes anything, calls a provider, mutates code or touches
 * the database. It emits the classification plus an audit receipt; callers
 * decide whether to consolidate, correct or block.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/canonical-findings.md
 */
final class AtlasCanonicalFindingsService
{
    /** Stable receipt schema id for the classification this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.architecture_audit.canonical_findings.v1';

    /** Promotion targets — the closed set named by "Remaining Audit Discipline". */
    public const OWNER_CORE = 'core';
    public const OWNER_DOMAIN = 'domain';
    public const OWNER_RUNTIME = 'runtime';
    public const OWNER_TOOL_RUNTIME = 'tool_runtime';

    /** No promotion needed (finding already sits with its canonical owner). */
    public const OWNER_NONE = 'none';

    /** Verdicts. */
    public const VERDICT_CLEAN = 'clean';     // respects every truth, nothing to do
    public const VERDICT_CORRECT = 'correct'; // a disorder applies; apply its correction
    public const VERDICT_PROMOTE = 'promote'; // duplicated flow; promote to shared owner
    public const VERDICT_BLOCK = 'block';     // a truth is violated in a non-recoverable way

    /**
     * Canonical truths (id => implication) exactly as the doc states them. The
     * id is what callers and tests pin against.
     *
     * @var array<string,string>
     */
    private const TRUTHS = [
        'atlas_is_surface_providers_are_engines' => 'Provider adapters cannot own flow.',
        'memory_belongs_to_atlas' => 'Important flows must request context through Atlas.',
        'context_pack_is_artifact' => 'Context must be small, traceable, provider-safe and hashable.',
        'engineering_needs_contract_before_code' => 'Medium/hard programming tasks need task contracts and gates.',
        'tools_must_be_governed_and_evidenced' => 'Tool Runtime owns registry, policy, executor, normalizer and evidence.',
        'multiplier_comes_from_harness' => 'Context, gates, repair, replay and final packets are the multiplier.',
        'profile_is_not_a_model_preset' => 'Domain/flow profile resolves before provider/model.',
        'decide_emits_receipt_not_execution' => 'Domain orchestrator and runtime execute under the receipt.',
    ];

    /**
     * Observed disorders (id => correction) exactly as the doc states them.
     *
     * @var array<string,string>
     */
    private const DISORDERS = [
        'surface_became_flow' => 'Surface adapters collect input and call atlas.run.',
        'competing_context_concepts' => 'Base context, Open Brain and Engineering Context need formal boundaries.',
        'gates_at_several_levels' => 'Domain quality matrix chooses gates by task type and risk.',
        'repair_multiple_semantics' => 'Use one failure taxonomy and repair capsule per domain.',
        'decide_drifts_into_execution' => 'Decision Receipt is output; runtime executes.',
        'docs_without_consolidation_map' => 'Canonical index and Documentation OS govern authority.',
    ];

    /**
     * Closure mechanisms the doc lists as the currently active ways findings get
     * closed. Exposed so callers/tests can assert the inventory.
     *
     * @var list<string>
     */
    private const CLOSURE_MECHANISMS = [
        'surface_adapter_contracts',
        'decision_receipt_propagation',
        'architecture_validation_static_scans',
        'documentation_health_and_split_plan',
        'domain_profile_registry',
        'capability_registry_and_surface_coverage_tests',
        'evidence_ledger_and_read_models',
    ];

    /**
     * Classify one observed finding against the canonical truths and disorders,
     * then resolve the consolidation owner per the audit discipline.
     *
     * @param array<string,mixed> $finding
     *        logic_layer        : string  where business logic currently lives:
     *                                      surface_adapter|provider_adapter|core|
     *                                      domain|runtime|tool_runtime (default surface_adapter)
     *        duplicates_existing : bool    a duplicated flow appeared (default false)
     *        decide_executes     : bool    the Decide step executes instead of
     *                                      emitting a receipt (default false)
     *        context_source      : string  improvised|context_pack (default context_pack)
     *        has_task_contract   : bool    medium/hard task carries a contract (default true)
     *        task_difficulty     : string  easy|medium|hard (default medium)
     *        provider_owns_flow  : bool    a provider adapter owns flow (default false)
     *        memory_through_atlas : bool   context requested through Atlas (default true)
     *        repair_semantics_count : int  distinct repair semantics observed (default 1)
     *        capability_kind     : string  what the duplicated capability is —
     *                                      tool|orchestration|cross_domain|domain
     *                                      (drives the promotion target; default orchestration)
     *
     * @return array<string,mixed> the classification + audit receipt
     */
    public function classify(array $finding): array
    {
        $logicLayer = $this->normalizeLayer($finding['logic_layer'] ?? null);
        $duplicates = (bool) ($finding['duplicates_existing'] ?? false);
        $decideExecutes = (bool) ($finding['decide_executes'] ?? false);
        $contextSource = $this->normalizeContextSource($finding['context_source'] ?? null);
        $hasContract = (bool) ($finding['has_task_contract'] ?? true);
        $difficulty = $this->normalizeDifficulty($finding['task_difficulty'] ?? null);
        $providerOwnsFlow = (bool) ($finding['provider_owns_flow'] ?? false);
        $memoryThroughAtlas = (bool) ($finding['memory_through_atlas'] ?? true);
        $repairSemantics = $this->normalizeRepairCount($finding['repair_semantics_count'] ?? null);

        $violatedTruths = [];
        $disorders = [];
        $reasons = [];

        // Truth: "Decide emits receipt; it does not execute." A Decide step that
        // executes is the worst violation — it is a hard block, not a soft
        // correction, because runtime authority has been bypassed.
        if ($decideExecutes) {
            $violatedTruths[] = 'decide_emits_receipt_not_execution';
            $disorders[] = 'decide_drifts_into_execution';
            $reasons[] = 'decide_step_executes_instead_of_emitting_receipt';
        }

        // Truth: "Atlas is the surface; providers are replaceable engines" +
        // "Provider adapters cannot own flow." A provider adapter owning flow is
        // a hard block.
        if ($providerOwnsFlow) {
            $violatedTruths[] = 'atlas_is_surface_providers_are_engines';
            $reasons[] = 'provider_adapter_owns_flow';
        }

        // Truth: "Memory belongs to Atlas, not providers."
        if (! $memoryThroughAtlas) {
            $violatedTruths[] = 'memory_belongs_to_atlas';
            $reasons[] = 'context_not_requested_through_atlas';
        }

        // Disorder: "Surface became flow." A surface adapter is the place to
        // collect input and call atlas.run — if business logic lives there it is
        // a disorder whose correction is to promote the logic out of the surface.
        if ($logicLayer === 'surface_adapter') {
            $disorders[] = 'surface_became_flow';
            $reasons[] = 'business_logic_lives_in_surface_adapter';
        }

        // Truth: "Context Pack is an artifact, not improvised prompt text."
        if ($contextSource === 'improvised') {
            $violatedTruths[] = 'context_pack_is_artifact';
            $disorders[] = 'competing_context_concepts';
            $reasons[] = 'context_is_improvised_prompt_text_not_a_pack_artifact';
        }

        // Truth: "Engineering needs contract before code." Only medium/hard tasks
        // require a contract; an easy task without one is not a violation.
        if (! $hasContract && in_array($difficulty, ['medium', 'hard'], true)) {
            $violatedTruths[] = 'engineering_needs_contract_before_code';
            $reasons[] = "task_difficulty_{$difficulty}_without_task_contract";
        }

        // Disorder: "Repair has multiple semantics." More than one repair
        // semantic in scope must collapse to one taxonomy + capsule per domain.
        if ($repairSemantics > 1) {
            $disorders[] = 'repair_multiple_semantics';
            $reasons[] = "repair_has_{$repairSemantics}_competing_semantics";
        }

        // Disorder: a duplicated flow appeared.
        if ($duplicates) {
            $disorders[] = 'docs_without_consolidation_map';
            $reasons[] = 'duplicated_flow_observed';
        }

        $violatedTruths = array_values(array_unique($violatedTruths));
        $disorders = array_values(array_unique($disorders));

        // --- Verdict resolution (ordered: block dominates) -------------------
        // A violated truth that bypasses runtime/provider authority cannot be
        // patched in place — it is a hard block.
        $hardBlockTruths = array_intersect($violatedTruths, [
            'decide_emits_receipt_not_execution',
            'atlas_is_surface_providers_are_engines',
        ]);

        if ($hardBlockTruths !== []) {
            $verdict = self::VERDICT_BLOCK;
        } elseif ($duplicates) {
            // "Remaining Audit Discipline": a duplicated flow is NEVER patched in
            // place — it is promoted to its shared owner.
            $verdict = self::VERDICT_PROMOTE;
        } elseif ($violatedTruths !== [] || $disorders !== []) {
            $verdict = self::VERDICT_CORRECT;
        } else {
            $verdict = self::VERDICT_CLEAN;
        }

        // Promotion owner: only meaningful when we are promoting a duplicated
        // flow. "Identify the shared owner and promote to Core, Domain, Runtime
        // or Tool Runtime."
        $promotionOwner = $verdict === self::VERDICT_PROMOTE
            ? $this->resolveOwner($finding['capability_kind'] ?? null)
            : self::OWNER_NONE;

        // The discipline's headline invariant: never patch a duplicate in place.
        $patchInPlaceForbidden = $duplicates;

        $corrections = [];
        foreach ($disorders as $disorderId) {
            $corrections[$disorderId] = self::DISORDERS[$disorderId];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'logic_layer' => $logicLayer,
            'duplicates_existing' => $duplicates,
            'patch_in_place_forbidden' => $patchInPlaceForbidden,
            'promotion_owner' => $promotionOwner,
            'violated_truths' => $violatedTruths,
            'violated_truth_count' => count($violatedTruths),
            'disorders' => $disorders,
            'corrections' => $corrections,
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate for the audit driver: is this finding clean (every
     * canonical truth respected, no disorder, no duplication)?
     *
     * @param array<string,mixed> $finding
     */
    public function isClean(array $finding): bool
    {
        return $this->classify($finding)['verdict'] === self::VERDICT_CLEAN;
    }

    /**
     * Resolve the shared owner a duplicated capability must be promoted to.
     *
     * "Promote the capability to Core, Domain, Runtime or Tool Runtime."
     *   - tool        -> Tool Runtime (tools are governed + evidenced there).
     *   - cross_domain / orchestration -> Core (shared above all domains).
     *   - domain      -> Domain (single-domain business logic).
     *   - runtime/execution -> Runtime (executes under the receipt).
     */
    private function resolveOwner(mixed $capabilityKind): string
    {
        $kind = is_string($capabilityKind) ? strtolower(trim($capabilityKind)) : '';

        return match ($kind) {
            'tool', 'tooling', 'tool_runtime' => self::OWNER_TOOL_RUNTIME,
            'domain', 'single_domain' => self::OWNER_DOMAIN,
            'runtime', 'execution', 'executor' => self::OWNER_RUNTIME,
            // Cross-domain / orchestration / shared logic is owned by Core. This
            // is also the safe default: a shared flow with no clearer home is
            // promoted to Core rather than left in a surface.
            default => self::OWNER_CORE,
        };
    }

    /**
     * The eight canonical truths (id => implication).
     *
     * @return array<string,string>
     */
    public function canonicalTruths(): array
    {
        return self::TRUTHS;
    }

    /**
     * The six observed disorders (id => correction).
     *
     * @return array<string,string>
     */
    public function disorderCorrections(): array
    {
        return self::DISORDERS;
    }

    /**
     * The currently active closure mechanisms.
     *
     * @return list<string>
     */
    public function closureMechanisms(): array
    {
        return self::CLOSURE_MECHANISMS;
    }

    private function normalizeLayer(mixed $layer): string
    {
        $allowed = [
            'surface_adapter',
            'provider_adapter',
            'core',
            'domain',
            'runtime',
            'tool_runtime',
        ];

        if (is_string($layer)) {
            $key = strtolower(trim($layer));
            if (in_array($key, $allowed, true)) {
                return $key;
            }
        }

        // Unknown / missing layer is treated as surface_adapter so the audit
        // assumes the riskiest placement until proven otherwise.
        return 'surface_adapter';
    }

    private function normalizeContextSource(mixed $source): string
    {
        if (is_string($source) && strtolower(trim($source)) === 'improvised') {
            return 'improvised';
        }

        return 'context_pack';
    }

    private function normalizeDifficulty(mixed $difficulty): string
    {
        if (is_string($difficulty)) {
            $key = strtolower(trim($difficulty));
            if (in_array($key, ['easy', 'medium', 'hard'], true)) {
                return $key;
            }
        }

        return 'medium';
    }

    private function normalizeRepairCount(mixed $count): int
    {
        if (is_int($count) && $count >= 0) {
            return $count;
        }

        if (is_string($count) && ctype_digit(trim($count))) {
            return (int) trim($count);
        }

        return 1;
    }
}
