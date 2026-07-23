<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane principles decider.
 *
 * Pure, deterministic enforcement of the three concrete governance systems the
 * Cognitive Plane doc defines. The service never mutates code, calls a
 * provider, runs a gate or touches the database — it classifies an input and
 * returns a typed verdict so a cognitive capability or an external suggestion
 * can never be admitted as default unseen.
 *
 * Concrete rules grounded in the doc:
 *   - "Authority"                       → conflict order thesis (Layer -1) >
 *     atlas hard principles (14) > cognitive principles (22) > anti-patterns;
 *     the highest-ranked authority wins a conflict.
 *   - "Hierarquia de Evidencia" (C15)   → every cognitive capability carries an
 *     `evidence_level`. consensus may become default after a contract test;
 *     emerging is opt-in until AP-99 + adversarial validation loop; contested is opt-in
 *     only, NEVER default and may NOT promise a gain; speculative is not a
 *     capability at all — it becomes a Curator proposal requiring validation.
 *   - "Filtro Critico" (C11)            → an external contribution runs the 6
 *     questions and receives exactly one of the 7 closed verdicts; competing
 *     with the Atlas channel or violating a hard principle is a rejection,
 *     unproven gain routes to validation, everything else is absorbed as
 *     source / capability / specialist profile / partial.
 *
 * @see docs/engineering-knowledge-base/cognitive/principles.md
 */
