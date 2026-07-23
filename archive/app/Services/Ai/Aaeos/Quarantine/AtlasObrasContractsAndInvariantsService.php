<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Obras — Contracts And Invariants: pure, deterministic conformance
 * checker for the non-negotiable rules of the Obras Operating System.
 *
 * The doc is the authoring boundary. This service turns its concrete contract
 * surfaces into runtime. Each method audits a candidate Obra (or candidate
 * implementation) against ONE documented rule set and returns a typed, blocking
 * verdict. It is read-only: it classifies and lists blocking reasons; it never
 * mutates Obra state, promotes a level, or relaxes a rule.
 *
 * Documented contract surfaces this code enforces (one method per surface):
 *   - Implementation Invariants: the 12 rules that "must hold from the first
 *       MVP" (stable id outside chat, objective/type/domain/status/next step,
 *       structured persistence, traceability, shared workspace for multi-provider,
 *       artifacts not chat handoffs, and the three claim guards: complete needs
 *       output/closure, Foundry needs asset classification, Sovereign needs
 *       autonomy + constraint review). -> auditInvariants()
 *   - Persistence Boundary: the minimum persisted shape, OR (fallback) reserved
 *       identifiers + extension points for relationships. -> checkPersistenceBoundary()
 *   - MVP Acceptance Contract: the 8 capabilities the first runtime must satisfy,
 *       including "an Obra without next step is incomplete" and "implementation
 *       independent from TCC-specific fields". -> checkMvpAcceptance()
 *   - Obra completeness rule (MVP point 7): no Obra is complete without a next
 *       step OR explicit closure. -> validateObraCompleteness()
 *   - Level Promotion Rules: L0->L1->L2->L3->L4->L5, each gated by its documented
 *       required-evidence list; promotion is monotonic (one step at a time) and
 *       requires evidence, not UI presence. -> evaluatePromotion()
 *   - Anti-Patterns: the forbidden implementations; presence of any one blocks.
 *       -> detectAntiPatterns()
 *
 * Non-goals honoured: it does not run providers, does not write evidence, does
 * not decide a level is reached on UI presence, and does not re-implement the
 * metrics thresholds owned by AtlasObrasMetricsRisksAndExcellenceService.
 *
 * @see docs/engineering-knowledge-base/obras/contracts-and-invariants.md
 */
final class AtlasObrasContractsAndInvariantsService
{
    /** Stable evidence schema id this checker emits. */
    public const SCHEMA = 'atlas.obras.contracts_and_invariants.v1';

    /** Conformance verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** Ordered maturity ladder (the documented patamares). */
    public const LEVELS = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5'];

    /**
     * Implementation Invariants — the 12 documented rules, keyed to the boolean
     * a caller asserts about a candidate Obra. Every key must be true for the
     * Obra to satisfy the invariant contract; an absent key is treated as false.
     *
     * @var array<string, string>
     */
    public const INVARIANTS = [
        'stable_id_outside_chat' => 'Obra has a stable id and persists outside chat history.',
        'has_core_fields' => 'Obra has objective, type, domain, status and next step.',
        'can_have_structure_nodes' => 'Obra can have structure nodes from day one.',
        'can_link_relations' => 'Obra can link to notes, tasks, sources, decisions, versions, gates and outputs.',
        'state_in_structured_persistence' => 'Obra state lives in structured persistence, not only Markdown files.',
        'markdown_not_sole_source' => 'Markdown export is allowed, but cannot be the only source of truth.',
        'ai_action_traceable' => 'Every AI action inside an Obra is traceable to Obra id and intent.',
        'multi_provider_uses_shared_workspace' => 'Multi-provider work uses the Obras Shared Workspace.',
        'provider_outputs_are_artifacts' => 'Provider outputs return as artifacts, not unstructured chat handoffs.',
        'complete_has_output_or_closure' => 'No Obra is complete without output or explicit closure.',
        'foundry_has_asset_classification' => 'No Foundry claim without asset classification.',
        'sovereign_has_autonomy_and_constraint_review' => 'No Sovereign claim without autonomy and constraint review.',
    ];

    /**
     * Persistence Boundary — the documented minimum persisted shape.
     *
     * @var list<string>
     */
    public const PERSISTENCE_MINIMUM = [
        'obra_id',
        'lifecycle_status',
        'type_domain_classification',
        'current_phase',
        'next_action',
        'hierarchical_nodes',
        'relation_or_edge_table',
        'evidence_event_path',
    ];

