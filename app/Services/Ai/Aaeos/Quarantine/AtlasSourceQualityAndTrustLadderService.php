<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Source Quality And Trust Ladder decider.
 *
 * Pure, deterministic implementation of the research trust ladder: it turns an
 * external or internal information item into a tiered, promotion-bounded,
 * hallucination-checked research signal. Nothing here touches the database, a
 * provider or the filesystem — every method returns a typed decision array that
 * a caller may then act on (or refuse to act on).
 *
 * Contract (from the doc):
 *   - "Every research claim must carry a source tier."  (tier 0..5, closed set)
 *   - Each tier has a single allowed promotion target  (Promotion Rules table).
 *   - source_score is an explicit, reproducible sum of signed signals
 *     (Source Scoring Formula): positives add, negatives subtract.
 *   - Recent news requires the 6-step confirmation rule before a strong
 *     conclusion is allowed.
 *   - A research packet FAILS the Anti-Hallucination Gate on any of 7
 *     conditions; a failing packet may only be archived as a lead and can
 *     NEVER become memory truth, policy, routing, docs law or implementation.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
 */
final class AtlasSourceQualityAndTrustLadderService
{
    /** Stable receipt schema id for the decisions this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.source_trust_ladder.v1';

    /** Lowest (most trusted) and highest (least trusted) tier numbers. */
    public const TIER_MIN = 0;
    public const TIER_MAX = 5;

    /**
     * The closed Trust Tiers table from the doc: tier => [class, allowed_use].
     *
     * @var array<int,array{class:string,allowed_use:string}>
     */
    private const TIERS = [
        0 => [
            'class' => 'local_atlas_code_tests_canonical_docs_evidence_ledger_receipts',
            'allowed_use' => 'can_define_current_truth',
        ],
        1 => [
            'class' => 'official_provider_docs_standards_specs_release_notes_api_docs',
            'allowed_use' => 'can_define_external_capability_truth',
        ],
        2 => [
            'class' => 'peer_reviewed_papers_serious_evals_reproducible_evals',
            'allowed_use' => 'can_guide_architecture_and_metrics',
        ],
        3 => [
            'class' => 'high_quality_engineering_blogs_postmortems_open_source_code',
            'allowed_use' => 'can_guide_design_when_evidence_is_clear',
        ],
        4 => [
            'class' => 'community_threads_social_posts_videos_newsletters',
            'allowed_use' => 'lead_only_require_promotion_evidence',
        ],
        5 => [
            'class' => 'llm_answer_uncited_summary_rumor_marketing_claim',
            'allowed_use' => 'hypothesis_only_never_canonical_alone',
        ],
    ];

    /**
     * Promotion Rules from the doc: each tier can create exactly one artifact
     * kind. Tier 0 updates docs (with inspected repo evidence); the ladder gets
     * progressively weaker until tier 5 may only open a question.
     *
     * @var array<int,string>
     */
    private const PROMOTION_BY_TIER = [
        0 => 'update_docs',
        1 => 'provider_release_envelope_or_official_capability_entry',
        2 => 'architecture_guidance_or_eval_requirement',
        3 => 'design_candidate_or_ap_proposal',
        4 => 'research_task',
        5 => 'question',
    ];

    /** The 11 required source fields (Required Source Fields). */
    public const REQUIRED_SOURCE_FIELDS = [
        'source_id',
        'url_or_repo_path',
        'title',
        'publisher_or_owner',
        'retrieved_at',
        'tier',
        'claim_supported',
        'evidence_excerpt_or_pointer',
        'known_limits',
        'staleness_risk',
        'atlas_impact',
    ];

    /**
     * Source Scoring Formula signals. Positive signals add +1, the four penalty
     * signals subtract 1 each. The set and signs mirror the doc exactly.
     *
     * @var array<string,int>
     */
    private const SCORE_SIGNALS = [
        // positives
        'authority' => 1,
        'primary_source_proximity' => 1,
        'recency' => 1,
        'reliability_history' => 1,
        'methodological_transparency' => 1,
        'reproducibility' => 1,
        'data_or_code_presence' => 1,
        'cross_source_consistency' => 1,
        // negatives
        'conflict_of_interest' => -1,
        'missing_evidence' => -1,
        'promotional_language' => -1,
        'missing_date' => -1,
        'dead_link' => -1,
    ];

    /** The 6 ordered steps of the Recent News Rule. */
    public const RECENT_NEWS_STEPS = [
        'find_original_source',
        'find_independent_confirmation',
        'verify_date_and_timezone',
        'check_update_correction_history',
        'check_primary_documents_linked',
        'avoid_strong_conclusion_before_primary_source',
    ];