final class AtlasCognitivePrinciplesService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.cognitive.principles.v1';

    // ---- Authority order (lower rank = higher authority) --------------------

    public const AUTHORITY_THESIS = 'central_thesis';
    public const AUTHORITY_ATLAS_HARD_PRINCIPLES = 'atlas_hard_principles';
    public const AUTHORITY_COGNITIVE_PRINCIPLES = 'cognitive_principles';
    public const AUTHORITY_ANTI_PATTERNS = 'anti_patterns';

    /**
     * Ranked conflict-resolution chain. The doc's "Authority" section:
     * "Tese central (Layer -1) > principios duros do Atlas (14) > principios
     * cognitivos (22, este doc) > anti-patterns".
     *
     * @var array<string,int>
     */
    public const AUTHORITY_ORDER = [
        self::AUTHORITY_THESIS => 0,
        self::AUTHORITY_ATLAS_HARD_PRINCIPLES => 1,
        self::AUTHORITY_COGNITIVE_PRINCIPLES => 2,
        self::AUTHORITY_ANTI_PATTERNS => 3,
    ];

    // ---- Evidence levels (C15 capability gate) ------------------------------

    public const EVIDENCE_CONSENSUS = 'consensus';
    public const EVIDENCE_EMERGING = 'emerging';
    public const EVIDENCE_CONTESTED = 'contested';
    public const EVIDENCE_SPECULATIVE = 'speculative';

    /**
     * Canonical evidence levels, ordered strongest -> weakest.
     *
     * @var list<string>
     */
    public const EVIDENCE_LEVELS = [
        self::EVIDENCE_CONSENSUS,
        self::EVIDENCE_EMERGING,
        self::EVIDENCE_CONTESTED,
        self::EVIDENCE_SPECULATIVE,
    ];

    // ---- External-input verdicts (closed set of 7) --------------------------

    public const VERDICT_ACCEPTED_AS_SOURCE = 'accepted_as_source';
    public const VERDICT_ACCEPTED_AS_CAPABILITY = 'accepted_as_capability';
    public const VERDICT_ACCEPTED_AS_SPECIALIST_PROFILE = 'accepted_as_specialist_profile';
    public const VERDICT_FILTERED_PARTIAL = 'filtered_partial';
    public const VERDICT_REJECTED_COMPETE_WITH_ATLAS = 'rejected_compete_with_atlas';
    public const VERDICT_REJECTED_VIOLATES_PRINCIPLE = 'rejected_violates_principle';
    public const VERDICT_REQUIRES_ADVERSARIAL_VALIDATION = 'requires_adversarial_validation';

    /**
     * The 7 verdicts a critical-filter run can yield, in the doc's order.
     *
     * @var list<string>
     */
    public const EXTERNAL_INPUT_VERDICTS = [
        self::VERDICT_ACCEPTED_AS_SOURCE,
        self::VERDICT_ACCEPTED_AS_CAPABILITY,
        self::VERDICT_ACCEPTED_AS_SPECIALIST_PROFILE,
        self::VERDICT_FILTERED_PARTIAL,
        self::VERDICT_REJECTED_COMPETE_WITH_ATLAS,
        self::VERDICT_REJECTED_VIOLATES_PRINCIPLE,
        self::VERDICT_REQUIRES_ADVERSARIAL_VALIDATION,
    ];

    /**
     * The 6 critical-filter question keys, in the doc's order.
     *
     * @var list<string>
     */
    public const FILTER_QUESTIONS = [
        'multiplies_operator_output',   // Q1: multiplies vs competes with the channel
        'classification',               // Q2: capability | domain | parallel_product
        'violates_principle',           // Q3: violates a hard / cognitive principle
        'absorbable_as_source',         // Q4: can enter the canonical pipeline as input
        'gain_is_proven',               // Q5: AP-99 / Evidence vs opinion
        'reduces_mastery_cycle',        // Q6: shortens vs merely reorganizes
    ];

    /**
     * Resolve a conflict between cited authorities to the highest-ranked one.
     *
     * Implements the doc's "Authority": when authorities disagree, the central
     * thesis beats the 14 Atlas hard principles, which beat the 22 cognitive
     * principles, which beat anti-patterns.
     *
     * @param  list<string>  $authorities
     * @return array{resolved_authority:string,rank:int,ranked:list<array{authority:string,rank:int}>}
     */
    public function resolveAuthority(array $authorities): array
    {
        $ranked = [];
        foreach ($authorities as $authority) {
            if (! array_key_exists($authority, self::AUTHORITY_ORDER)) {
                continue;
            }
            $ranked[] = ['authority' => $authority, 'rank' => self::AUTHORITY_ORDER[$authority]];
        }

        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        $winner = $ranked[0] ?? ['authority' => self::AUTHORITY_THESIS, 'rank' => 0];

        return [
            'resolved_authority' => $winner['authority'],
            'rank' => $winner['rank'],
            'ranked' => $ranked,
        ];
    }

    /**
     * Classify a cognitive capability by its declared evidence level (C15).
     *
     * Encodes the doc's "Tratamento" column exactly:
     *   - consensus   → may become default after a contract test.
     *   - emerging    → opt-in / preview; default only after AP-99 + adversarial validation.
     *   - contested   → opt-in only, NEVER default, promising a gain is forbidden.
     *   - speculative → not a capability; becomes a Curator proposal that
     *     requires adversarial validation.
     *
     * An unknown level fails closed: treated as the weakest tier.
     *
     * @param  array{evidence_level?:string,promises_gain?:bool,has_ap99?:bool,has_adversarial_validation?:bool,passed_contract_test?:bool}  $capability
     * @return array{
     *   evidence_level:string,
     *   recognized:bool,
     *   may_be_default:bool,
     *   is_capability:bool,
     *   may_promise_gain:bool,
     *   requires_adversarial_validation:bool,
     *   treatment:string,
     *   block_reasons:list<string>
     * }
     */
    public function classifyEvidence(array $capability): array
    {
        $level = $capability['evidence_level'] ?? '';
        $recognized = in_array($level, self::EVIDENCE_LEVELS, true);
        // Fail closed: an undeclared / unknown level is treated as speculative.
        $effective = $recognized ? $level : self::EVIDENCE_SPECULATIVE;

        $promisesGain = (bool) ($capability['promises_gain'] ?? false);
        $hasAp99 = (bool) ($capability['has_ap99'] ?? false);
        $hasAdversarial = (bool) ($capability['has_adversarial_validation'] ?? false);
        $passedContractTest = (bool) ($capability['passed_contract_test'] ?? false);

        $blockReasons = [];
        if (! $recognized) {
            $blockReasons[] = 'evidence_level_undeclared_or_unknown';
        }

        switch ($effective) {
            case self::EVIDENCE_CONSENSUS:
                $isCapability = true;
                // "pode entrar como default apos teste de contrato".
                $mayBeDefault = $passedContractTest;
                $mayPromiseGain = $hasAp99; // C15: no "+X%" claim without AP-99.
                $requiresAdversarial = false;
                $treatment = 'default_after_contract_test';
                if (! $passedContractTest) {
                    $blockReasons[] = 'consensus_default_requires_contract_test';
                }
                break;

            case self::EVIDENCE_EMERGING:
                $isCapability = true;
                // "entra como opcional/preview; vira default apos AP-99 + adversarial validation".
                $mayBeDefault = $hasAp99 && $hasAdversarial;
                $mayPromiseGain = $hasAp99;
                $requiresAdversarial = ! $hasAdversarial;
                $treatment = 'optional_preview_until_ap99_and_adversarial';
                if (! $mayBeDefault) {
                    $blockReasons[] = 'emerging_default_requires_ap99_and_adversarial';
                }
                break;

            case self::EVIDENCE_CONTESTED:
                $isCapability = true;
                // "so opcional gamificado; nunca default; proibido prometer ganho".
                $mayBeDefault = false;
                $mayPromiseGain = false;
                $requiresAdversarial = false;
                $treatment = 'gamified_optional_never_default';
                $blockReasons[] = 'contested_may_never_be_default';
                if ($promisesGain) {
                    $blockReasons[] = 'contested_may_not_promise_gain';
                }
                break;

            case self::EVIDENCE_SPECULATIVE:
            default:
                // "nao entra como capability; vira proposal pro Curator com
                //  requires_adversarial_validation".
                $isCapability = false;
                $mayBeDefault = false;
                $mayPromiseGain = false;
                $requiresAdversarial = true;
                $treatment = 'not_a_capability_curator_proposal';
                $blockReasons[] = 'speculative_is_not_a_capability';
                break;
        }

        // C15 cross-cutting: ANY level promising a gain without AP-99 is a block.
        if ($promisesGain && ! $hasAp99 && ! in_array('contested_may_not_promise_gain', $blockReasons, true)) {
            $blockReasons[] = 'promised_gain_without_ap99';
            $mayPromiseGain = false;
        }

        return [
            'evidence_level' => $effective,
            'recognized' => $recognized,
            'may_be_default' => $mayBeDefault,
            'is_capability' => $isCapability,
            'may_promise_gain' => $mayPromiseGain,
            'requires_adversarial_validation' => $requiresAdversarial,
            'treatment' => $treatment,
            'block_reasons' => array_values(array_unique($blockReasons)),
        ];
    }

    /**
     * Run the 6-question critical filter over one external contribution and
     * return exactly one of the 7 closed verdicts.
     *
     * Decision order (most-restrictive first, mirroring the doc):
     *   1. Violates a hard / cognitive principle      → rejected_violates_principle
     *   2. Competes with the Atlas channel             → rejected_compete_with_atlas
     *      (a "parallel_product" classification, or Q1 false, competes)
     *   3. Gain unproven but otherwise admissible      → requires_adversarial_validation
     *   4. Otherwise absorbed, routed by classification:
     *        - specialist_profile classification       → accepted_as_specialist_profile
     *        - capability classification               → accepted_as_capability
     *        - partially accepted                      → filtered_partial
     *        - source/domain input                     → accepted_as_source
     *
     * @param  array{
     *   multiplies_operator_output?:bool,
     *   classification?:string,
     *   violates_principle?:bool,
     *   absorbable_as_source?:bool,
     *   gain_is_proven?:bool,
     *   reduces_mastery_cycle?:bool,
     *   partially_accepted?:bool
     * }  $input
     * @return array{verdict:string,accepted:bool,reasons:list<string>,answers:array<string,mixed>}
     */
    public function filterExternalInput(array $input): array
    {
        $answers = [
            'multiplies_operator_output' => (bool) ($input['multiplies_operator_output'] ?? false),
            'classification' => (string) ($input['classification'] ?? 'parallel_product'),
            'violates_principle' => (bool) ($input['violates_principle'] ?? false),
            'absorbable_as_source' => (bool) ($input['absorbable_as_source'] ?? false),
            'gain_is_proven' => (bool) ($input['gain_is_proven'] ?? false),
            'reduces_mastery_cycle' => (bool) ($input['reduces_mastery_cycle'] ?? false),
        ];
        $partiallyAccepted = (bool) ($input['partially_accepted'] ?? false);

        $reasons = [];

        // Q3 — a principle violation is an unconditional rejection.
        if ($answers['violates_principle']) {
            $reasons[] = 'q3_violates_hard_or_cognitive_principle';

            return $this->verdict(self::VERDICT_REJECTED_VIOLATES_PRINCIPLE, false, $reasons, $answers);
        }

        // Q1 + Q2 — competing with the single Atlas channel (a parallel product,
        // or an input that does not multiply the operator's output) is rejected.
        if ($answers['classification'] === 'parallel_product' || ! $answers['multiplies_operator_output']) {
            if ($answers['classification'] === 'parallel_product') {
                $reasons[] = 'q2_parallel_product_competes_with_channel';
            }
            if (! $answers['multiplies_operator_output']) {
                $reasons[] = 'q1_does_not_multiply_competes_with_channel';
            }

            return $this->verdict(self::VERDICT_REJECTED_COMPETE_WITH_ATLAS, false, $reasons, $answers);
        }

        // At this point the input is admissible in principle.
        // Q5 — a capability with an unproven gain may only enter after adversarial validation.
        if ($answers['classification'] === 'capability' && ! $answers['gain_is_proven']) {
            $reasons[] = 'q5_gain_unproven_requires_adversarial_validation';

            return $this->verdict(self::VERDICT_REQUIRES_ADVERSARIAL_VALIDATION, false, $reasons, $answers);
        }

        // Partial acceptance is explicit and documented.
        if ($partiallyAccepted) {
            $reasons[] = 'partially_accepted_documented';

            return $this->verdict(self::VERDICT_FILTERED_PARTIAL, true, $reasons, $answers);
        }

        // Q2 routing for fully-absorbed contributions.
        return match ($answers['classification']) {
            'specialist_profile' => $this->verdict(
                self::VERDICT_ACCEPTED_AS_SPECIALIST_PROFILE,
                true,
                ['q2_specialist_profile_in_learning'],
                $answers,
            ),
            'capability' => $this->verdict(
                self::VERDICT_ACCEPTED_AS_CAPABILITY,
                true,
                ['q2_capability_promoted_to_core'],
                $answers,
            ),
            // domain / source contributions are absorbed as source/input.
            default => $this->verdict(
                self::VERDICT_ACCEPTED_AS_SOURCE,
                true,
                ['q4_absorbed_as_source_in_pipeline'],
                $answers,
            ),
        };
    }

    /**
     * Whether a cognitive capability is allowed to ship as a silent default.
     * Convenience boolean over {@see classifyEvidence()}; a capability that may
     * not be default is exactly the architectural bug C15 / architecture-validate
     * is meant to catch.
     *
     * @param  array{evidence_level?:string,promises_gain?:bool,has_ap99?:bool,has_adversarial_validation?:bool,passed_contract_test?:bool}  $capability
     */
    public function mayBeDefault(array $capability): bool
    {
        return $this->classifyEvidence($capability)['may_be_default'];
    }

    /**
     * Whether an external contribution is admitted in any accepted form.
     *
     * @param  array<string,mixed>  $input
     */
    public function isAccepted(array $input): bool
    {
        return $this->filterExternalInput($input)['accepted'];
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $answers
     * @return array{verdict:string,accepted:bool,reasons:list<string>,answers:array<string,mixed>}
     */
    private function verdict(string $verdict, bool $accepted, array $reasons, array $answers): array
    {
        return [
            'verdict' => $verdict,
            'accepted' => $accepted,
            'reasons' => array_values($reasons),
            'answers' => $answers,
        ];
    }
}
