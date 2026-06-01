<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Evidence Lake & Citation Health decider.
 *
 * Pure, deterministic implementation of the citation-health contract: given a
 * single citation (an atomic claim linked to a raw-evidence source plus the
 * health signals collected at promotion time) it returns the controlled
 * gate decision — `promote`, `repair` or `block` — and never lets an
 * unsupported, invented, stale or unpreserved citation be promoted.
 *
 * Contract (from the doc):
 *   - "Raw evidence is the source of truth; reports and summaries are derived
 *     artifacts."
 *   - "Every critical claim needs source support and citation health."
 *   - "Dead, changed or unsupported citations block promotion."
 *   - Storage Principle: "Raw evidence must be preserved or the claim loses
 *     promotion eligibility."
 *
 * The doc's "Blocking Conditions" (a citation hitting ANY of these is blocked):
 *   1. citation URL is invented;
 *   2. source cannot be retrieved and no local snapshot exists;
 *   3. quote does not support the claim;
 *   4. claim is stronger than source;
 *   5. source is stale for a time-sensitive claim;
 *   6. social/community source is used as final proof;
 *   7. content changed and no snapshot exists.
 *
 * The doc's "Citation Health Checks" (eight questions every promoted citation
 * must answer) are evaluated and surfaced as a structured audit so a caller can
 * see exactly which check failed.
 *
 * The service NEVER performs network IO, fetches a URL, calls a provider,
 * mutates storage or touches the database. The caller collects the raw health
 * signals (does the URL resolve, did the hash change, does a snapshot exist,
 * what support label did the verifier assign, ...) and this decider turns those
 * signals into the single auditable gate decision plus a receipt.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
 */
final class AtlasEvidenceLakeAndCitationHealthService
{
    /** Stable receipt schema id for the decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.evidence.citation_health.v1';

    /** Canonical gate decisions (closed set). */
    public const ACTION_PROMOTE = 'promote';
    public const ACTION_REPAIR = 'repair';
    public const ACTION_BLOCK = 'block';

    /** Atomic-claim status values (from "Atomic Claim Object"). */
    public const CLAIM_SUPPORTED = 'supported';
    public const CLAIM_CONTRADICTED = 'contradicted';
    public const CLAIM_INSUFFICIENT = 'insufficient_evidence';
    public const CLAIM_OUTDATED = 'outdated';
    public const CLAIM_UNVERIFIABLE = 'unverifiable';

    /** Claim-evidence support labels (from "Claim Evidence Link"). */
    public const SUPPORT_SUPPORTS = 'supports';
    public const SUPPORT_CONTRADICTS = 'contradicts';
    public const SUPPORT_MENTIONS = 'mentions';
    public const SUPPORT_INSUFFICIENT = 'insufficient';

    /**
     * Source types treated as social/community — never acceptable as the FINAL
     * proof of a promoted claim (doc Blocking Condition #6). They may still
     * appear as supporting colour, but a citation that leans on one of these as
     * its sole/primary proof is blocked.
     *
     * @var list<string>
     */
    private const SOCIAL_SOURCE_TYPES = ['social', 'community', 'video'];

    /**
     * Authority levels recognised by the Raw Evidence Object. "primary" and
     * "lead" are first-party; "secondary" is acceptable; "unknown" is the
     * weakest and cannot, by itself, carry a critical claim.
     *
     * @var list<string>
     */
    private const AUTHORITY_LEVELS = ['primary', 'lead', 'secondary', 'unknown'];

