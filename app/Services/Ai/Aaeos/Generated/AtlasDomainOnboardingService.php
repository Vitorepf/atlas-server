<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Domain Onboarding "Domain Test" decider.
 *
 * Pure, deterministic gate that turns the canonical Domain Onboarding doc's two
 * concrete contracts into an executable verdict, WITHOUT duplicating the part of
 * the doc that already has runtime.
 *
 * Scope split (deliberate, to avoid a duplicate):
 *
 *  - The doc's "Onboarding Phases" table (Phase 0 Charter .. Phase 8 Maturity
 *    Gate) is ALREADY implemented by App\Services\Ai\Kernel\Domain\
 *    AtlasDomainOnboardingScorecard, which scores an *accepted* domain's
 *    onboarding completeness across those nine phases. This service does NOT
 *    re-score them.
 *
 *  - The doc's "## Domain Test" section has NO runtime. It is the gate that
 *    decides, *before* onboarding, whether a candidate is a real domain at all
 *    or merely Business Context. This service implements exactly that.
 *
 * The doc states the contract verbatim:
 *
 *   "A new domain is valid when it has distinct:
 *      1. intents and flows;
 *      2. context model;
 *      3. specialist profiles;
 *      4. tools or runtimes;
 *      5. gates and evidence;
 *      6. memory projection;
 *      7. learning loop.
 *    If it is only a customer, company, project or product, it is Business
 *    Context."
 *
 * and: "Programming, Finance, Marketing, Cognitive/Learning, Personal
 * Development, Research, Writing, Health and Self-Improvement/Curator are valid
 * domain families. Companies and products attach as context."
 *
 * Enforced rules (each load-bearing, not a label):
 *
 *  1. Seven-criteria distinctness test. All seven must be present and distinct
 *     for a `domain` verdict. Any missing criterion -> the candidate is NOT yet
 *     a domain. The seven criteria are a closed, ordered set; the service names
 *     exactly which are missing.
 *
 *  2. Business-Context override. The doc says "If it is only a customer,
 *     company, project or product, it is Business Context." So even a candidate
 *     that nominally fills criteria is forced to `business_context` when it is
 *     flagged as a pure customer/company/project/product attachment. This is a
 *     hard override: a company can never be promoted to a domain by checklist
 *     padding.
 *
 *  3. Family gate. A `domain` verdict additionally requires the candidate to
 *     belong to one of the nine canonical domain families (closed set). A
 *     distinct-but-unfamilied candidate is routed to `needs_family_review`
 *     rather than silently accepted, because the doc lists the families as the
 *     valid set and says companies/products "attach as context".
 *
 *  4. Maturity ladder. The doc's Phase 8 names exactly four maturity rungs:
 *     scaffold -> pilot -> ready -> enterprise. The service exposes this ladder,
 *     normalises an arbitrary requested rung to the nearest valid rung, and
 *     refuses to let a freshly-accepted domain be born above `scaffold` (a new
 *     domain may not self-promote past the first rung).
 *
 * Verdict flow:
 *   business_context  : the Business-Context override fired (product-only).
 *   domain            : all 7 criteria distinct AND family is canonical.
 *   needs_family_review: all 7 criteria distinct but family not in the set.
 *   not_a_domain      : at least one of the 7 criteria is missing.
 *
 * The service is pure: it consumes a normalized candidate array and emits a
 * verdict. It never reads a doc, runs a harness, or touches a DB.
 *
 * @see docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
 */
