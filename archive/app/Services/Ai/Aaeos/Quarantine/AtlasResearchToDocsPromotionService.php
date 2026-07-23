<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Deterministic runtime for the Atlas AI Research To Documentation Promotion
 * policy.
 *
 * Turns the documented governance contract into pure, testable decision logic:
 *   - the Promotion Decision table: research is NOT law until it is routed to
 *     the correct canonical destination. Each research result maps to exactly
 *     one destination, and a result that is uncertain or weak is archived as a
 *     lead and is NOT promoted at all;
 *   - the Required Doc Delta: a promoted doc MUST state all 8 mandatory items
 *     (source basis, decision, allowed actions, forbidden actions, owner,
 *     validation, promotion gate, rollback/fail-closed). A delta missing any
 *     one of them is not a valid promotion;
 *   - the Source Trace: a doc does not paste long research, but it MUST point to
 *     at least one accepted source reference (AP, source packet, source URL /
 *     repo evidence, or local command/test output);
 *   - the Promotion Gate: the 6-item pre-implementation checklist. If ANY item
 *     fails, implementation does not proceed as normal work — it pauses, or (when
 *     only the structural-plan item is missing) becomes an isolated spike.
 *
 * Pure functions only: no database, no IO, no side effects. Every method returns
 * a strict typed array shape.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md
 */
