<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Code Category Evolution decider.
 *
 * Pure, deterministic runtime for the canonical category-definition doc. The doc
 * fixes two things that this service turns into a contract that cannot be
 * misstated:
 *
 *  1. The Category Stack + Natural Progression ladder (doc "Category Stack" and
 *     "Natural Progression"): a strict, ordered six-level ladder with a named
 *     category/maturity, primary question, human posture and Atlas posture at
 *     each level. Levels 1..3 (IDE, AI-native IDE, AI coding agent) are the
 *     *lower* categories Atlas Code explicitly is NOT; the Atlas Code band is
 *     levels 4..6 (Engineering Operations System / Operating Room, Software
 *     Evolution Operating System, Autonomous Software Organism). The classifier
 *     never lets a higher level be claimed before its predecessors, and reports
 *     the Atlas-band membership for any level.
 *
 *  2. The Boundary Rules (doc "Boundary Rules"): each Atlas-band level forbids a
 *     specific over-claim — Level 4 is NOT autonomous self-evolution; Level 5 is
 *     NOT uncontrolled auto-coding; Level 6 is NOT free self-modification; and at
 *     every level "Chat output is never proof. Files, receipts, tests, evidence
 *     and review are proof." This service evaluates a stated claim about a level
 *     and rejects category confusion and the documented over-autonomy/over-proof
 *     framings, naming the exact boundary rule violated.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes" and "Usar
 * Engineering Operations System como substituto de Atlas Agentic Engineering OS".
 * The classifier therefore (a) never grants the autonomous-organism level by
 * silence and (b) flags any framing that promotes EOS to the whole engineering
 * area instead of the Atlas Code product/surface category.
 *
 * NEVER calls a provider. No database. No I/O.
 *
 * @see docs/engineering-knowledge-base/atlas-code-category-evolution.md
 */
