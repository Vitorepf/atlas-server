<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Local Performance Memory Strategy — pure, deterministic enforcement
 * of the canonical contract for turning a local Mac with 48GB of RAM into an AI
 * substrate that improves quality, not just prompt size.
 *
 * "O ganho vem de contexto certo, nao de contexto enorme." This service mutates
 * nothing and never touches the database. It encodes the doc's concrete decision
 * rules so callers can validate them at runtime instead of trusting prose:
 *
 *   Memory pyramid (Piramide De Memoria) — L0 Prompt, L1 Hot RAM, L2 Local
 *     Index, L3 Canonical Store, L4 Archive. "O Atlas deve promover
 *     L3 -> L2 -> L1 -> L0 com filtros. Nunca pular direto de L3 bruto para L0."
 *     => promotion is legal only as adjacent single steps toward the prompt and
 *        only when a filter is declared on each hop; a raw jump skipping a level
 *        (especially L3->L0) is rejected.
 *
 *   Degradation under memory pressure (Como Usar Os 48GB) — "Se houver pressao
 *     de memoria, degrade assim: desligar modelo local -> reduzir hot cache ->
 *     reduzir rerank batch -> usar provider externo com Context Pack compacto."
 *     => the degradation ladder has exactly these four ordered actions and they
 *        are applied in that order as pressure rises; the last resort never
 *        drops the Context Pack — it falls back to a compact one.
 *
 *   RAM reservation (Como Usar Os 48GB table) — recommended initial split across
 *     system, Laravel/Postgres, Python RAG, optional local model and
 *     cache/indices. "O Atlas deve medir memoria real, nao assumir." => each use
 *     is clamped to its declared band; the plan reports whether the request fits
 *     the measured total and the optional local model is the first thing dropped
 *     on overcommit (consistent with the degradation ladder).
 *
 *   Quality gates (Gates De Qualidade) — every Context Pack built by RAM/local
 *     AI must pass eight gates: relevance score, source diversity, freshness +
 *     invalidation, authority check, privacy/provider-safety, token budget,
 *     contradiction scan, replay metadata. "Sem esses gates, a RAM vira
 *     acelerador de erro." => a pack is admissible only when ALL eight hold; any
 *     failing gate blocks the provider call.
 *
 *   Local model boundary (Tecnicas Prioritarias) — "Modelos locais podem
 *     reescrever query, sumarizar, reranquear, detectar anomalia e classificar
 *     privacidade. Nao podem decidir provider final, policy, autonomy, gate
 *     result ou apply." => the allowed set and the forbidden set are closed; any
 *     requested action outside the allowed set is denied.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
 */
final class AtlasAiLocalPerformanceMemoryStrategyService
{
    /** Stable schema id this strategy emits. */
    public const SCHEMA = 'atlas.local_performance_memory_strategy.v1';

    /**
     * Memory pyramid levels, ordered from the prompt (closest to the model) down
     * to the archive. Index 0 = L0 Prompt is the promotion target.
     *
     * @var list<string>
     */
    public const PYRAMID_LEVELS = [
        'L0_prompt',
        'L1_hot_ram',
        'L2_local_index',
        'L3_canonical_store',
        'L4_archive',
    ];

    /**
     * Degradation ladder under memory pressure, in the exact documented order.
     * Action 0 is taken first as pressure rises.
     *
     * @var list<string>
     */
    public const DEGRADATION_LADDER = [
        'disable_local_model',
        'reduce_hot_cache',
        'reduce_rerank_batch',
        'external_provider_compact_pack',
    ];

    /**
     * The eight mandatory Context Pack quality gates (closed set, ordered as in
     * "Gates De Qualidade").
     *
     * @var list<string>
     */
    public const QUALITY_GATES = [
        'relevance_score',
        'source_diversity',
        'freshness_invalidation',
        'authority_check',
        'privacy_provider_safety',
        'token_budget',
        'contradiction_scan',
        'replay_metadata',
    ];