class AtlasResearchToDocsPromotionService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.research_to_docs_promotion.v1';

    /**
     * The Promotion Decision table (doc: "Promotion Decision"). Each research
     * result routes to exactly one canonical destination. The order mirrors the
     * doc and is significant: identity/thesis is the highest authority and a
     * weak/uncertain result is the terminal "do not promote" case.
     *
     * Keyed by the stable research-result identifier -> destination identifier.
     *
     * @var array<string,string>
     */
    public const PROMOTION_ROUTES = [
        'changes_identity_or_thesis' => 'layer_minus_1_doc_and_ap',
        'changes_kernel_behavior' => 'kernel_doc_and_ap_and_tests',
        'changes_runtime_boundary' => 'runtime_boundary_doc_and_ap',
        'changes_memory_or_context' => 'cognitive_runtime_or_memory_docs',
        'changes_provider_absorption' => 'provider_evolution_intelligence',
        'changes_source_ingestion' => 'content_intelligence',
        'changes_domain_behavior' => 'domain_spec',
        'uncertain_or_weak' => 'archive_as_lead',
    ];

    /**
     * The single research result that must NOT be promoted into a canonical doc.
     * It is archived as a lead instead.
     */
    public const ARCHIVE_RESULT = 'uncertain_or_weak';

    public const ARCHIVE_DESTINATION = 'archive_as_lead';

    /**
     * The 8 fields a promoted research delta MUST state (doc: "Required Doc
     * Delta"). A delta that omits any one of them is not a valid promotion.
     *
     * @var list<string>
     */
    public const REQUIRED_DOC_DELTA_FIELDS = [
        'source_basis',
        'decision',
        'allowed_actions',
        'forbidden_actions',
        'owner',
        'validation',
        'promotion_gate',
        'rollback_or_fail_closed',
    ];

    /**
     * Accepted source-trace references (doc: "Source Trace"). A doc need not
     * paste long research, but it MUST point to at least one of these.
     *
     * @var list<string>
     */
    public const SOURCE_TRACE_KINDS = [
        'ap',
        'source_packet',
        'source_url_or_repo_evidence',
        'command_or_test_output',
    ];

    /**
     * The 6-item Promotion Gate checklist, in documented order (doc: "Promotion
     * Gate"). Every item must hold before implementation proceeds as normal work.
     *
     * @var list<string>
     */
    public const PROMOTION_GATE_ITEMS = [
        'research_packet_exists',
        'source_tier_is_acceptable',
        'conflicts_are_documented',
        'canonical_owner_doc_updated',
        'ap_or_plan_exists_for_structural_work',
        'validation_plan_exists',
    ];

    /**
     * The one gate item that, when it is the ONLY failure, downgrades the work to
     * an isolated spike rather than a hard pause: the AP/plan for structural work.
     * The doc allows structural work to proceed as an isolated spike while the
     * plan is still being shaped; every other gap pauses implementation.
     */
    public const SPIKE_DOWNGRADE_ITEM = 'ap_or_plan_exists_for_structural_work';

    /**
     * Route a research result to its canonical destination per the Promotion
     * Decision table. Research is not law until promoted to the right doc; an
     * uncertain/weak result is explicitly NOT promoted (archived as a lead).
     *
     * @return array{schema:string,research_result:string,known:bool,destination:?string,is_promotable:bool,is_archived:bool,reason:string}
     */
    public function routePromotion(string $researchResult): array
    {
        $key = $this->normalize($researchResult);
        $known = array_key_exists($key, self::PROMOTION_ROUTES);

        if (! $known) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'research_result' => $key,
                'known' => false,
                'destination' => null,
                'is_promotable' => false,
                'is_archived' => false,
                'reason' => 'unknown_research_result:not_in_promotion_decision_table',
            ];
        }

        $destination = self::PROMOTION_ROUTES[$key];
        $isArchived = $key === self::ARCHIVE_RESULT;
        $isPromotable = ! $isArchived;

        return [
            'schema' => self::SCHEMA_VERSION,
            'research_result' => $key,
            'known' => true,
            'destination' => $destination,
            'is_promotable' => $isPromotable,
            'is_archived' => $isArchived,
            'reason' => $isArchived
                ? 'uncertain_or_weak:archive_as_lead_do_not_promote'
                : 'route_to_canonical_destination:'.$destination,
        ];
    }

    /**
     * Validate a promoted research delta against the 8 mandatory Required Doc
     * Delta fields. A field counts as present only when supplied and non-empty.
     * A delta missing any required field is not a valid promotion.
     *
     * @param  array<string,mixed>  $delta
     * @return array{schema:string,valid:bool,missing_fields:list<string>,present_fields:list<string>,missing_count:int,present_count:int,reason:string}
     */
    public function validateDocDelta(array $delta): array
    {
        $missing = [];
        $present = [];

        foreach (self::REQUIRED_DOC_DELTA_FIELDS as $field) {
            if ($this->fieldPresent($delta[$field] ?? null)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $valid = $missing === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'valid' => $valid,
            'missing_fields' => $missing,
            'present_fields' => $present,
            'missing_count' => count($missing),
            'present_count' => count($present),
            'reason' => $valid
                ? 'all_8_required_doc_delta_fields_present'
                : 'missing_required_doc_delta_fields:'.implode(',', $missing),
        ];
    }

    /**
     * Validate the Source Trace. A doc does not need to paste long research, but
     * it MUST point to at least one accepted source reference. Unknown kinds are
     * ignored (they do not satisfy the trace); the trace holds only when at least
     * one recognised kind is provided and non-empty.
     *
     * @param  array<int|string,mixed>  $providedKinds  source-trace kinds present on the doc
     * @return array{schema:string,has_source_trace:bool,accepted_kinds:list<string>,unrecognized_kinds:list<string>,accepted_count:int,reason:string}
     */
    public function validateSourceTrace(array $providedKinds): array
    {
        $accepted = [];
        $unrecognized = [];

        foreach ($providedKinds as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $kind = $this->normalize($raw);
            if ($kind === '') {
                continue;
            }
            if (in_array($kind, self::SOURCE_TRACE_KINDS, true)) {
                if (! in_array($kind, $accepted, true)) {
                    $accepted[] = $kind;
                }
            } elseif (! in_array($kind, $unrecognized, true)) {
                $unrecognized[] = $kind;
            }
        }

        $has = $accepted !== [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'has_source_trace' => $has,
            'accepted_kinds' => $accepted,
            'unrecognized_kinds' => $unrecognized,
            'accepted_count' => count($accepted),
            'reason' => $has
                ? 'source_trace_present:'.implode(',', $accepted)
                : 'no_accepted_source_trace:doc_must_point_to_ap_packet_url_or_command_output',
        ];
    }

    /**
     * Evaluate the 6-item Promotion Gate. Every item must hold before
     * implementation proceeds as normal work.
     *
     * Decision (doc: "If any item fails, implementation pauses or becomes an
     * isolated spike"):
     *   - all 6 satisfied                         -> "implementation_may_proceed"
     *   - the ONLY missing item is the structural
     *     AP/plan item                            -> "isolated_spike"
     *   - any other item missing                  -> "implementation_paused"
     *
     * @param  array<string,bool>  $checklist  gate item -> satisfied?
     * @return array{schema:string,passed:bool,decision:string,satisfied:list<string>,unmet:list<string>,unmet_count:int,reason:string}
     */
    public function evaluatePromotionGate(array $checklist): array
    {
        $satisfied = [];
        $unmet = [];

        foreach (self::PROMOTION_GATE_ITEMS as $item) {
            if (($checklist[$item] ?? false) === true) {
                $satisfied[] = $item;
            } else {
                $unmet[] = $item;
            }
        }

        $passed = $unmet === [];

        if ($passed) {
            $decision = 'implementation_may_proceed';
            $reason = 'all_6_promotion_gate_items_satisfied';
        } elseif ($unmet === [self::SPIKE_DOWNGRADE_ITEM]) {
            $decision = 'isolated_spike';
            $reason = 'only_structural_plan_missing:downgrade_to_isolated_spike';
        } else {
            $decision = 'implementation_paused';
            $reason = 'promotion_gate_failed:'.implode(',', $unmet);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'passed' => $passed,
            'decision' => $decision,
            'satisfied' => $satisfied,
            'unmet' => $unmet,
            'unmet_count' => count($unmet),
            'reason' => $reason,
        ];
    }

    /**
     * Resolve a full promotion attempt end-to-end: route the result, validate
     * the doc delta and source trace, and evaluate the promotion gate.
     *
     * Research becomes law only when ALL hold: the result is promotable (not
     * archived), the 8-field delta is complete, a source trace is present, and
     * the promotion gate fully passes. A weak/uncertain result short-circuits to
     * "archive_as_lead" regardless of the rest. Anything else that falls short of
     * a clean gate either pauses or runs as an isolated spike.
     *
     * @param  array<string,mixed>  $delta       the proposed doc delta
     * @param  array<int|string,mixed>  $sourceTrace  source-trace kinds present
     * @param  array<string,bool>  $gateChecklist  promotion-gate item -> satisfied?
     * @return array{schema:string,research_result:string,route:array<string,mixed>,delta:array<string,mixed>,source_trace:array<string,mixed>,gate:array<string,mixed>,promoted_to_law:bool,resolution:string,reason:string}
     */
    public function resolvePromotion(
        string $researchResult,
        array $delta = [],
        array $sourceTrace = [],
        array $gateChecklist = []
    ): array {
        $route = $this->routePromotion($researchResult);
        $deltaCheck = $this->validateDocDelta($delta);
        $traceCheck = $this->validateSourceTrace($sourceTrace);
        $gate = $this->evaluatePromotionGate($gateChecklist);

        if (! $route['known']) {
            $resolution = 'rejected_unknown_result';
            $reason = 'unknown_research_result_cannot_be_promoted';
            $promoted = false;
        } elseif ($route['is_archived']) {
            $resolution = 'archived_as_lead';
            $reason = 'uncertain_or_weak:not_promoted';
            $promoted = false;
        } elseif (! $deltaCheck['valid']) {
            $resolution = 'blocked_incomplete_delta';
            $reason = 'doc_delta_incomplete:'.implode(',', $deltaCheck['missing_fields']);
            $promoted = false;
        } elseif (! $traceCheck['has_source_trace']) {
            $resolution = 'blocked_missing_source_trace';
            $reason = 'no_accepted_source_trace';
            $promoted = false;
        } elseif ($gate['passed']) {
            $resolution = 'promoted_to_law';
            $reason = 'routed_delta_complete_traced_and_gate_passed:'.(string) $route['destination'];
            $promoted = true;
        } elseif ($gate['decision'] === 'isolated_spike') {
            $resolution = 'isolated_spike';
            $reason = 'gate_only_missing_structural_plan:run_as_isolated_spike';
            $promoted = false;
        } else {
            $resolution = 'implementation_paused';
            $reason = 'promotion_gate_failed:'.implode(',', $gate['unmet']);
            $promoted = false;
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'research_result' => $route['research_result'],
            'route' => $route,
            'delta' => $deltaCheck,
            'source_trace' => $traceCheck,
            'gate' => $gate,
            'promoted_to_law' => $promoted,
            'resolution' => $resolution,
            'reason' => $reason,
        ];
    }

    private function fieldPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
