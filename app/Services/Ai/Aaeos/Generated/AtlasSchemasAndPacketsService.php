<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Research Self-Improvement Schemas And Packets decider.
 *
 * Pure, deterministic implementation of the executable packet contracts that
 * carry a research finding from raw source to (possibly) docs/code. Nothing here
 * touches the database, a provider, the shell or the filesystem: every method
 * returns a typed verdict array that a caller may act on — or, when the gate is
 * closed, must refuse to act on.
 *
 * The doc defines five typed packets (each with a frozen `schema_version`) plus
 * a closed "Fail-Closed Rules" section. The two canonical decisions are encoded
 * verbatim:
 *
 *   Fail-Closed Rules (every one is enforced, default-deny):
 *     1. Missing source judgment blocks promotion.
 *     2. Tier 4 or Tier 5 blocks memory truth and policy.
 *     3. promotion_allowed=false blocks docs law.
 *     4. implementation_allowed=false blocks code.
 *     5. review_required=true blocks auto-apply.
 *
 * Frontmatter decisions, also enforced:
 *   - "Packets are evidence carriers, not permission to mutate Atlas."
 *     -> every gate defaults closed; a packet only opens a stage when it proves
 *        the precondition, never by mere presence.
 *
 * The service NEVER promotes a packet, writes a doc, applies code or runs a
 * tool. It returns the verdict plus the closed reason list; the caller decides.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
 */