    /**
     * Persistence Boundary fallback — if relationships cannot ship yet, the doc
     * requires at minimum reserved identifiers AND extension points.
     *
     * @var list<string>
     */
    public const PERSISTENCE_RESERVED_FALLBACK = [
        'reserved_relationship_identifiers',
        'relationship_extension_points',
    ];

    /**
     * MVP Acceptance Contract — the 8 capabilities the first runtime must prove.
     *
     * @var array<string, string>
     */
    public const MVP_CAPABILITIES = [
        'create_obra_with_core_fields' => 'create an Obra with objective, type, domain, status and next step.',
        'create_structure_node' => 'create at least one structure node.',
        'update_status_and_next_step' => 'update status and next step.',
        'list_active_obras' => 'list active Obras.',
        'archive_without_deleting_history' => 'archive an Obra without deleting history.',
        'expose_readonly_status_summary' => 'expose a read-only status summary.',
        'validate_obra_without_next_step_is_incomplete' => 'validate that an Obra without next step is incomplete.',
        'independent_from_tcc_fields' => 'keep implementation independent from TCC-specific fields.',
    ];

    /**
     * Level Promotion Rules — each transition's documented required-evidence list.
     * A promotion is allowed only when EVERY requirement for that single step is
     * present (true) in the supplied evidence map.
     *
     * @var array<string, list<string>>
     */
    public const PROMOTION_REQUIREMENTS = [
        'L0->L1' => [
            'structure_nodes',
            'notes_tasks_sources_attach',
            'obra_summary_generated',
            'ai_context_scoped_by_obra_id',
        ],
        'L1->L2' => [
            'decision_records',
            'version_records',
            'gate_templates_and_runs',
            'evidence_events',
            'role_permission_or_personal_substitute',
        ],
        'L2->L3' => [
            'intent_parser',
            'spec_and_plan_generation',
            'reviewer',
            'repair_loop',
            'output_renderer',
            'human_checkpoints',
        ],
        'L3->L4' => [
            'asset_registry',
            'obra_dependencies',
            'portfolio_view',
            'strategic_score',
            'opportunity_cost_record',
        ],
        'L4->L5' => [
            'operator_model',
            'autonomy_graph',
            'capital_stack',
            'constraint_system',
            'periodic_strategic_review',
        ],
    ];

    /**
     * Anti-Patterns — the documented forbidden implementations. A caller flags
     * which (if any) are present; any single present flag fails the audit.
     *
     * @var array<string, string>
     */
    public const ANTI_PATTERNS = [
        'tcc_only_schema' => 'TCC-only schema.',
        'folder_only_model' => 'folder-only model.',
        'chat_only_memory' => 'chat-only memory.',
        'ui_cards_without_production_graph' => 'UI cards without production graph.',
        'task_board_renamed_as_obras' => 'task board renamed as Obras.',
        'output_without_evidence' => 'output without evidence.',
        'gates_as_static_checklist' => 'gates as static checklist with no run history.',
        'ai_cannot_say_which_obra' => 'AI sessions that cannot say which Obra, node, sources and decisions were used.',
        'providers_relay_without_shared_packets' => 'multi-provider work where providers relay context without shared packets, artifact ids, scope map and evidence.',
    ];

