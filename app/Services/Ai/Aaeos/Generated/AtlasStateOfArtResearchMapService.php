<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cognitive Runtime State Of Art Research Map — pure, deterministic
 * governance guard that turns the research map doc into runtime.
 *
 * The doc is explicit that it is a *research map, not a runtime authorization*
 * ("Ele e mapa de pesquisa, nao autorizacao de runtime"). So this service never
 * touches the database, a provider, the shell or the filesystem, never promotes
 * a technique by itself, and never alters policy. It is read-only: given a
 * proposed external technique, it answers what the doc already decides.
 *
 * Three documented decision surfaces are implemented, one per concrete contract
 * in the doc:
 *
 *   1. Posture classification ("Research Areas And Atlas Posture" table). The
 *      doc assigns each of twelve research areas exactly one of a closed set of
 *      postures (adopt_with_internal_measure, watch, runtime_optimization,
 *      adopt_later_with_AP, research_only, aligned, candidate, future_AP,
 *      adopt_concepts). classifyArea() returns the area's posture plus whether
 *      that posture, on its own, lets the technique reach code now. Only
 *      `aligned` is implementable now WITHOUT a fresh Architecture Packet; every
 *      other posture requires the promotion rule first (or is pure research /
 *      runtime-only / watch).
 *
 *   2. Research Promotion Rule ("Research Promotion Rule"). A technique from the
 *      map becomes implementable ONLY when it carries all SEVEN named items
 *      (AP, provider-safe contract, deterministic fallback, internal measurement,
 *      failure modes, evidence/replay, architecture-validate + docs-health
 *      green). evaluatePromotion() enforces the AND across all seven, fails
 *      closed on any miss, and lists exactly which items are missing. No partial
 *      promotion is possible.
 *
 *   3. Guardrails ("Guardrails"). Six hard prohibitions (huge window to mask bad
 *      retrieval, KV compression as auditable memory, indexing raw transcript as
 *      memory, vector similarity before privacy/scope/trust filters, abstractive
 *      compression of code/logs/contracts without a recoverable original,
 *      promoting an external measurement as Atlas proof without an internal golden
 *      set). screenAction() rejects any proposed action that trips a guardrail
 *      and names the violated guardrails; the rejection is fail-closed.
 *
 * promote() composes all three: an area's posture, the seven-item gate and the
 * guardrail screen must ALL clear before a technique is reported as
 * `implementable`. Any failure downgrades it to research/blocked with reasons.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/state-of-art-research-map.md
 */