    /**
     * Outcomes a packet that fails the gate can NEVER reach (anti-hallucination
     * invariant). A failing packet is archived as a lead only.
     *
     * @var list<string>
     */
    public const FORBIDDEN_OUTCOMES_ON_FAIL = [
        'memory_truth',
        'policy',
        'routing',
        'docs_law',
        'implementation',
    ];

    /**
     * Classify a source into its trust tier.
     *
     * @param array<string,mixed> $source
     *        tier         : int|string  explicit tier 0..5 (preferred — the doc
     *                                   requires tier to be explicit)
     *        source_class : string      optional class hint when tier absent
     *
     * @return array<string,mixed>
     */
    public function classifyTier(array $source): array
    {
        $tier = $this->resolveTier($source);
        $meta = self::TIERS[$tier];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'tier' => $tier,
            'source_class' => $meta['class'],
            'allowed_use' => $meta['allowed_use'],
            // Tier 0 is the only tier that "can define current truth"; tier 5 is
            // hypothesis-only. Surface both as flags for callers.
            'can_define_truth' => $tier === self::TIER_MIN,
            'hypothesis_only' => $tier === self::TIER_MAX,
            'canonical_alone' => $tier === self::TIER_MIN,
        ];
    }

    /**
     * The single artifact a given tier is allowed to create (Promotion Rules).
     *
     * @param int|string $tier
     *
     * @return array<string,mixed>
     */
    public function promotionFor(int|string $tier): array
    {
        $t = $this->normalizeTier($tier);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'tier' => $t,
            'allowed_promotion' => self::PROMOTION_BY_TIER[$t],
            // The two weakest tiers cannot affect canon: tier 4 opens a research
            // task, tier 5 only a question.
            'can_update_canon' => $t <= 3,
        ];
    }

    /**
     * Decide whether a requested promotion target is permitted for a tier.
     * Anything stronger than the tier's allowed artifact is refused.
     *
     * @param int|string $tier
     *
     * @return array<string,mixed>
     */
    public function canPromote(int|string $tier, string $requestedTarget): array
    {
        $t = $this->normalizeTier($tier);
        $allowed = self::PROMOTION_BY_TIER[$t];
        $requested = strtolower(trim($requestedTarget));

        // Tier may always do less than (or exactly) its allowed action. We treat
        // the ladder as a strict allow-list: only the tier's own promotion target
        // and the weaker-tier targets below it are permitted.
        $permittedSet = [];
        foreach (self::PROMOTION_BY_TIER as $ladderTier => $artifact) {
            if ($ladderTier >= $t) {
                $permittedSet[] = $artifact;
            }
        }

        $allowedFlag = in_array($requested, $permittedSet, true);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'tier' => $t,
            'requested' => $requested,
            'tier_allowed_promotion' => $allowed,
            'allowed' => $allowedFlag,
            'reason' => $allowedFlag
                ? 'within_tier_authority'
                : "exceeds_tier_authority:tier_{$t}_max_is_{$allowed}",
        ];
    }

    /**
     * Compute the explicit, reproducible source score (Source Scoring Formula).
     * Positives add, the five penalties subtract. Only recognised signals count.
     *
     * @param array<string,mixed> $signals signal_name => truthy/falsey
     *
     * @return array<string,mixed>
     */
    public function scoreSource(array $signals): array
    {
        $score = 0;
        $applied = [];
        $positive = 0;
        $negative = 0;

        foreach (self::SCORE_SIGNALS as $name => $weight) {
            if (! empty($signals[$name])) {
                $score += $weight;
                $applied[$name] = $weight > 0 ? "+{$weight}" : (string) $weight;
                if ($weight > 0) {
                    $positive++;
                } else {
                    $negative++;
                }
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'source_score' => $score,
            'positive_signals' => $positive,
            'negative_signals' => $negative,
            'applied' => $applied,
            'reproducible' => true,
        ];
    }

    /**
     * Walk the Recent News Rule. A strong conclusion is only allowed when every
     * one of the 6 steps is satisfied (the rule explicitly forbids a strong
     * conclusion before the primary source is in hand).
     *
     * @param array<string,mixed> $steps step_name => truthy/falsey
     *
     * @return array<string,mixed>
     */
    public function recentNewsChecklist(array $steps): array
    {
        $passed = [];
        $missing = [];
        foreach (self::RECENT_NEWS_STEPS as $step) {
            if (! empty($steps[$step])) {
                $passed[] = $step;
            } else {
                $missing[] = $step;
            }
        }

        $strongConclusionAllowed = $missing === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'steps_total' => count(self::RECENT_NEWS_STEPS),
            'steps_passed' => count($passed),
            'missing_steps' => $missing,
            'strong_conclusion_allowed' => $strongConclusionAllowed,
            'reason' => $strongConclusionAllowed
                ? 'all_recent_news_steps_satisfied'
                : 'primary_source_or_confirmation_incomplete',
        ];
    }

    /**
     * Run the Anti-Hallucination Gate on a research packet. The packet fails on
     * ANY of the 7 documented conditions. A failing packet is reduced to a lead
     * and may never become memory truth, policy, routing, docs law or code.
     *
     * @param array<string,mixed> $packet
     *        source_url_invented : bool  a source URL is invented
     *        source_type_known   : bool  source type is known (false => fail)
     *        claim_has_source_pointer : bool  claim carries a source pointer
     *        secondary_as_primary : bool  a secondary source treated as primary
     *        freshness_matters    : bool  freshness is relevant for this claim
     *        timestamp_present    : bool  a timestamp is present
     *        source_conflict      : bool  sources conflict
     *        source_conflict_resolved : bool  the conflict was addressed
     *        llm_text_as_proof    : bool  LLM text treated as factual proof
     *
     * @return array<string,mixed>
     */
    public function evaluatePacket(array $packet): array
    {
        $failures = [];

        // 1 — a source URL is invented.
        if (! empty($packet['source_url_invented'])) {
            $failures[] = 'source_url_invented';
        }

        // 2 — source type is unknown.
        if (array_key_exists('source_type_known', $packet) && empty($packet['source_type_known'])) {
            $failures[] = 'source_type_unknown';
        }

        // 3 — claim has no source pointer.
        if (array_key_exists('claim_has_source_pointer', $packet) && empty($packet['claim_has_source_pointer'])) {
            $failures[] = 'claim_without_source_pointer';
        }

        // 4 — a secondary source is treated as primary.
        if (! empty($packet['secondary_as_primary'])) {
            $failures[] = 'secondary_treated_as_primary';
        }

        // 5 — freshness matters but timestamp is missing.
        if (! empty($packet['freshness_matters']) && empty($packet['timestamp_present'])) {
            $failures[] = 'freshness_matters_but_timestamp_missing';
        }

        // 6 — source conflict is ignored.
        if (! empty($packet['source_conflict']) && empty($packet['source_conflict_resolved'])) {
            $failures[] = 'source_conflict_ignored';
        }

        // 7 — LLM text is treated as factual proof.
        if (! empty($packet['llm_text_as_proof'])) {
            $failures[] = 'llm_text_treated_as_proof';
        }

        $passed = $failures === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'passed' => $passed,
            'failures' => $failures,
            // A failing packet is archived as a lead only — never canon/code.
            'disposition' => $passed ? 'eligible_for_promotion' : 'archived_as_lead',
            'may_become_memory_truth' => $passed,
            'may_become_policy' => $passed,
            'may_become_routing' => $passed,
            'may_become_docs_law' => $passed,
            'may_become_implementation' => $passed,
            'forbidden_outcomes_on_fail' => $passed ? [] : self::FORBIDDEN_OUTCOMES_ON_FAIL,
        ];
    }

    /**
     * Check a source for the 11 Required Source Fields. A non-empty value is
     * required for each field; missing/blank fields are reported.
     *
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    public function checkRequiredFields(array $source): array
    {
        $missing = [];
        foreach (self::REQUIRED_SOURCE_FIELDS as $field) {
            $value = $source[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'complete' => $missing === [],
            'missing_fields' => $missing,
            'required_total' => count(self::REQUIRED_SOURCE_FIELDS),
        ];
    }

    /**
     * Resolve the tier from a source array, preferring the explicit `tier`,
     * falling back to a class hint, and finally to the least-trusted tier 5
     * (the doc requires tier to be explicit, so an unknown source is hypothesis
     * only — it can never silently inherit a stronger tier).
     *
     * @param array<string,mixed> $source
     */
    private function resolveTier(array $source): int
    {
        if (array_key_exists('tier', $source) && $source['tier'] !== null && $source['tier'] !== '') {
            return $this->normalizeTier($source['tier']);
        }

        $class = $source['source_class'] ?? null;
        if (is_string($class) && trim($class) !== '') {
            $needle = strtolower(trim($class));
            foreach (self::TIERS as $tier => $meta) {
                if ($meta['class'] === $needle) {
                    return $tier;
                }
            }
        }

        // Unknown source => least trusted tier (hypothesis only).
        return self::TIER_MAX;
    }

    /**
     * Clamp any tier input into the valid 0..5 range. Out-of-range or
     * non-numeric values fall to the least-trusted tier so nothing can promote
     * itself by passing a bogus tier.
     *
     * @param int|string $tier
     */
    private function normalizeTier(int|string $tier): int
    {
        if (is_string($tier)) {
            $tier = ctype_digit(ltrim($tier, '-')) ? (int) $tier : self::TIER_MAX;
        }

        if ($tier < self::TIER_MIN || $tier > self::TIER_MAX) {
            return self::TIER_MAX;
        }

        return $tier;
    }
}