    /**
     * What a local model is allowed to do (closed set).
     *
     * @var list<string>
     */
    public const LOCAL_MODEL_ALLOWED = [
        'rewrite_query',
        'summarize',
        'rerank',
        'detect_anomaly',
        'classify_privacy',
    ];

    /**
     * What a local model must never do (closed set).
     *
     * @var list<string>
     */
    public const LOCAL_MODEL_FORBIDDEN = [
        'decide_final_provider',
        'decide_policy',
        'decide_autonomy',
        'decide_gate_result',
        'apply',
    ];

    /**
     * Recommended initial RAM reservation bands per use, in GB [min, max], from
     * the "Como Usar Os 48GB" table. The optional local model is the first to be
     * dropped under overcommit.
     *
     * @var array<string,array{min:int,max:int,optional:bool,drop_order:int}>
     */
    public const RAM_BANDS = [
        'system_macos_apps' => ['min' => 8, 'max' => 12, 'optional' => false, 'drop_order' => 0],
        'laravel_postgres_queues' => ['min' => 3, 'max' => 6, 'optional' => false, 'drop_order' => 0],
        'python_rag_embeddings_rerank' => ['min' => 8, 'max' => 16, 'optional' => false, 'drop_order' => 0],
        'local_model_quantized' => ['min' => 8, 'max' => 20, 'optional' => true, 'drop_order' => 1],
        'cache_indices_hot_packs' => ['min' => 4, 'max' => 8, 'optional' => false, 'drop_order' => 2],
    ];

    /** Documented baseline total RAM the strategy is calibrated for, in GB. */
    public const DEFAULT_TOTAL_RAM_GB = 48;

    /**
     * Validate a proposed promotion of context up the pyramid toward the prompt.
     *
     * Legal promotion is a single adjacent step that moves UP (toward L0) and
     * declares a filter on the hop. A raw jump (skipping a level, e.g. L3->L0)
     * or any hop without a filter is rejected — "Nunca pular direto de L3 bruto
     * para L0."
     *
     * @return array{
     *   from:string,
     *   to:string,
     *   filtered:bool,
     *   legal:bool,
     *   verdict:string,
     *   reason:string,
     *   from_index:int,
     *   to_index:int,
     *   levels:list<string>
     * }
     */
    public function memoryPyramidPromotion(string $from, string $to, bool $filtered = true): array
    {
        $levels = self::PYRAMID_LEVELS;
        $fromIndex = array_search($from, $levels, true);
        $toIndex = array_search($to, $levels, true);

        if ($fromIndex === false || $toIndex === false) {
            return [
                'from' => $from,
                'to' => $to,
                'filtered' => $filtered,
                'legal' => false,
                'verdict' => 'rejected_unknown_level',
                'reason' => 'from/to must be one of '.implode(', ', $levels),
                'from_index' => -1,
                'to_index' => -1,
                'levels' => $levels,
            ];
        }

        // Lower index = closer to the prompt. Promotion moves toward L0, so the
        // destination index must be strictly smaller than the source index.
        $movesUp = $toIndex < $fromIndex;
        $isAdjacent = ($fromIndex - $toIndex) === 1;

        if (! $movesUp) {
            $verdict = 'rejected_wrong_direction';
            $reason = 'promotion must move toward L0 (prompt); this hop does not';
            $legal = false;
        } elseif (! $isAdjacent) {
            $verdict = 'rejected_raw_jump';
            $reason = 'must promote one level at a time (L3 -> L2 -> L1 -> L0); skipping a level is forbidden';
            $legal = false;
        } elseif (! $filtered) {
            $verdict = 'rejected_unfiltered';
            $reason = 'every promotion hop must declare a filter; raw promotion is forbidden';
            $legal = false;
        } else {
            $verdict = 'promotion_allowed';
            $reason = 'single filtered step toward the prompt';
            $legal = true;
        }

        return [
            'from' => $from,
            'to' => $to,
            'filtered' => $filtered,
            'legal' => $legal,
            'verdict' => $verdict,
            'reason' => $reason,
            'from_index' => $fromIndex,
            'to_index' => $toIndex,
            'levels' => $levels,
        ];
    }

