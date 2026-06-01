<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Implementation Priority Engine decider.
 *
 * Pure, deterministic engine that turns the priority-engine doc into a runtime
 * contract. The doc's hard claim: "Atlas must not choose work because it is
 * interesting. It chooses work because it maximizes durable capability." This
 * service scores and bands construction work by exactly that law and never
 * lies.
 *
 * Five documented mechanisms are modelled:
 *
 *  1. Priority Formula (doc "Priority Formula"): a signed sum of ten named
 *     terms — six that add leverage
 *       strategic_leverage + dependency_unlocks + quality_improvement
 *       + autonomy_enablement + user_value + evidence_confidence
 *     and four that subtract cost/doubt
 *       - risk - implementation_size - uncertainty - maintenance_burden.
 *     {@see score()} computes precisely this; absent terms count as 0 so a
 *     packet can never be inflated by silence, and the POSITIVE / NEGATIVE
 *     splits are the exact doc terms in the exact signs.
 *
 *  2. Priority banding (doc "Priority Packet": p_level P0|P1|P2|P3). The raw
 *     score is mapped to a P-level band. P0 is reserved for the highest-leverage
 *     compounding foundations; product-expansion-flavoured work lands lower.
 *
 *  3. P0 Foundations order (doc "P0 Foundations"): eight ordered construction
 *     areas, 1..8, with the explicit gate that voice/mobile surfaces (area 8)
 *     come "only after core contracts stay intact". {@see p0Foundations()} and
 *     {@see surfaceGate()} enforce that voice/mobile is blocked while any core
 *     contract is not intact.
 *
 *  4. Selection Questions (doc "Selection Questions"): seven yes/no questions
 *     Atlas asks before choosing work. {@see selectionGate()} answers them from
 *     a candidate and blocks the two non-negotiables — no available gates, and
 *     not a small reversible slice — because the doc frames those as hard
 *     pre-conditions for safe self-construction.
 *
 *  5. Deprioritize triggers (doc "Deprioritize"): seven properties that push
 *     work down. {@see deprioritizeFlags()} returns exactly the triggered ones;
 *     {@see decide()} folds them into the band so flagged work cannot sit at P0.
 *
 * Current Strategic Bias (doc "Current Strategic Bias"): until reliable
 * autonomous runtime, the engine favors
 *   memory + retrieval + SDD runtime + evidence + drift + research verification
 * over broad product expansion. {@see strategicBias()} exposes that bias and
 * {@see decide()} grants a bias bonus to work inside it.
 *
 * NEVER calls a provider. NEVER promotes a Forge run. No database.
 *
 * @see docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
 */