final class AtlasDomainOnboardingService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.domain_onboarding.v1';

    /**
     * The seven distinctness criteria from the doc's "## Domain Test", in the
     * exact documented order. The candidate key for each criterion is the array
     * member name; the human label is the doc wording.
     *
     * @var array<string,string>
     */
    public const DOMAIN_TEST_CRITERIA = [
        'intents_and_flows' => 'distinct intents and flows',
        'context_model' => 'distinct context model',
        'specialist_profiles' => 'distinct specialist profiles',
        'tools_or_runtimes' => 'distinct tools or runtimes',
        'gates_and_evidence' => 'distinct gates and evidence',
        'memory_projection' => 'distinct memory projection',
        'learning_loop' => 'distinct learning loop',
    ];

    /**
     * The closed set of valid domain families (doc "## Current Domain Families").
     * Stored as canonical lower-snake tokens; aliases are normalised on input.
     *
     * @var array<int,string>
     */
    public const DOMAIN_FAMILIES = [
        'programming',
        'finance',
        'marketing',
        'cognitive_learning',
        'personal_development',
        'research',
        'writing',
        'health',
        'self_improvement_curator',
    ];

    /**
     * Family aliases -> canonical token. Lets callers pass the doc's display
     * spellings ("Cognitive/Learning", "Self-Improvement/Curator") and still hit
     * the closed set.
     *
     * @var array<string,string>
     */
    private const FAMILY_ALIASES = [
        'cognitive' => 'cognitive_learning',
        'learning' => 'cognitive_learning',
        'cognitive/learning' => 'cognitive_learning',
        'cognitive-learning' => 'cognitive_learning',
        'personal development' => 'personal_development',
        'personal-development' => 'personal_development',
        'self improvement' => 'self_improvement_curator',
        'self-improvement' => 'self_improvement_curator',
        'self-improvement/curator' => 'self_improvement_curator',
        'curator' => 'self_improvement_curator',
    ];

    /**
     * The four maturity rungs from the doc's Phase 8 "Maturity Gate", ordered
     * from least to most mature. A new domain may not be born above index 0.
     *
     * @var array<int,string>
     */
    public const MATURITY_LADDER = ['scaffold', 'pilot', 'ready', 'enterprise'];

    /** A freshly-accepted domain always starts on the first rung. */
    public const INITIAL_MATURITY = 'scaffold';

    /** Closed set of verdicts. */
    public const VERDICT_DOMAIN = 'domain';
    public const VERDICT_BUSINESS_CONTEXT = 'business_context';
    public const VERDICT_NEEDS_FAMILY_REVIEW = 'needs_family_review';
    public const VERDICT_NOT_A_DOMAIN = 'not_a_domain';

    /**
     * Classify a candidate against the Domain Test and the family gate.
     *
     * @param array{
     *   name?:string,
     *   intents_and_flows?:bool,
     *   context_model?:bool,
     *   specialist_profiles?:bool,
     *   tools_or_runtimes?:bool,
     *   gates_and_evidence?:bool,
     *   memory_projection?:bool,
     *   learning_loop?:bool,
     *   family?:string,
     *   is_company?:bool,
     *   is_customer?:bool,
     *   is_project?:bool,
     *   is_product?:bool,
     *   requested_maturity?:string
     * } $candidate
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   is_domain:bool,
     *   criteria:array<string,array{required:bool,present:bool,label:string}>,
     *   criteria_met:int,
     *   criteria_total:int,
     *   missing_criteria:array<int,string>,
     *   business_context_override:bool,
     *   business_context_signals:array<int,string>,
     *   family:array{requested:string,canonical:?string,valid:bool},
     *   initial_maturity:?string,
     *   reasons:array<int,string>
     * }
     */
    public function classify(array $candidate): array
    {
        $criteria = $this->criteria($candidate);
        $missing = [];
        $met = 0;
        foreach ($criteria as $key => $result) {
            if ($result['present']) {
                $met++;
            } else {
                $missing[] = $key;
            }
        }
        $allDistinct = $missing === [];

        $signals = $this->businessContextSignals($candidate);
        $override = $signals !== [];

        $family = $this->family($candidate);

        $reasons = [];
        $verdict = $this->verdict($allDistinct, $override, $family['valid'], $missing, $signals, $reasons);

        $isDomain = $verdict === self::VERDICT_DOMAIN;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'is_domain' => $isDomain,
            'criteria' => $criteria,
            'criteria_met' => $met,
            'criteria_total' => count(self::DOMAIN_TEST_CRITERIA),
            'missing_criteria' => array_values($missing),
            'business_context_override' => $override,
            'business_context_signals' => array_values($signals),
            'family' => $family,
            // Only an accepted domain gets a starting rung; everything else is null.
            'initial_maturity' => $isDomain ? self::INITIAL_MATURITY : null,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Evaluate the seven distinctness criteria as real predicates.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,array{required:bool,present:bool,label:string}>
     */
    public function criteria(array $candidate): array
    {
        $out = [];
        foreach (self::DOMAIN_TEST_CRITERIA as $key => $label) {
            $out[$key] = [
                'required' => true,
                'present' => ($candidate[$key] ?? false) === true,
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * The Business-Context signals the doc enumerates: "only a customer,
     * company, project or product". Returns each signal that fired.
     *
     * @param array<string,mixed> $candidate
     * @return array<int,string>
     */
    public function businessContextSignals(array $candidate): array
    {
        $signals = [];
        foreach (['is_customer', 'is_company', 'is_project', 'is_product'] as $flag) {
            if ((bool) ($candidate[$flag] ?? false)) {
                $signals[] = $flag;
            }
        }

        return array_values($signals);
    }

    /**
     * Resolve a requested family to the canonical closed set.
     *
     * @param array<string,mixed> $candidate
     * @return array{requested:string,canonical:?string,valid:bool}
     */
    public function family(array $candidate): array
    {
        $requested = trim((string) ($candidate['family'] ?? ''));
        $canonical = $this->normaliseFamily($requested);

        return [
            'requested' => $requested,
            'canonical' => $canonical,
            'valid' => $canonical !== null,
        ];
    }

    /**
     * Normalise an arbitrary requested maturity rung to the nearest valid rung
     * on the documented ladder, and clamp a brand-new domain to the first rung.
     *
     * @return array{requested:string,normalized:string,index:int,clamped_to_initial:bool}
     */
    public function resolveMaturity(string $requested, bool $isNewDomain = true): array
    {
        $req = strtolower(trim($requested));
        $index = array_search($req, self::MATURITY_LADDER, true);
        $normalized = $index === false ? self::INITIAL_MATURITY : $req;
        $normalizedIndex = $index === false ? 0 : (int) $index;

        $clamped = false;
        if ($isNewDomain && $normalizedIndex > 0) {
            // A new domain may not self-promote past scaffold (doc Phase 8).
            $normalized = self::INITIAL_MATURITY;
            $normalizedIndex = 0;
            $clamped = true;
        }

        return [
            'requested' => $requested,
            'normalized' => $normalized,
            'index' => $normalizedIndex,
            'clamped_to_initial' => $clamped,
        ];
    }

    /**
     * Decide the verdict and append human reasons.
     *
     * @param array<int,string> $missing
     * @param array<int,string> $signals
     * @param array<int,string> $reasons
     */
    private function verdict(
        bool $allDistinct,
        bool $override,
        bool $familyValid,
        array $missing,
        array $signals,
        array &$reasons,
    ): string {
        // Business-Context override is a hard rule and fires first: the doc says
        // a pure customer/company/project/product "is Business Context".
        if ($override) {
            $reasons[] = 'business_context_override:'.implode('+', $signals);

            return self::VERDICT_BUSINESS_CONTEXT;
        }

        if (! $allDistinct) {
            $reasons[] = 'missing_distinct_criteria:'.implode('+', $missing);

            return self::VERDICT_NOT_A_DOMAIN;
        }

        if (! $familyValid) {
            $reasons[] = 'family_not_in_canonical_set';

            return self::VERDICT_NEEDS_FAMILY_REVIEW;
        }

        $reasons[] = 'all_criteria_distinct_and_family_canonical';

        return self::VERDICT_DOMAIN;
    }

    private function normaliseFamily(string $requested): ?string
    {
        $key = strtolower(trim($requested));
        if ($key === '') {
            return null;
        }

        $canonical = str_replace(['/', '-', ' '], '_', $key);
        $canonical = preg_replace('/_+/', '_', $canonical) ?? $canonical;

        if (in_array($canonical, self::DOMAIN_FAMILIES, true)) {
            return $canonical;
        }

        if (isset(self::FAMILY_ALIASES[$key])) {
            return self::FAMILY_ALIASES[$key];
        }

        return null;
    }
}