final class AtlasSchemasAndPacketsService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.schemas_and_packets.v1';

    /** Frozen schema_version for each of the five typed packets (from the doc). */
    public const SCHEMA_RESEARCH_PACKET = 'atlas.research_packet.v1';
    public const SCHEMA_SOURCE_JUDGMENT = 'atlas.source_judgment.v1';
    public const SCHEMA_CLAIM = 'atlas.research_claim.v1';
    public const SCHEMA_DOCS_PROMOTION = 'atlas.docs_promotion_packet.v1';
    public const SCHEMA_IMPLEMENTATION_PLAN = 'atlas.implementation_plan_packet.v1';
    public const SCHEMA_SELF_IMPROVEMENT = 'atlas.self_improvement_proposal.v1';

    /** Closed enum for Research Packet `recommended_action`. */
    public const RECOMMENDED_ACTIONS = [
        'promote_to_doc',
        'create_ap',
        'measure',
        'archive',
        'research_more',
    ];

    /** Closed enum for Documentation Promotion `change_type`. */
    public const DOCS_CHANGE_TYPES = ['new_doc', 'update_doc', 'ap', 'archive'];

    /** Closed enum for Self-Improvement `autonomy_level`. */
    public const AUTONOMY_LEVELS = ['read_only', 'proposal_only', 'approved_apply'];

    /** Closed enum for the `risk` field shared by plan and proposal packets. */
    public const RISK_LEVELS = ['low', 'medium', 'high'];

    /** Source-judgment tier bounds (mirrors the trust ladder: 0 best, 5 worst). */
    public const TIER_MIN = 0;
    public const TIER_MAX = 5;

    /**
     * Tiers that may NOT back memory truth or policy (Fail-Closed Rule 2).
     *
     * @var list<int>
     */
    public const TIERS_BLOCKED_FROM_TRUTH = [4, 5];

    /** Required fields per packet, keyed by schema_version. */
    private const REQUIRED_FIELDS = [
        self::SCHEMA_RESEARCH_PACKET => ['packet_id', 'objective', 'question', 'recommended_action'],
        self::SCHEMA_SOURCE_JUDGMENT => ['source_id', 'url_or_repo_path', 'title', 'tier'],
        self::SCHEMA_DOCS_PROMOTION => ['packet_id', 'research_packet_id', 'target_doc', 'change_type'],
        self::SCHEMA_IMPLEMENTATION_PLAN => ['packet_id', 'docs_promotion_packet_id', 'owner_files', 'risk'],
        self::SCHEMA_SELF_IMPROVEMENT => ['proposal_id', 'expected_gain', 'risk', 'autonomy_level'],
    ];

    /**
     * Rule 1 — "Missing source judgment blocks promotion."
     *
     * A research packet may only advance toward promotion if it carries at least
     * one source judgment (a non-empty `source_ids` list AND a matching
     * source-judgment record). With nothing to judge, promotion is fail-closed.
     *
     * @param array<string,mixed> $researchPacket
     * @param list<array<string,mixed>> $sourceJudgments
     *
     * @return array<string,mixed>
     */
    public function gatePromotionBySourceJudgment(array $researchPacket, array $sourceJudgments): array
    {
        $sourceIds = AtlasAaeosStringListNormalizer::nonBlankStringOrIntValues($researchPacket['source_ids'] ?? []);
        $haveJudgments = $this->nonEmptyJudgments($sourceJudgments);

        $hasJudgment = $sourceIds !== [] && $haveJudgments;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'rule' => 'missing_source_judgment_blocks_promotion',
            'promotion_allowed' => $hasJudgment,
            'source_id_count' => count($sourceIds),
            'source_judgment_count' => count($haveJudgments),
            'reason' => $hasJudgment
                ? 'source_judgment_present'
                : 'no_source_judgment_promotion_blocked',
        ];
    }

    /**
     * Rule 2 — "Tier 4 or Tier 5 blocks memory truth and policy."
     *
     * A source judgment at tier 4 (community) or tier 5 (rumor / LLM answer) can
     * never back Atlas memory truth or policy. Tiers 0..3 may. An unknown/invalid
     * tier clamps to the least-trusted tier 5 (default-deny — a bogus tier can
     * never buy authority).
     *
     * @param array<string,mixed> $sourceJudgment
     *
     * @return array<string,mixed>
     */
    public function gateMemoryTruthByTier(array $sourceJudgment): array
    {
        $tier = $this->normalizeTier($sourceJudgment['tier'] ?? null);
        $blocked = in_array($tier, self::TIERS_BLOCKED_FROM_TRUTH, true);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'rule' => 'tier_4_or_5_blocks_memory_truth_and_policy',
            'tier' => $tier,
            'may_back_memory_truth' => ! $blocked,
            'may_back_policy' => ! $blocked,
            'reason' => $blocked
                ? "tier_{$tier}_blocked_from_memory_truth_and_policy"
                : "tier_{$tier}_may_back_memory_truth_and_policy",
        ];
    }

    /**
     * Rule 3 — "promotion_allowed=false blocks docs law."
     *
     * A documentation promotion packet may only change docs law when its
     * `promotion_allowed` flag is explicitly true. The flag is fail-closed:
     * absent or non-true => blocked.
     *
     * @param array<string,mixed> $docsPromotionPacket
     *
     * @return array<string,mixed>
     */
    public function gateDocsLaw(array $docsPromotionPacket): array
    {
        $allowed = ($docsPromotionPacket['promotion_allowed'] ?? null) === true;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'rule' => 'promotion_allowed_false_blocks_docs_law',
            'may_change_docs_law' => $allowed,
            'reason' => $allowed
                ? 'promotion_allowed_true'
                : 'promotion_allowed_false_docs_law_blocked',
        ];
    }

    /**
     * Rule 4 — "implementation_allowed=false blocks code."
     *
     * An implementation plan packet may only touch code when its
     * `implementation_allowed` flag is explicitly true. Fail-closed.
     *
     * @param array<string,mixed> $implementationPlanPacket
     *
     * @return array<string,mixed>
     */
    public function gateCode(array $implementationPlanPacket): array
    {
        $allowed = ($implementationPlanPacket['implementation_allowed'] ?? null) === true;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'rule' => 'implementation_allowed_false_blocks_code',
            'may_change_code' => $allowed,
            'reason' => $allowed
                ? 'implementation_allowed_true'
                : 'implementation_allowed_false_code_blocked',
        ];
    }

    /**
     * Rule 5 — "review_required=true blocks auto-apply."
     *
     * A self-improvement proposal may only auto-apply when review is NOT required
     * AND the autonomy level is the strongest tier (`approved_apply`). When
     * `review_required` is absent it is treated as true (fail-closed: a proposal
     * is reviewed by default).
     *
     * @param array<string,mixed> $proposal
     *
     * @return array<string,mixed>
     */
    public function gateAutoApply(array $proposal): array
    {
        // Absent flag => review required (default-deny).
        $reviewRequired = ($proposal['review_required'] ?? true) !== false;
        $autonomy = $this->normalizeAutonomy($proposal['autonomy_level'] ?? null);
        $autonomyPermits = $autonomy === 'approved_apply';

        $autoApplyAllowed = ! $reviewRequired && $autonomyPermits;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'rule' => 'review_required_true_blocks_auto_apply',
            'review_required' => $reviewRequired,
            'autonomy_level' => $autonomy,
            'auto_apply_allowed' => $autoApplyAllowed,
            'reason' => $autoApplyAllowed
                ? 'review_cleared_and_autonomy_approved_apply'
                : ($reviewRequired
                    ? 'review_required_auto_apply_blocked'
                    : 'autonomy_below_approved_apply_auto_apply_blocked'),
        ];
    }

    /**
     * Run the full Fail-Closed pipeline end-to-end. A finding may only reach
     * `applied` when every gate in order opens; the first closed gate stops the
     * pipeline and names the blocking stage. This makes the doc's invariant
     * literal: each stage is permission-bearing, and the packet is an evidence
     * carrier, never a blanket permission to mutate Atlas.
     *
     * @param array<string,mixed> $researchPacket
     * @param list<array<string,mixed>> $sourceJudgments
     * @param array<string,mixed> $docsPromotionPacket
     * @param array<string,mixed> $implementationPlanPacket
     * @param array<string,mixed> $proposal
     *
     * @return array<string,mixed>
     */
    public function evaluatePipeline(
        array $researchPacket,
        array $sourceJudgments,
        array $docsPromotionPacket,
        array $implementationPlanPacket,
        array $proposal,
    ): array {
        $promotion = $this->gatePromotionBySourceJudgment($researchPacket, $sourceJudgments);

        // The strongest source judgment is the one that may (or may not) back
        // memory truth; we evaluate the best-tier judgment supplied.
        $bestJudgment = $this->bestJudgment($sourceJudgments);
        $truth = $this->gateMemoryTruthByTier($bestJudgment);

        $docs = $this->gateDocsLaw($docsPromotionPacket);
        $code = $this->gateCode($implementationPlanPacket);
        $apply = $this->gateAutoApply($proposal);

        // Ordered stages: promotion -> docs_law -> code -> auto_apply. The first
        // failing stage is the terminal disposition.
        $stages = [
            'promotion' => $promotion['promotion_allowed'],
            'docs_law' => $docs['may_change_docs_law'],
            'code' => $code['may_change_code'],
            'auto_apply' => $apply['auto_apply_allowed'],
        ];

        $blockedAt = null;
        foreach ($stages as $stage => $open) {
            if (! $open) {
                $blockedAt = $stage;
                break;
            }
        }

        $disposition = match ($blockedAt) {
            null => 'applied',
            'promotion' => 'blocked_no_source_judgment',
            'docs_law' => 'blocked_docs_law',
            'code' => 'blocked_code',
            'auto_apply' => 'blocked_auto_apply',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'blocked_at' => $blockedAt,
            'disposition' => $disposition,
            'applied' => $blockedAt === null,
            'gates' => [
                'promotion' => $promotion,
                'memory_truth' => $truth,
                'docs_law' => $docs,
                'code' => $code,
                'auto_apply' => $apply,
            ],
        ];
    }

    /**
     * Structural validation of any of the five typed packets: confirms the
     * `schema_version` is known, the required fields are present and non-empty,
     * and any closed enum value is in range. This is shape validation only — it
     * does NOT open any gate.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function validatePacket(array $packet): array
    {
        $schema = is_string($packet['schema_version'] ?? null) ? $packet['schema_version'] : '';
        $known = array_key_exists($schema, self::REQUIRED_FIELDS);

        $missing = [];
        if ($known) {
            foreach (self::REQUIRED_FIELDS[$schema] as $field) {
                $value = $packet[$field] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
                    $missing[] = $field;
                }
            }
        }

        $enumErrors = $this->enumErrors($schema, $packet);
        $valid = $known && $missing === [] && $enumErrors === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'schema_version' => $schema,
            'schema_known' => $known,
            'valid' => $valid,
            'missing_fields' => $missing,
            'enum_errors' => $enumErrors,
            'reason' => $valid
                ? 'packet_valid'
                : (! $known ? 'unknown_schema_version' : 'packet_invalid'),
        ];
    }

    /**
     * Validate closed-enum fields for a packet schema.
     *
     * @param array<string,mixed> $packet
     *
     * @return list<string>
     */
    private function enumErrors(string $schema, array $packet): array
    {
        $errors = [];

        if ($schema === self::SCHEMA_RESEARCH_PACKET
            && array_key_exists('recommended_action', $packet)
            && ! in_array($packet['recommended_action'], self::RECOMMENDED_ACTIONS, true)
        ) {
            $errors[] = 'recommended_action_out_of_enum';
        }

        if ($schema === self::SCHEMA_SOURCE_JUDGMENT && array_key_exists('tier', $packet)) {
            $raw = $packet['tier'];
            if (! is_int($raw) || $raw < self::TIER_MIN || $raw > self::TIER_MAX) {
                $errors[] = 'tier_out_of_range';
            }
        }

        if ($schema === self::SCHEMA_DOCS_PROMOTION
            && array_key_exists('change_type', $packet)
            && ! in_array($packet['change_type'], self::DOCS_CHANGE_TYPES, true)
        ) {
            $errors[] = 'change_type_out_of_enum';
        }

        if ($schema === self::SCHEMA_IMPLEMENTATION_PLAN
            && array_key_exists('risk', $packet)
            && ! in_array($packet['risk'], self::RISK_LEVELS, true)
        ) {
            $errors[] = 'risk_out_of_enum';
        }

        if ($schema === self::SCHEMA_SELF_IMPROVEMENT) {
            if (array_key_exists('autonomy_level', $packet)
                && ! in_array($packet['autonomy_level'], self::AUTONOMY_LEVELS, true)
            ) {
                $errors[] = 'autonomy_level_out_of_enum';
            }
            if (array_key_exists('risk', $packet)
                && ! in_array($packet['risk'], self::RISK_LEVELS, true)
            ) {
                $errors[] = 'risk_out_of_enum';
            }
        }

        return $errors;
    }

    /**
     * Keep only source-judgment records that actually carry a source_id.
     *
     * @param list<array<string,mixed>> $judgments
     *
     * @return list<array<string,mixed>>
     */
    private function nonEmptyJudgments(array $judgments): array
    {
        $out = [];
        foreach ($judgments as $j) {
            if (is_array($j) && isset($j['source_id']) && trim((string) $j['source_id']) !== '') {
                $out[] = $j;
            }
        }

        return $out;
    }

    /**
     * The judgment with the strongest (lowest-number) tier; an empty set yields a
     * synthetic worst-tier judgment so the memory-truth gate stays fail-closed.
     *
     * @param list<array<string,mixed>> $judgments
     *
     * @return array<string,mixed>
     */
    private function bestJudgment(array $judgments): array
    {
        $valid = $this->nonEmptyJudgments($judgments);
        if ($valid === []) {
            return ['tier' => self::TIER_MAX];
        }

        usort($valid, fn (array $a, array $b): int => $this->normalizeTier($a['tier'] ?? null) <=> $this->normalizeTier($b['tier'] ?? null));

        return $valid[0];
    }

    /**
     * Clamp a tier to 0..5; anything unknown / non-int / out-of-range falls to
     * the least-trusted tier 5 so a bogus tier can never gain authority.
     *
     * @param mixed $tier
     */
    private function normalizeTier(mixed $tier): int
    {
        if (is_string($tier) && ctype_digit($tier)) {
            $tier = (int) $tier;
        }

        if (! is_int($tier) || $tier < self::TIER_MIN || $tier > self::TIER_MAX) {
            return self::TIER_MAX;
        }

        return $tier;
    }

    /**
     * Normalize an autonomy level; unknown values fall to the safest level
     * `read_only` (default-deny).
     *
     * @param mixed $level
     */
    private function normalizeAutonomy(mixed $level): string
    {
        if (is_string($level) && in_array($level, self::AUTONOMY_LEVELS, true)) {
            return $level;
        }

        return 'read_only';
    }
}