    /**
     * Resolve the degradation actions to apply for a given memory-pressure level.
     *
     * Pressure is an integer step count 0..4. At step 0 nothing is degraded; each
     * additional step engages the next ladder action in the documented order.
     * The final action keeps a compact Context Pack — latency is never improved
     * by dropping the pack (the doc forbids sacrificing quality/citation).
     *
     * @return array{
     *   pressure_steps:int,
     *   ladder:list<string>,
     *   applied:list<string>,
     *   remaining:list<string>,
     *   local_model_active:bool,
     *   context_pack_preserved:bool,
     *   last_resort:bool
     * }
     */
    public function degradationPlan(int $pressureSteps): array
    {
        $ladder = self::DEGRADATION_LADDER;
        $steps = max(0, min($pressureSteps, count($ladder)));

        $applied = array_slice($ladder, 0, $steps);
        $remaining = array_slice($ladder, $steps);

        return [
            'pressure_steps' => $steps,
            'ladder' => $ladder,
            'applied' => array_values($applied),
            'remaining' => array_values($remaining),
            // The local model is the FIRST thing disabled; it is inactive the
            // moment any degradation is applied.
            'local_model_active' => $steps === 0,
            // The Context Pack is always preserved (compact at the last resort).
            'context_pack_preserved' => true,
            'last_resort' => $steps >= count($ladder),
        ];
    }

    /**
     * Build the RAM reservation plan and verify it against the measured total.
     *
     * Each requested use is clamped to its declared band [min, max]. The plan
     * reports the clamped total at the band minimums and maximums, whether the
     * max plan fits the measured total, and — when it overcommits — which uses to
     * drop in order (optional local model first, then cache/indices), mirroring
     * the degradation ladder.
     *
     * @param array<string,int> $requestedGb optional per-use override (clamped to band)
     *
     * @return array{
     *   total_ram_gb:int,
     *   bands:array<string,array{min:int,max:int,requested:int,clamped:int,optional:bool}>,
     *   min_total_gb:int,
     *   max_total_gb:int,
     *   requested_total_gb:int,
     *   fits_at_max:bool,
     *   fits_requested:bool,
     *   overcommit_gb:int,
     *   drop_order:list<string>,
     *   measure_dont_assume:bool
     * }
     */
    public function ramReservation(int $totalRamGb = self::DEFAULT_TOTAL_RAM_GB, array $requestedGb = []): array
    {
        $total = max(0, $totalRamGb);
        $bands = [];
        $minTotal = 0;
        $maxTotal = 0;
        $requestedTotal = 0;

        foreach (self::RAM_BANDS as $use => $band) {
            $requested = $requestedGb[$use] ?? $band['max'];
            $clamped = max($band['min'], min($requested, $band['max']));

            $bands[$use] = [
                'min' => $band['min'],
                'max' => $band['max'],
                'requested' => $requested,
                'clamped' => $clamped,
                'optional' => $band['optional'],
            ];

            $minTotal += $band['min'];
            $maxTotal += $band['max'];
            $requestedTotal += $clamped;
        }

        $fitsRequested = $requestedTotal <= $total;
        $overcommit = $fitsRequested ? 0 : ($requestedTotal - $total);

        // Drop order on overcommit: ascending drop_order, optional uses first.
        $dropCandidates = self::RAM_BANDS;
        uasort(
            $dropCandidates,
            static fn (array $a, array $b): int => $a['drop_order'] <=> $b['drop_order'],
        );
        $dropOrder = [];
        foreach ($dropCandidates as $use => $band) {
            if ($band['drop_order'] > 0) {
                $dropOrder[] = $use;
            }
        }

        return [
            'total_ram_gb' => $total,
            'bands' => $bands,
            'min_total_gb' => $minTotal,
            'max_total_gb' => $maxTotal,
            'requested_total_gb' => $requestedTotal,
            'fits_at_max' => $maxTotal <= $total,
            'fits_requested' => $fitsRequested,
            'overcommit_gb' => $overcommit,
            'drop_order' => $dropOrder,
            'measure_dont_assume' => true,
        ];
    }