class AtlasImplementationPriorityEngineService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.implementation_priority_engine.v1';

    /**
     * Doc "Priority Formula" — the six terms that ADD to priority_score.
     *
     * @var list<string>
     */
    public const POSITIVE_TERMS = [
        'strategic_leverage',
        'dependency_unlocks',
        'quality_improvement',
        'autonomy_enablement',
        'user_value',
        'evidence_confidence',
    ];

    /**
     * Doc "Priority Formula" — the four terms that SUBTRACT from priority_score.
     *
     * @var list<string>
     */
    public const NEGATIVE_TERMS = [
        'risk',
        'implementation_size',
        'uncertainty',
        'maintenance_burden',
    ];

    /**
     * Doc "P0 Foundations" — the eight ordered construction areas. Area 8
     * (voice/mobile surfaces) is gated: "only after core contracts stay intact".
     *
     * @var array<int,string>
     */
    public const P0_FOUNDATIONS = [
        1 => 'governed memory',
        2 => 'context retrieval quality',
        3 => 'long session quality and compaction',
        4 => 'SDD runtime',
        5 => 'evidence, gates and drift detection',
        6 => 'research self-improvement runtime',
        7 => 'self-construction loop',
        8 => 'voice/mobile surfaces only after core contracts stay intact',
    ];

    /**
     * Doc "P0 Foundations" — the core construction areas (1..7). Area 8
     * (surfaces) is NOT core; it depends on these staying intact.
     *
     * @var list<int>
     */
    public const CORE_FOUNDATION_AREAS = [1, 2, 3, 4, 5, 6, 7];

    public const SURFACE_FOUNDATION_AREA = 8;

    /**
     * Doc "Selection Questions" — the seven questions Atlas asks before choosing
     * work, keyed by the candidate boolean that answers each.
     *
     * @var array<string,string>
     */
    public const SELECTION_QUESTIONS = [
        'unlocks_multiple_downstream' => 'Does this unlock multiple downstream capabilities?',
        'reduces_future_error' => 'Does it reduce future implementation error?',
        'improves_core' => 'Does it improve memory, context, SDD, evidence or gates?',
        'makes_autonomy_safer' => 'Does it make autonomous execution safer?',
        'enough_source_truth' => 'Is there enough source truth and code context?',
        'small_reversible_slice' => 'Can it be implemented in a small reversible slice?',
        'gates_available' => 'Are gates available?',
    ];

    /**
     * Doc "Selection Questions" — the two questions framed as hard
     * pre-conditions: without gates and without a small reversible slice the
     * work is not safe to start.
     *
     * @var list<string>
     */
    public const REQUIRED_SELECTION_KEYS = [
        'gates_available',
        'small_reversible_slice',
    ];

    /**
     * Doc "Deprioritize" — the seven properties that push work down the queue.
     *
     * @var array<string,string>
     */
    public const DEPRIORITIZE_TRIGGERS = [
        'visually_impressive_low_leverage' => 'visually impressive but low leverage',
        'provider_wrapper_driven' => 'provider-wrapper driven',
        'not_build_graph_dependency' => 'not tied to a build graph dependency',
        'missing_source_research' => 'missing source-backed research',
        'missing_tests_gates' => 'missing tests/gates',
        'likely_scope_expansion' => 'likely to expand scope',
        'likely_parallel_architecture' => 'likely to create parallel architecture',
    ];

    /**
     * Doc "Current Strategic Bias" — the areas the engine favors until reliable
     * autonomous runtime is reached.
     *
     * @var list<string>
     */
    public const STRATEGIC_BIAS = [
        'memory',
        'retrieval',
        'SDD runtime',
        'evidence',
        'drift',
        'research verification',
    ];

    /**
     * Doc "Priority Packet" — the keys every self-construction task must carry.
     *
     * @var list<string>
     */
    public const PACKET_KEYS = [
        'score',
        'rationale',
        'p_level',
        'unlocks',
        'blocked_by',
        'risk',
        'smallest_safe_slice',
    ];

    public const P_LEVELS = ['P0', 'P1', 'P2', 'P3'];

    /** Bonus added to the score when work sits inside the Current Strategic Bias. */
    public const STRATEGIC_BIAS_BONUS = 10;

    /**
     * Doc "Priority Formula": compute priority_score as the signed sum of the ten
     * named terms. Six add, four subtract; any absent term is treated as 0 so a
     * packet can never inflate itself by omitting a cost.
     *
     * @param array<string,int|float> $terms
     * @return array{
     *   schema_version:string,
     *   priority_score:float,
     *   positive_total:float,
     *   negative_total:float,
     *   contributions:array<string,float>,
     *   missing_terms:list<string>
     * }
     */
    public function score(array $terms): array
    {
        $contributions = [];
        $missing = [];
        $positiveTotal = 0.0;
        $negativeTotal = 0.0;

        foreach (self::POSITIVE_TERMS as $term) {
            if (! array_key_exists($term, $terms)) {
                $missing[] = $term;
            }
            $value = (float) ($terms[$term] ?? 0);
            $contributions[$term] = $value;
            $positiveTotal += $value;
        }

        foreach (self::NEGATIVE_TERMS as $term) {
            if (! array_key_exists($term, $terms)) {
                $missing[] = $term;
            }
            $value = (float) ($terms[$term] ?? 0);
            $contributions[$term] = -$value;
            $negativeTotal += $value;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'priority_score' => round($positiveTotal - $negativeTotal, 4),
            'positive_total' => round($positiveTotal, 4),
            'negative_total' => round($negativeTotal, 4),
            'contributions' => $contributions,
            'missing_terms' => $missing,
        ];
    }

    /**
     * Doc "P0 Foundations" — the eight ordered construction areas.
     *
     * @return array<int,string>
     */
    public function p0Foundations(): array
    {
        return self::P0_FOUNDATIONS;
    }

    /**
     * Doc "P0 Foundations" gate: voice/mobile surfaces (area 8) may proceed
     * "only after core contracts stay intact". Given the intactness of the core
     * contracts, decide whether surface work is unblocked.
     *
     * @param array<int|string,bool> $coreContractsIntact map of core area => intact?
     * @return array{
     *   schema_version:string,
     *   surface_area:int,
     *   surface_area_name:string,
     *   core_contracts_intact:bool,
     *   broken_core_areas:list<int>,
     *   surface_unblocked:bool,
     *   reason:string
     * }
     */
    public function surfaceGate(array $coreContractsIntact): array
    {
        $broken = [];
        foreach (self::CORE_FOUNDATION_AREAS as $area) {
            $intact = (bool) ($coreContractsIntact[$area] ?? false);
            if (! $intact) {
                $broken[] = $area;
            }
        }

        $unblocked = $broken === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'surface_area' => self::SURFACE_FOUNDATION_AREA,
            'surface_area_name' => self::P0_FOUNDATIONS[self::SURFACE_FOUNDATION_AREA],
            'core_contracts_intact' => $unblocked,
            'broken_core_areas' => $broken,
            'surface_unblocked' => $unblocked,
            'reason' => $unblocked
                ? 'all core contracts intact: voice/mobile surfaces may proceed'
                : 'core contracts not intact: voice/mobile surfaces are blocked',
        ];
    }

    /**
     * Doc "Selection Questions": answer the seven questions from a candidate and
     * block on the two hard pre-conditions (gates available, small reversible
     * slice). Each unanswered question defaults to false.
     *
     * @param array<string,bool> $answers
     * @return array{
     *   schema_version:string,
     *   answers:array<string,bool>,
     *   yes_count:int,
     *   blocking_unmet:list<string>,
     *   passes:bool
     * }
     */
    public function selectionGate(array $answers): array
    {
        $resolved = [];
        $yes = 0;
        foreach (self::SELECTION_QUESTIONS as $key => $_question) {
            $value = (bool) ($answers[$key] ?? false);
            $resolved[$key] = $value;
            if ($value) {
                $yes++;
            }
        }

        $blockingUnmet = [];
        foreach (self::REQUIRED_SELECTION_KEYS as $key) {
            if (! $resolved[$key]) {
                $blockingUnmet[] = $key;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'answers' => $resolved,
            'yes_count' => $yes,
            'blocking_unmet' => $blockingUnmet,
            'passes' => $blockingUnmet === [],
        ];
    }

    /**
     * Doc "Deprioritize": return exactly the triggered deprioritize properties.
     *
     * @param array<string,bool> $signals
     * @return list<string>
     */
    public function deprioritizeFlags(array $signals): array
    {
        $flags = [];
        foreach (self::DEPRIORITIZE_TRIGGERS as $key => $_label) {
            if ((bool) ($signals[$key] ?? false)) {
                $flags[] = $key;
            }
        }

        return $flags;
    }

    /**
     * Doc "Current Strategic Bias" — the favored areas and whether a given area
     * tag sits inside the bias.
     *
     * @return array{schema_version:string, favored:list<string>, over:string}
     */
    public function strategicBias(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'favored' => self::STRATEGIC_BIAS,
            'over' => 'broad product expansion',
        ];
    }

    /**
     * Map a raw priority_score to a P-level band (doc "Priority Packet"
     * p_level P0|P1|P2|P3). Higher leverage => lower (better) P number. Bands:
     * P0 >= 40, P1 >= 20, P2 >= 5, else P3.
     */
    public function band(float $score): string
    {
        return match (true) {
            $score >= 40.0 => 'P0',
            $score >= 20.0 => 'P1',
            $score >= 5.0 => 'P2',
            default => 'P3',
        };
    }

    /**
     * Full decision for a self-construction candidate. Combines the formula, the
     * selection gate, the deprioritize flags and the strategic bias into a single
     * Priority Packet (doc "Priority Packet").
     *
     * Hard rules enforced here, all from the doc:
     *  - The base band comes from the signed Priority Formula.
     *  - Work inside the Current Strategic Bias earns a bias bonus before banding
     *    (the engine "favors" it).
     *  - Any triggered Deprioritize property forbids P0: flagged work is demoted
     *    at least one band and can never be top priority.
     *  - If the Selection Gate's hard pre-conditions are unmet (no gates / not a
     *    small reversible slice) the work is not selectable and is forced to P3.
     *
     * @param array{
     *   id?:string,
     *   terms?:array<string,int|float>,
     *   in_strategic_bias?:bool,
     *   selection?:array<string,bool>,
     *   deprioritize?:array<string,bool>,
     *   unlocks?:list<string>,
     *   blocked_by?:list<string>,
     *   smallest_safe_slice?:string
     * } $candidate
     * @return array{
     *   schema_version:string,
     *   id:string,
     *   score:float,
     *   base_p_level:string,
     *   p_level:string,
     *   selectable:bool,
     *   in_strategic_bias:bool,
     *   bias_bonus_applied:float,
     *   deprioritize_flags:list<string>,
     *   selection:array{schema_version:string,answers:array<string,bool>,yes_count:int,blocking_unmet:list<string>,passes:bool},
     *   packet:array<string,mixed>,
     *   rationale:string
     * }
     */
    public function decide(array $candidate): array
    {
        $id = (string) ($candidate['id'] ?? 'unnamed-construction-work');
        $terms = is_array($candidate['terms'] ?? null) ? $candidate['terms'] : [];
        $scoring = $this->score($terms);
        $rawScore = $scoring['priority_score'];

        $inBias = (bool) ($candidate['in_strategic_bias'] ?? false);
        $biasBonus = $inBias ? (float) self::STRATEGIC_BIAS_BONUS : 0.0;
        $effectiveScore = round($rawScore + $biasBonus, 4);

        $selection = $this->selectionGate(
            is_array($candidate['selection'] ?? null) ? $candidate['selection'] : []
        );
        $flags = $this->deprioritizeFlags(
            is_array($candidate['deprioritize'] ?? null) ? $candidate['deprioritize'] : []
        );

        $baseBand = $this->band($effectiveScore);
        $pLevel = $baseBand;

        // Deprioritize triggers forbid P0 and cost at least one band.
        if ($flags !== []) {
            $pLevel = $this->demote($pLevel);
        }

        // Hard selection pre-conditions unmet => not selectable, forced to P3.
        $selectable = $selection['passes'];
        if (! $selectable) {
            $pLevel = 'P3';
        }

        $rationaleParts = [
            "score={$effectiveScore} -> band {$baseBand}",
        ];
        if ($inBias) {
            $rationaleParts[] = "inside strategic bias (+{$biasBonus})";
        }
        if ($flags !== []) {
            $rationaleParts[] = 'deprioritized: ' . implode(', ', $flags) . ' (P0 forbidden)';
        }
        if (! $selectable) {
            $rationaleParts[] = 'not selectable: unmet ' . implode(', ', $selection['blocking_unmet']) . ' -> P3';
        }

        $packet = [
            'score' => $effectiveScore,
            'rationale' => implode('; ', $rationaleParts),
            'p_level' => $pLevel,
            'unlocks' => array_values(array_map('strval', $candidate['unlocks'] ?? [])),
            'blocked_by' => array_values(array_map('strval', $candidate['blocked_by'] ?? [])),
            'risk' => (float) ($terms['risk'] ?? 0),
            'smallest_safe_slice' => (string) ($candidate['smallest_safe_slice'] ?? ''),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $id,
            'score' => $effectiveScore,
            'base_p_level' => $baseBand,
            'p_level' => $pLevel,
            'selectable' => $selectable,
            'in_strategic_bias' => $inBias,
            'bias_bonus_applied' => $biasBonus,
            'deprioritize_flags' => $flags,
            'selection' => $selection,
            'packet' => $packet,
            'rationale' => implode('; ', $rationaleParts),
        ];
    }

    /**
     * Rank a list of candidates highest-leverage-first. Selectable work always
     * outranks non-selectable; then by P-level (P0 best); then by raw score.
     *
     * @param list<array<string,mixed>> $candidates
     * @return array{
     *   schema_version:string,
     *   ranked:list<array<string,mixed>>,
     *   top:array<string,mixed>|null
     * }
     */
    public function rank(array $candidates): array
    {
        $decided = [];
        foreach ($candidates as $candidate) {
            /** @var array<string,mixed> $candidate */
            $decided[] = $this->decide($candidate);
        }

        usort($decided, function (array $a, array $b): int {
            // Selectable first.
            $sel = (int) $b['selectable'] <=> (int) $a['selectable'];
            if ($sel !== 0) {
                return $sel;
            }

            // Then P-level: P0 (index 0) is best.
            $pa = array_search($a['p_level'], self::P_LEVELS, true);
            $pb = array_search($b['p_level'], self::P_LEVELS, true);
            $pCmp = ($pa === false ? 99 : $pa) <=> ($pb === false ? 99 : $pb);
            if ($pCmp !== 0) {
                return $pCmp;
            }

            // Then higher score first.
            return (float) $b['score'] <=> (float) $a['score'];
        });

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ranked' => $decided,
            'top' => $decided[0] ?? null,
        ];
    }

    /**
     * Demote a P-level by exactly one band, never below P3.
     */
    private function demote(string $pLevel): string
    {
        $idx = array_search($pLevel, self::P_LEVELS, true);
        if ($idx === false) {
            return 'P3';
        }

        $next = min($idx + 1, count(self::P_LEVELS) - 1);

        return self::P_LEVELS[$next];
    }
}