    /**
     * Invariant audit: returns pass only when EVERY documented invariant holds.
     *
     * @param array<string, bool> $obra
     * @return array{schema: string, surface: string, status: string, satisfied: list<string>, violated: list<string>, total: int, satisfied_count: int, blocking_reasons: list<string>}
     */
    public function auditInvariants(array $obra): array
    {
        $satisfied = [];
        $violated = [];
        $reasons = [];
        foreach (self::INVARIANTS as $key => $label) {
            if (($obra[$key] ?? false) === true) {
                $satisfied[] = $key;
            } else {
                $violated[] = $key;
                $reasons[] = 'invariant_violated: ' . $label;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'implementation_invariants',
            'status' => $violated === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'satisfied' => $satisfied,
            'violated' => $violated,
            'total' => count(self::INVARIANTS),
            'satisfied_count' => count($satisfied),
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Persistence Boundary check. Passes when the full documented minimum shape
     * is present. If the minimum is not fully met, it passes ONLY via the
     * documented fallback: reserved identifiers AND extension points present.
     *
     * @param array<string, bool> $persistence
     * @return array{schema: string, surface: string, status: string, mode: string, missing_minimum: list<string>, missing_fallback: list<string>, blocking_reasons: list<string>}
     */
    public function checkPersistenceBoundary(array $persistence): array
    {
        $missingMinimum = [];
        foreach (self::PERSISTENCE_MINIMUM as $field) {
            if (($persistence[$field] ?? false) !== true) {
                $missingMinimum[] = $field;
            }
        }

        if ($missingMinimum === []) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'persistence_boundary',
                'status' => self::STATUS_PASS,
                'mode' => 'full',
                'missing_minimum' => [],
                'missing_fallback' => [],
                'blocking_reasons' => [],
            ];
        }

        $missingFallback = [];
        foreach (self::PERSISTENCE_RESERVED_FALLBACK as $field) {
            if (($persistence[$field] ?? false) !== true) {
                $missingFallback[] = $field;
            }
        }

        $fallbackMet = $missingFallback === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'persistence_boundary',
            'status' => $fallbackMet ? self::STATUS_PASS : self::STATUS_FAIL,
            'mode' => $fallbackMet ? 'reserved_fallback' : 'insufficient',
            'missing_minimum' => $missingMinimum,
            'missing_fallback' => $missingFallback,
            'blocking_reasons' => $fallbackMet
                ? []
                : ['persistence_below_minimum_and_no_reserved_extension_points'],
        ];
    }