class AtlasCodeCategoryEvolutionService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.code_category_evolution.v1';

    /** Doc: the new product class Atlas Code creates and occupies. */
    public const CATEGORY_NAME = 'Engineering Operations System';

    /** Doc "Category Stack": the canonical layer mapping for Atlas Code. */
    public const CATEGORY_STACK = [
        'category' => 'Engineering Operations System',
        'product_surface' => 'Atlas Code',
        'first_enterprise_mvp' => 'Atlas Code SCOR-1',
        'initial_experience' => 'Software Construction Operating Room',
        'next_maturity' => 'Software Evolution Operating System',
        'long_term_horizon' => 'Autonomous Software Organism',
    ];

    /** Highest documented level (Autonomous Software Organism). */
    public const MAX_LEVEL = 6;

    /** First level inside the Atlas Code category band (EOS / Operating Room). */
    public const ATLAS_BAND_FLOOR = 4;

    /**
     * Doc "Natural Progression" — the six ordered levels. Levels 1..3 are the
     * lower categories Atlas Code is NOT; 4..6 are the Atlas Code maturity band.
     *
     * @var array<int,array{name:string,question:string,human:string,atlas:string,atlas_band:bool}>
     */
    public const LADDER = [
        1 => [
            'name' => 'IDE',
            'question' => 'Where do I edit code?',
            'human' => 'Writer',
            'atlas' => 'Tool host',
            'atlas_band' => false,
        ],
        2 => [
            'name' => 'AI-native IDE',
            'question' => 'Can AI help me edit?',
            'human' => 'Writer with assistant',
            'atlas' => 'Suggestion layer',
            'atlas_band' => false,
        ],
        3 => [
            'name' => 'AI coding agent',
            'question' => 'Can an agent do this task?',
            'human' => 'Requester and reviewer',
            'atlas' => 'Single worker',
            'atlas_band' => false,
        ],
        4 => [
            'name' => 'Engineering Operations System / Operating Room',
            'question' => 'Can Atlas safely build this?',
            'human' => 'Director and signer',
            'atlas' => 'Builder under contract',
            'atlas_band' => true,
        ],
        5 => [
            'name' => 'Software Evolution Operating System',
            'question' => 'What should evolve next?',
            'human' => 'Strategist and governor',
            'atlas' => 'Diagnostician, proposer and executor',
            'atlas_band' => true,
        ],
        6 => [
            'name' => 'Autonomous Software Organism',
            'question' => 'Can Atlas preserve and evolve itself?',
            'human' => 'Constitutional authority',
            'atlas' => 'Governed self-maintaining system',
            'atlas_band' => true,
        ],
    ];

    /**
     * Doc "Boundary Rules" — the forbidden over-claim at each Atlas-band level.
     * A claim that asserts the forbidden framing for its level is a violation.
     *
     * @var array<int,array{rule:string,forbidden:string,pattern:string}>
     */
    public const BOUNDARY_RULES = [
        4 => [
            'rule' => 'level_4_is_governed_construction_not_self_evolution',
            'forbidden' => 'Level 4 is not autonomous self-evolution; it is governed construction.',
            'pattern' => '/\b(self[\s-]?evolv|autonomous\s+self|auto[\s-]?evolv)/i',
        ],
        5 => [
            'rule' => 'level_5_is_evidence_driven_evolution_not_uncontrolled_auto_coding',
            'forbidden' => 'Level 5 is not uncontrolled auto-coding; it is evidence-driven evolution with proposal, receipt and policy.',
            'pattern' => '/\b(uncontrolled|unchecked|auto[\s-]?cod)/i',
        ],
        6 => [
            'rule' => 'level_6_is_governed_autonomy_not_free_self_modification',
            'forbidden' => 'Level 6 is not free self-modification; it is autonomy constrained by constitution, policy, evidence, budget, rollback and human governance.',
            'pattern' => '/\b(free\s+self|unconstrained|unrestricted|without\s+governance|no\s+governance)/i',
        ],
    ];

    /**
     * Resolve a single ladder level into its documented framing.
     *
     * @return array<string,mixed>
     */
    public function level(int $level): array
    {
        if (! isset(self::LADDER[$level])) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'known' => false,
                'level' => $level,
            ];
        }

        $row = self::LADDER[$level];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'known' => true,
            'level' => $level,
            'name' => $row['name'],
            'question' => $row['question'],
            'human' => $row['human'],
            'atlas' => $row['atlas'],
            'atlas_band' => $row['atlas_band'],
            'is_max' => $level === self::MAX_LEVEL,
        ];
    }

    /**
     * The full ordered ladder (doc "Natural Progression").
     *
     * @return list<array<string,mixed>>
     */
    public function ladder(): array
    {
        $out = [];
        foreach (array_keys(self::LADDER) as $level) {
            $out[] = $this->level($level);
        }

        return $out;
    }

    /**
     * The canonical Category Stack mapping (doc "Category Stack").
     *
     * @return array<string,string>
     */
    public function categoryStack(): array
    {
        return self::CATEGORY_STACK;
    }

    /**
     * Classify a claimed maturity level against the documented ladder and
     * Boundary Rules.
     *
     * Rules enforced, in order:
     *  - the level must be a known ladder level (1..6);
     *  - the ladder is ordered: a level is only reachable when every lower level
     *    has been reached. `reached_levels` lists which predecessor categories
     *    the claimant asserts. A gap (claiming level N while a lower level is not
     *    reached) is rejected as a non-contiguous skip naming the first gap;
     *  - the stated framing must not assert the forbidden over-claim for that
     *    level (Boundary Rules), e.g. Level 4 claiming self-evolution;
     *  - "chat output is proof" is rejected at every level (doc Boundary Rules:
     *    "Chat output is never proof.");
     *  - any framing that promotes EOS to the whole engineering area (rather than
     *    the Atlas Code product/surface category) is rejected (doc frontmatter
     *    `forbidden_changes`).
     *
     * @param  array{level?:int,framing?:string,reached_levels?:list<int>,proof_is_chat?:bool}  $claim
     * @return array<string,mixed>
     */
    public function classifyClaim(array $claim): array
    {
        $level = is_int($claim['level'] ?? null) ? $claim['level'] : 0;
        $framing = is_string($claim['framing'] ?? null) ? $claim['framing'] : '';
        $reached = $this->intList($claim['reached_levels'] ?? null);
        $proofIsChat = ($claim['proof_is_chat'] ?? null) === true;

        $violations = [];

        if (! isset(self::LADDER[$level])) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'level' => $level,
                'valid' => false,
                'status' => 'unknown_level',
                'is_atlas_band' => false,
                'violations' => [['rule' => 'unknown_ladder_level', 'detail' => 'Level is outside the documented 1..6 ladder.']],
            ];
        }

        $row = self::LADDER[$level];

        // Ordering: the ladder is a strict progression. Find the first lower
        // level the claimant has NOT reached — that gap caps the valid claim.
        $reachedSet = array_fill_keys($reached, true);
        $firstGap = null;
        for ($lower = 1; $lower < $level; $lower++) {
            if (! isset($reachedSet[$lower])) {
                $firstGap = $lower;
                break;
            }
        }
        if ($firstGap !== null) {
            $violations[] = [
                'rule' => 'non_contiguous_level_skip',
                'detail' => 'Claimed level '.$level.' but level '.$firstGap.' ('.self::LADDER[$firstGap]['name'].') is not reached.',
                'first_gap_level' => $firstGap,
            ];
        }

        // Boundary Rule for this level (only Atlas-band levels carry one).
        if (isset(self::BOUNDARY_RULES[$level]) && $framing !== '') {
            $br = self::BOUNDARY_RULES[$level];
            if (preg_match($br['pattern'], $framing) === 1) {
                $violations[] = [
                    'rule' => $br['rule'],
                    'detail' => $br['forbidden'],
                ];
            }
        }

        // EOS-as-whole-area confusion (forbidden at any level that names EOS).
        if ($framing !== '' && $this->framingPromotesEosToWholeArea($framing)) {
            $violations[] = [
                'rule' => 'eos_is_category_not_the_whole_engineering_area',
                'detail' => 'Engineering Operations System names the Atlas Code product/surface category, not the whole Agentic Software Engineering area.',
            ];
        }

        // Chat-as-proof is rejected everywhere (doc Boundary Rules).
        if ($proofIsChat) {
            $violations[] = [
                'rule' => 'chat_output_is_never_proof',
                'detail' => 'Files, receipts, tests, evidence and review are proof; chat output is never proof.',
            ];
        }

        $valid = $violations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $level,
            'level_name' => $row['name'],
            'is_atlas_band' => $row['atlas_band'],
            'valid' => $valid,
            'status' => $valid ? 'claim_within_boundaries' : 'boundary_violation',
            'violations' => $violations,
        ];
    }

    /**
     * Does a framing string promote EOS to the whole engineering area instead of
     * the Atlas Code product/surface category? True only when it both names EOS
     * and asserts whole-area/replacement scope.
     */
    private function framingPromotesEosToWholeArea(string $framing): bool
    {
        $namesEos = preg_match('/\bengineering\s+operations\s+system\b|\bEOS\b/i', $framing) === 1;
        if (! $namesEos) {
            return false;
        }

        return preg_match('/\b(whole|entire|all\s+of|replaces?\s+the\s+area|whole\s+area|entire\s+area|agentic\s+software\s+engineering)\b/i', $framing) === 1;
    }

    /**
     * Normalize a mixed value into a list of ints.
     *
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