final class AtlasStateOfArtResearchMapService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA_VERSION = 'atlas.cognitive_runtime.state_of_art_research_map.v1';

    /** Closed set of Atlas postures used by the research-areas table. */
    public const POSTURE_ADOPT_WITH_INTERNAL_MEASURE = 'adopt_with_internal_measure';
    public const POSTURE_WATCH = 'watch';
    public const POSTURE_RUNTIME_OPTIMIZATION = 'runtime_optimization';
    public const POSTURE_ADOPT_LATER_WITH_AP = 'adopt_later_with_AP';
    public const POSTURE_RESEARCH_ONLY = 'research_only';
    public const POSTURE_ALIGNED = 'aligned';
    public const POSTURE_CANDIDATE = 'candidate';
    public const POSTURE_FUTURE_AP = 'future_AP';
    public const POSTURE_ADOPT_CONCEPTS = 'adopt_concepts';

    /**
     * The twelve documented research areas mapped to their canonical posture.
     * Verbatim from the "Research Areas And Atlas Posture" table.
     *
     * @var array<string, string>
     */
    public const AREA_POSTURE = [
        'long_context_models' => self::POSTURE_ADOPT_WITH_INTERNAL_MEASURE,
        'position_extension' => self::POSTURE_WATCH,
        'efficient_attention' => self::POSTURE_RUNTIME_OPTIMIZATION,
        'prefix_kv_cache' => self::POSTURE_ADOPT_LATER_WITH_AP,
        'kv_compression' => self::POSTURE_RESEARCH_ONLY,
        'hybrid_rag' => self::POSTURE_ALIGNED,
        'long_contextual_rag' => self::POSTURE_CANDIDATE,
        'representation_retrieval' => self::POSTURE_FUTURE_AP,
        'agentic_memory' => self::POSTURE_ALIGNED,
        'prompt_compression' => self::POSTURE_CANDIDATE,
        'alternative_architectures' => self::POSTURE_WATCH,
        'context_evaluation_suites' => self::POSTURE_ADOPT_CONCEPTS,
    ];

    /**
     * The seven items a technique MUST carry before the "Research Promotion Rule"
     * lets it become implementable. Order mirrors the doc's numbered list.
     *
     * @var list<string>
     */
    public const PROMOTION_REQUIREMENTS = [
        'ap_or_explicit_inclusion',   // 1. AP propria ou inclusao explicita em AP existente
        'provider_safe_contract',     // 2. contrato provider-safe
        'deterministic_fallback',     // 3. fallback deterministico
        'internal_measurement',       // 4. medida interna (golden set interno do Atlas)
        'failure_modes',              // 5. failure modes
        'evidence_replay',            // 6. evidence/replay
        'gates_green',                // 7. architecture-validate e docs-health verdes
    ];

    /**
     * The six documented Guardrails. Each key is the prohibition flag a proposed
     * action would raise; the value is the human-readable rule text.
     *
     * @var array<string, string>
     */
    public const GUARDRAILS = [
        'huge_window_masks_retrieval' => 'Nao usar janela gigante para compensar retrieval ruim.',
        'kv_compression_as_audit_memory' => 'Nao usar KV compression como memoria auditavel.',
        'raw_transcript_as_memory' => 'Nao indexar raw transcript como memoria.',
        'vector_before_privacy_filters' => 'Nao usar vector similarity antes de privacy/scope/trust filters.',
        'abstractive_compress_without_original' => 'Nao comprimir codigo, logs ou contratos de forma abstrativa sem original recuperavel.',
        'external_measure_as_proof' => 'Nao promover medida externa como prova do Atlas sem golden set interno.',
    ];

    /**
     * Postures that, on their own, let a technique be treated as implementable
     * NOW without first running the seven-item promotion rule. The doc marks the
     * already-compatible areas (Hybrid RAG, Agentic memory) as `aligned`; every
     * other posture is research / watch / optimization and still needs the gate.
     *
     * @var list<string>
     */
    private const IMPLEMENTABLE_NOW_POSTURES = [
        self::POSTURE_ALIGNED,
    ];

    /**
     * Classify a research area against the documented posture table.
     *
     * @return array{
     *   schema: string,
     *   area: string,
     *   known: bool,
     *   posture: ?string,
     *   implementable_now: bool,
     *   requires_promotion_rule: bool,
     *   reason: string
     * }
     */
    public function classifyArea(string $area): array
    {
        $key = $this->normalizeArea($area);
        $known = array_key_exists($key, self::AREA_POSTURE);
        $posture = $known ? self::AREA_POSTURE[$key] : null;

        // Unknown area: the doc only governs the twelve listed areas; anything
        // outside the map is, by default, not implementable and must be routed
        // back to research. Fail closed.
        if (! $known) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'area' => $key,
                'known' => false,
                'posture' => null,
                'implementable_now' => false,
                'requires_promotion_rule' => true,
                'reason' => 'area is not on the research map; route to research before any implementation',
            ];
        }

        $implementableNow = in_array($posture, self::IMPLEMENTABLE_NOW_POSTURES, true);

        return [
            'schema' => self::SCHEMA_VERSION,
            'area' => $key,
            'known' => true,
            'posture' => $posture,
            'implementable_now' => $implementableNow,
            'requires_promotion_rule' => ! $implementableNow,
            'reason' => $implementableNow
                ? "posture '{$posture}' is already aligned with an Atlas DoD; implement as governed memory/retrieval"
                : "posture '{$posture}' is not implementable on its own; run the Research Promotion Rule first",
        ];
    }

    /**
     * Evaluate the "Research Promotion Rule": a technique is promotable to
     * implementable ONLY when all seven items are present. Fails closed on any
     * miss and reports exactly which items are missing.
     *
     * @param  array<string, mixed>  $checklist  flags keyed by PROMOTION_REQUIREMENTS
     * @return array{
     *   schema: string,
     *   promotable: bool,
     *   satisfied: list<string>,
     *   missing: list<string>,
     *   required_count: int,
     *   satisfied_count: int,
     *   reason: string
     * }
     */
    public function evaluatePromotion(array $checklist): array
    {
        $satisfied = [];
        $missing = [];

        foreach (self::PROMOTION_REQUIREMENTS as $requirement) {
            if (($checklist[$requirement] ?? false) === true) {
                $satisfied[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $promotable = $missing === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'promotable' => $promotable,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'required_count' => count(self::PROMOTION_REQUIREMENTS),
            'satisfied_count' => count($satisfied),
            'reason' => $promotable
                ? 'all seven promotion items present; technique may become implementable'
                : 'promotion rule not met; technique stays as research until missing items are supplied',
        ];
    }

    /**
     * Screen a proposed action against the six Guardrails. Any raised guardrail
     * flag rejects the action (fail-closed) and the violated rules are named.
     *
     * @param  array<string, mixed>  $action  guardrail flags keyed by GUARDRAILS
     * @return array{
     *   schema: string,
     *   allowed: bool,
     *   violations: list<string>,
     *   violation_texts: list<string>,
     *   reason: string
     * }
     */
    public function screenAction(array $action): array
    {
        $violations = [];
        $texts = [];

        foreach (self::GUARDRAILS as $flag => $text) {
            if (($action[$flag] ?? false) === true) {
                $violations[] = $flag;
                $texts[] = $text;
            }
        }

        $allowed = $violations === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'allowed' => $allowed,
            'violations' => $violations,
            'violation_texts' => $texts,
            'reason' => $allowed
                ? 'no guardrail tripped; action respects the research-map prohibitions'
                : 'action rejected: it trips a research-map guardrail',
        ];
    }

    /**
     * Compose the full promotion decision: an area's posture, the seven-item gate
     * and the guardrail screen must ALL clear before a technique is reported as
     * implementable. Any failure downgrades it to research/blocked with reasons.
     *
     * @param  array<string, mixed>  $promotionChecklist  flags keyed by PROMOTION_REQUIREMENTS
     * @param  array<string, mixed>  $guardrailFlags      flags keyed by GUARDRAILS
     * @return array{
     *   schema: string,
     *   area: array<string, mixed>,
     *   promotion: array<string, mixed>,
     *   guardrails: array<string, mixed>,
     *   status: string,
     *   implementable: bool,
     *   blocking_reasons: list<string>
     * }
     */
    public function promote(string $area, array $promotionChecklist, array $guardrailFlags = []): array
    {
        $classification = $this->classifyArea($area);
        $promotion = $this->evaluatePromotion($promotionChecklist);
        $screen = $this->screenAction($guardrailFlags);

        $blocking = [];

        // A guardrail violation always blocks, regardless of posture or gate.
        if (! $screen['allowed']) {
            foreach ($screen['violations'] as $violation) {
                $blocking[] = "guardrail:{$violation}";
            }
        }

        // If the posture is already aligned, the technique is implementable as
        // governed memory/retrieval and does NOT need the seven-item gate — but
        // it still must pass guardrails. Otherwise the promotion rule must pass.
        $needsPromotion = $classification['requires_promotion_rule'];

        if ($needsPromotion && ! $promotion['promotable']) {
            foreach ($promotion['missing'] as $missing) {
                $blocking[] = "promotion:{$missing}";
            }
        }

        if (! $classification['known']) {
            $blocking[] = 'area:not_on_research_map';
        }

        $implementable = $blocking === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'area' => $classification,
            'promotion' => $promotion,
            'guardrails' => $screen,
            'status' => $implementable ? 'implementable' : 'blocked',
            'implementable' => $implementable,
            'blocking_reasons' => array_values($blocking),
        ];
    }

    /**
     * Normalize a free-form area label to a research-map key
     * (lowercase, non-alphanumeric collapsed to a single underscore).
     */
    private function normalizeArea(string $area): string
    {
        $slug = strtolower(trim($area));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);

        return trim($slug, '_');
    }
}