    /**
     * MVP Acceptance Contract: passes only when all 8 documented capabilities
     * are present.
     *
     * @param array<string, bool> $capabilities
     * @return array{schema: string, surface: string, status: string, present: list<string>, missing: list<string>, total: int, present_count: int, blocking_reasons: list<string>}
     */
    public function checkMvpAcceptance(array $capabilities): array
    {
        $present = [];
        $missing = [];
        $reasons = [];
        foreach (self::MVP_CAPABILITIES as $key => $label) {
            if (($capabilities[$key] ?? false) === true) {
                $present[] = $key;
            } else {
                $missing[] = $key;
                $reasons[] = 'mvp_capability_missing: ' . $label;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'mvp_acceptance',
            'status' => $missing === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'present' => $present,
            'missing' => $missing,
            'total' => count(self::MVP_CAPABILITIES),
            'present_count' => count($present),
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Obra completeness rule (MVP point 7 + invariant "No Obra may be called
     * complete without output or explicit closure").
     *
     * An Obra is complete ONLY when it has a non-empty next step AND it carries
     * output OR an explicit closure. An Obra with no next step is incomplete
     * regardless of any "complete" flag the caller passes (the rule is enforced,
     * not trusted). Output or explicit closure is what allows a complete claim.
     *
     * @param array{next_step?: string|null, has_output?: bool, explicitly_closed?: bool} $obra
     * @return array{schema: string, surface: string, status: string, is_complete: bool, has_next_step: bool, has_output: bool, explicitly_closed: bool, reasons: list<string>}
     */
    public function validateObraCompleteness(array $obra): array
    {
        $nextStep = $obra['next_step'] ?? null;
        $hasNextStep = is_string($nextStep) && trim($nextStep) !== '';
        $hasOutput = ($obra['has_output'] ?? false) === true;
        $closed = ($obra['explicitly_closed'] ?? false) === true;

        $reasons = [];
        if (! $hasNextStep) {
            $reasons[] = 'incomplete: an Obra without next step is incomplete.';
        }
        if (! $hasOutput && ! $closed) {
            $reasons[] = 'incomplete: no Obra may be called complete without output or explicit closure.';
        }

        $isComplete = $hasNextStep && ($hasOutput || $closed);

        return [
            'schema' => self::SCHEMA,
            'surface' => 'obra_completeness',
            'status' => $isComplete ? self::STATUS_PASS : self::STATUS_FAIL,
            'is_complete' => $isComplete,
            'has_next_step' => $hasNextStep,
            'has_output' => $hasOutput,
            'explicitly_closed' => $closed,
            'reasons' => $reasons,
        ];
    }

    /**
     * Level Promotion gate. Decides whether an Obra at $from may be promoted to
     * $to given an evidence map. Rules enforced:
     *   - promotion is monotonic and single-step (L0->L1, L1->L2, ...); skipping
     *     a level or going backwards is rejected;
     *   - the target step's full documented required-evidence list must be
     *     present (evidence, not UI presence — "Promotion between levels requires
     *     evidence, not UI presence").
     *
     * @param array<string, bool> $evidence
     * @return array{schema: string, surface: string, status: string, transition: string, can_promote: bool, valid_transition: bool, required: list<string>, present: list<string>, missing: list<string>, blocking_reasons: list<string>}
     */
    public function evaluatePromotion(string $from, string $to, array $evidence): array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        $transition = $from . '->' . $to;

        $fromIdx = array_search($from, self::LEVELS, true);
        $toIdx = array_search($to, self::LEVELS, true);

        $validTransition = $fromIdx !== false
            && $toIdx !== false
            && $toIdx === $fromIdx + 1;

        if (! $validTransition) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'level_promotion',
                'status' => self::STATUS_FAIL,
                'transition' => $transition,
                'can_promote' => false,
                'valid_transition' => false,
                'required' => [],
                'present' => [],
                'missing' => [],
                'blocking_reasons' => ['invalid_transition: promotion must be a single forward step on the L0..L5 ladder.'],
            ];
        }

        $required = self::PROMOTION_REQUIREMENTS[$transition] ?? [];
        $present = [];
        $missing = [];
        foreach ($required as $requirement) {
            if (($evidence[$requirement] ?? false) === true) {
                $present[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $canPromote = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'level_promotion',
            'status' => $canPromote ? self::STATUS_PASS : self::STATUS_FAIL,
            'transition' => $transition,
            'can_promote' => $canPromote,
            'valid_transition' => true,
            'required' => $required,
            'present' => $present,
            'missing' => $missing,
            'blocking_reasons' => $canPromote
                ? []
                : ['promotion_blocked: missing required evidence (evidence, not UI presence).'],
        ];
    }

    /**
     * Anti-Pattern detector. Passes only when NO forbidden implementation flag
     * is present.
     *
     * @param array<string, bool> $flags
     * @return array{schema: string, surface: string, status: string, detected: list<string>, blocking_reasons: list<string>}
     */
    public function detectAntiPatterns(array $flags): array
    {
        $detected = [];
        $reasons = [];
        foreach (self::ANTI_PATTERNS as $key => $label) {
            if (($flags[$key] ?? false) === true) {
                $detected[] = $key;
                $reasons[] = 'anti_pattern_present: ' . $label;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'anti_patterns',
            'status' => $detected === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'detected' => $detected,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-contract audit: runs every surface over a candidate Obra bundle and
     * folds the per-surface verdicts into one pass|fail document. Status is pass
     * only when EVERY surface passes.
     *
     * @param array{
     *     invariants?: array<string, bool>,
     *     persistence?: array<string, bool>,
     *     mvp?: array<string, bool>,
     *     completeness?: array<string, mixed>,
     *     promotion?: array{from?: string, to?: string, evidence?: array<string, bool>},
     *     anti_patterns?: array<string, bool>
     * } $bundle
     * @return array{schema: string, status: string, surfaces: array<string, mixed>, blocking_reasons: list<string>}
     */
    public function audit(array $bundle): array
    {
        $surfaces = [
            'invariants' => $this->auditInvariants($bundle['invariants'] ?? []),
            'persistence' => $this->checkPersistenceBoundary($bundle['persistence'] ?? []),
            'mvp' => $this->checkMvpAcceptance($bundle['mvp'] ?? []),
            'completeness' => $this->validateObraCompleteness($bundle['completeness'] ?? []),
            'anti_patterns' => $this->detectAntiPatterns($bundle['anti_patterns'] ?? []),
        ];

        if (isset($bundle['promotion'])) {
            $promotion = $bundle['promotion'];
            $surfaces['promotion'] = $this->evaluatePromotion(
                (string) ($promotion['from'] ?? ''),
                (string) ($promotion['to'] ?? ''),
                $promotion['evidence'] ?? [],
            );
        }

        $reasons = [];
        $allPass = true;
        foreach ($surfaces as $surface) {
            if (($surface['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $allPass = false;
            }
            foreach (($surface['blocking_reasons'] ?? []) as $reason) {
                $reasons[] = $reason;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_PASS : self::STATUS_FAIL,
            'surfaces' => $surfaces,
            'blocking_reasons' => $reasons,
        ];
    }
}