    /**
     * Evaluate the eight mandatory Context Pack quality gates.
     *
     * A pack is admissible for the provider call only when ALL eight gates pass —
     * "Sem esses gates, a RAM vira acelerador de erro." Any gate not explicitly
     * marked true is treated as failing (gates are opt-in proof, not opt-out).
     *
     * @param array<string,bool> $gateResults caller-supplied gate evaluations
     *
     * @return array{
     *   required_gates:list<string>,
     *   gates:array<string,bool>,
     *   passed:list<string>,
     *   failed:list<string>,
     *   all_passed:bool,
     *   admissible:bool,
     *   verdict:string
     * }
     */
    public function qualityGates(array $gateResults): array
    {
        $gates = [];
        $passed = [];
        $failed = [];

        foreach (self::QUALITY_GATES as $gate) {
            $ok = ($gateResults[$gate] ?? false) === true;
            $gates[$gate] = $ok;
            if ($ok) {
                $passed[] = $gate;
            } else {
                $failed[] = $gate;
            }
        }

        $allPassed = $failed === [];

        return [
            'required_gates' => self::QUALITY_GATES,
            'gates' => $gates,
            'passed' => $passed,
            'failed' => $failed,
            'all_passed' => $allPassed,
            'admissible' => $allPassed,
            'verdict' => $allPassed ? 'context_pack_admissible' : 'blocked_failing_gate',
        ];
    }

    /**
     * Authorize a local-model action against the closed allowed/forbidden sets.
     *
     * Allowed: rewrite query, summarize, rerank, detect anomaly, classify
     * privacy. Forbidden: decide final provider, policy, autonomy, gate result,
     * apply. Anything not in the allowed set is denied — local models think, the
     * Kernel decides.
     *
     * @return array{
     *   action:string,
     *   allowed:bool,
     *   reason:string,
     *   allowed_actions:list<string>,
     *   forbidden_actions:list<string>
     * }
     */
    public function localModelCapability(string $action): array
    {
        $isAllowed = in_array($action, self::LOCAL_MODEL_ALLOWED, true);
        $isForbidden = in_array($action, self::LOCAL_MODEL_FORBIDDEN, true);

        if ($isAllowed) {
            $reason = 'local model may perform this preparatory/triage action';
        } elseif ($isForbidden) {
            $reason = 'decision authority belongs to the Kernel, not a local model';
        } else {
            $reason = 'action is not in the allowed local-model set; denied by default';
        }

        return [
            'action' => $action,
            'allowed' => $isAllowed,
            'reason' => $reason,
            'allowed_actions' => self::LOCAL_MODEL_ALLOWED,
            'forbidden_actions' => self::LOCAL_MODEL_FORBIDDEN,
        ];
    }

    /**
     * Aggregate self-check across all five rule blocks with documented defaults,
     * for the CLI and for a single-call audit of the strategy contract.
     *
     * @return array<string,mixed>
     */
    public function report(int $totalRamGb = self::DEFAULT_TOTAL_RAM_GB): array
    {
        return [
            'schema' => self::SCHEMA,
            'principle' => 'right context beats large context; RAM selects, compresses, ranks, validates and precomputes before the AI call',
            'pyramid' => [
                'levels' => self::PYRAMID_LEVELS,
                // The canonical staged path and a rejected raw jump, proven.
                'legal_step' => $this->memoryPyramidPromotion('L3_canonical_store', 'L2_local_index', true),
                'rejected_raw_jump' => $this->memoryPyramidPromotion('L3_canonical_store', 'L0_prompt', true),
            ],
            'degradation' => $this->degradationPlan(2),
            'ram_reservation' => $this->ramReservation($totalRamGb),
            'quality_gates_all_green' => $this->qualityGates(
                array_fill_keys(self::QUALITY_GATES, true),
            ),
            'local_model_boundary' => [
                'rerank_allowed' => $this->localModelCapability('rerank'),
                'apply_denied' => $this->localModelCapability('apply'),
            ],
        ];
    }
}