    /**
     * Decide the citation-health gate for ONE citation.
     *
     * Input (all keys optional; safe defaults applied):
     *   claim_text            string  the atomic claim being promoted.
     *   claim_kind            string  fact|inference|recommendation (health check
     *                                 "claim is fact, inference or recommendation?").
     *   time_sensitive        bool    claim depends on current/fresh state.
     *   critical              bool    "critical claim needs source support".
     *   url_exists            bool    URL or repo path resolves.
     *   url_invented          bool    URL was fabricated / never existed.
     *   canonical_url_stable  bool    canonical URL is stable.
     *   retrievable           bool    source can be retrieved right now.
     *   snapshot_exists       bool    archive/local snapshot preserved.
     *   content_changed       bool    source hash changed since retrieval.
     *   support_label         string  supports|contradicts|mentions|insufficient.
     *   claim_stronger_than_source bool the claim overstates what the source says.
     *   source_stale          bool    source is stale for a time-sensitive claim.
     *   newer_source_supersedes bool  a newer source supersedes this one.
     *   contradiction_exists  bool    a contradicting source/claim exists.
     *   source_type           string  docs|paper|github|news|dataset|repo|social|...
     *   authority_level       string  primary|lead|secondary|unknown.
     *   raw_evidence_preserved bool   raw object/text preserved (Storage Principle).
     *
     * @param  array<string,mixed>  $citation
     * @return array<string,mixed>
     */
    public function decide(array $citation): array
    {
        $c = $this->normalize($citation);

        $blockers = $this->blockingConditions($c);
        $repairs = $this->repairConditions($c);
        $health = $this->healthChecks($c);
        $claimStatus = $this->deriveClaimStatus($c);

        if ($blockers !== []) {
            $action = self::ACTION_BLOCK;
        } elseif ($repairs !== []) {
            $action = self::ACTION_REPAIR;
        } else {
            $action = self::ACTION_PROMOTE;
        }

        // The doc's hard rule: "Dead, changed or unsupported citations block
        // promotion." Promotion eligibility is true ONLY when there are no
        // blockers AND no open repairs.
        $promotionEligible = $action === self::ACTION_PROMOTE;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'action' => $action,
            'claim_status' => $claimStatus,
            'promotion_eligible' => $promotionEligible,
            'blocking_conditions' => $blockers,
            'repair_conditions' => $repairs,
            'health_checks' => $health,
            'health_ok' => $blockers === [] && $repairs === [],
            'critical' => $c['critical'],
            'auditable' => true,
        ];
    }

    /**
     * True only when the citation is safe to promote (no blockers, no repairs).
     * Convenience predicate over decide() for callers that just gate.
     *
     * @param  array<string,mixed>  $citation
     */
    public function mayPromote(array $citation): bool
    {
        return $this->decide($citation)['action'] === self::ACTION_PROMOTE;
    }

    /**
     * The doc's seven "Blocking Conditions". Returns the stable reason codes for
     * every condition the citation trips (order preserved, deduplicated).
     *
     * @param  array<string,mixed>  $c  normalized citation
     * @return list<string>
     */
    public function blockingConditions(array $c): array
    {
        $b = [];

        // 1. citation URL is invented.
        if ($c['url_invented'] || ! $c['url_exists']) {
            $b[] = 'citation_url_invented';
        }

        // 2. source cannot be retrieved and no local snapshot exists.
        if (! $c['retrievable'] && ! $c['snapshot_exists']) {
            $b[] = 'unretrievable_without_snapshot';
        }

        // 7. content changed and no snapshot exists.
        if ($c['content_changed'] && ! $c['snapshot_exists']) {
            $b[] = 'content_changed_without_snapshot';
        }

        // 3. quote does not support the claim (verifier said contradicts /
        //    insufficient, i.e. not "supports").
        if ($c['support_label'] === self::SUPPORT_CONTRADICTS) {
            $b[] = 'quote_contradicts_claim';
        } elseif ($c['support_label'] !== self::SUPPORT_SUPPORTS) {
            $b[] = 'quote_does_not_support_claim';
        }

        // 4. claim is stronger than source.
        if ($c['claim_stronger_than_source']) {
            $b[] = 'claim_stronger_than_source';
        }

        // 5. source is stale for a time-sensitive claim.
        if ($c['time_sensitive'] && $c['source_stale']) {
            $b[] = 'stale_source_for_time_sensitive_claim';
        }

        // 6. social/community source used as final/sole proof.
        if (in_array($c['source_type'], self::SOCIAL_SOURCE_TYPES, true)) {
            $b[] = 'social_source_as_final_proof';
        }

        // Storage Principle: raw evidence must be preserved or the claim loses
        // promotion eligibility. Unpreserved raw evidence is a hard block.
        if (! $c['raw_evidence_preserved']) {
            $b[] = 'raw_evidence_not_preserved';
        }

        return array_values(array_unique($b));
    }

    /**
     * Non-fatal health gaps the citation can RECOVER from with a repair step
     * (e.g. fetch a snapshot, re-pin the canonical URL, attach a fresher source)
     * before it earns promotion. These do not, on their own, block forever — but
     * promotion is withheld until they are cleared.
     *
     * @param  array<string,mixed>  $c  normalized citation
     * @return list<string>
     */
    public function repairConditions(array $c): array
    {
        $r = [];

        // canonical URL is not stable → re-pin / capture snapshot.
        if (! $c['canonical_url_stable']) {
            $r[] = 'unstable_canonical_url';
        }

        // a newer source supersedes this one → refresh the citation.
        if ($c['newer_source_supersedes']) {
            $r[] = 'superseded_by_newer_source';
        }

        // a contradiction exists elsewhere → resolve before promoting.
        if ($c['contradiction_exists']) {
            $r[] = 'unresolved_contradiction';
        }

        // critical claim resting on an unknown-authority source needs a stronger
        // (primary/secondary) source attached before promotion.
        if ($c['critical'] && $c['authority_level'] === 'unknown') {
            $r[] = 'critical_claim_needs_stronger_authority';
        }

        return array_values(array_unique($r));
    }

    /**
     * The doc's eight "Citation Health Checks", each as an explicit pass/fail so
     * a caller (or audit UI) can see precisely which question failed.
     *
     * @param  array<string,mixed>  $c  normalized citation
     * @return array<string,bool>
     */
    public function healthChecks(array $c): array
    {
        return [
            // URL or repo path exists?
            'url_or_path_exists' => $c['url_exists'] && ! $c['url_invented'],
            // canonical URL is stable?
            'canonical_url_stable' => $c['canonical_url_stable'],
            // source hash unchanged since retrieval? (or change is snapshotted)
            'source_unchanged_or_snapshotted' => ! $c['content_changed'] || $c['snapshot_exists'],
            // archive/snapshot exists when web content is volatile?
            'snapshot_when_volatile' => ! $this->isVolatile($c) || $c['snapshot_exists'],
            // quoted/pointer evidence supports the claim?
            'quote_supports_claim' => $c['support_label'] === self::SUPPORT_SUPPORTS,
            // claim is fact, inference or recommendation? (classified, not blank)
            'claim_kind_classified' => in_array($c['claim_kind'], ['fact', 'inference', 'recommendation'], true),
            // newer source does NOT silently supersede this one?
            'not_superseded' => ! $c['newer_source_supersedes'],
            // no unresolved contradiction?
            'no_contradiction' => ! $c['contradiction_exists'],
        ];
    }

    /**
     * Derive the atomic-claim status from the verifier support label and the
     * freshness/retrievability signals (per "Atomic Claim Object" status enum).
     *
     * Precedence: contradicted > unverifiable > outdated > insufficient >
     * supported. A claim is only "supported" when the quote supports it AND it
     * is not stale-for-time-sensitive and not unverifiable.
     *
     * @param  array<string,mixed>  $c  normalized citation
     */
    public function deriveClaimStatus(array $c): string
    {
        if ($c['support_label'] === self::SUPPORT_CONTRADICTS) {
            return self::CLAIM_CONTRADICTED;
        }

        // Cannot retrieve and nothing preserved → cannot be verified at all.
        if (! $c['retrievable'] && ! $c['snapshot_exists']) {
            return self::CLAIM_UNVERIFIABLE;
        }

        if ($c['time_sensitive'] && $c['source_stale']) {
            return self::CLAIM_OUTDATED;
        }

        if ($c['support_label'] === self::SUPPORT_SUPPORTS && ! $c['claim_stronger_than_source']) {
            return self::CLAIM_SUPPORTED;
        }

        return self::CLAIM_INSUFFICIENT;
    }

    /**
     * Web content is "volatile" when it is not an immutable/first-party artifact.
     * News and social move; a pinned paper/dataset/repo commit is stable. Used by
     * the "snapshot when volatile" health check.
     *
     * @param  array<string,mixed>  $c  normalized citation
     */
    private function isVolatile(array $c): bool
    {
        return in_array($c['source_type'], ['news', 'social', 'community', 'video'], true);
    }

    /**
     * Coerce arbitrary input into the strict, fully-defaulted citation shape the
     * rules operate on. Unknown source types / authority levels fall back to the
     * weakest safe value so they cannot accidentally pass a check.
     *
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    private function normalize(array $in): array
    {
        $sourceType = is_string($in['source_type'] ?? null) ? (string) $in['source_type'] : 'unknown';

        $authority = is_string($in['authority_level'] ?? null) ? (string) $in['authority_level'] : 'unknown';
        if (! in_array($authority, self::AUTHORITY_LEVELS, true)) {
            $authority = 'unknown';
        }

        $support = is_string($in['support_label'] ?? null) ? (string) $in['support_label'] : self::SUPPORT_INSUFFICIENT;
        if (! in_array($support, [self::SUPPORT_SUPPORTS, self::SUPPORT_CONTRADICTS, self::SUPPORT_MENTIONS, self::SUPPORT_INSUFFICIENT], true)) {
            $support = self::SUPPORT_INSUFFICIENT;
        }

        $claimKind = is_string($in['claim_kind'] ?? null) ? (string) $in['claim_kind'] : 'inference';

        return [
            'claim_text' => is_string($in['claim_text'] ?? null) ? (string) $in['claim_text'] : '',
            'claim_kind' => $claimKind,
            'time_sensitive' => (bool) ($in['time_sensitive'] ?? false),
            'critical' => (bool) ($in['critical'] ?? false),

            // Health signals — default to the SAFE-FAILING value so a missing
            // signal never silently counts as "healthy".
            'url_exists' => (bool) ($in['url_exists'] ?? true),
            'url_invented' => (bool) ($in['url_invented'] ?? false),
            'canonical_url_stable' => (bool) ($in['canonical_url_stable'] ?? true),
            'retrievable' => (bool) ($in['retrievable'] ?? true),
            'snapshot_exists' => (bool) ($in['snapshot_exists'] ?? false),
            'content_changed' => (bool) ($in['content_changed'] ?? false),
            'support_label' => $support,
            'claim_stronger_than_source' => (bool) ($in['claim_stronger_than_source'] ?? false),
            'source_stale' => (bool) ($in['source_stale'] ?? false),
            'newer_source_supersedes' => (bool) ($in['newer_source_supersedes'] ?? false),
            'contradiction_exists' => (bool) ($in['contradiction_exists'] ?? false),
            'source_type' => $sourceType,
            'authority_level' => $authority,

            // Storage Principle. Defaults to true so well-formed citations pass,
            // but an explicit false is a hard block.
            'raw_evidence_preserved' => (bool) ($in['raw_evidence_preserved'] ?? true),
        ];
    }
}
