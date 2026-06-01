<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSourceQualityAndTrustLadderService;
use Tests\TestCase;

/**
 * Pins the documented Source Quality And Trust Ladder rules.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
 */
class AtlasSourceQualityAndTrustLadderTest extends TestCase
{
    private function service(): AtlasSourceQualityAndTrustLadderService
    {
        return new AtlasSourceQualityAndTrustLadderService();
    }

    /**
     * Trust Tiers table: tier 0 can define current truth and is canonical
     * alone; tier 5 is hypothesis only and never canonical alone.
     */
    public function test_tier_zero_defines_truth_tier_five_is_hypothesis_only(): void
    {
        $svc = $this->service();

        $t0 = $svc->classifyTier(['tier' => 0]);
        $this->assertSame(0, $t0['tier']);
        $this->assertTrue($t0['can_define_truth']);
        $this->assertTrue($t0['canonical_alone']);
        $this->assertFalse($t0['hypothesis_only']);

        $t5 = $svc->classifyTier(['tier' => 5]);
        $this->assertSame(5, $t5['tier']);
        $this->assertFalse($t5['can_define_truth']);
        $this->assertTrue($t5['hypothesis_only']);
        $this->assertFalse($t5['canonical_alone']);
    }

    /**
     * Unknown / unspecified source must NOT silently inherit a strong tier:
     * the doc requires tier to be explicit, so it falls to the least-trusted
     * tier 5 (hypothesis only).
     */
    public function test_unknown_source_falls_to_least_trusted_tier(): void
    {
        $svc = $this->service();

        $unknown = $svc->classifyTier(['source_id' => 'x']);
        $this->assertSame(5, $unknown['tier']);
        $this->assertTrue($unknown['hypothesis_only']);

        // A bogus out-of-range tier also clamps down, never up.
        $bogus = $svc->classifyTier(['tier' => 99]);
        $this->assertSame(5, $bogus['tier']);
    }

    /**
     * Promotion Rules table: each tier maps to exactly its documented artifact,
     * and only tiers 0..3 may touch canon (tier 4 => research task, tier 5 =>
     * question).
     */
    public function test_promotion_rules_match_tier_authority(): void
    {
        $svc = $this->service();

        $this->assertSame('update_docs', $svc->promotionFor(0)['allowed_promotion']);
        $this->assertSame(
            'provider_release_envelope_or_official_capability_entry',
            $svc->promotionFor(1)['allowed_promotion'],
        );
        $this->assertSame(
            'architecture_guidance_or_eval_requirement',
            $svc->promotionFor(2)['allowed_promotion'],
        );
        $this->assertSame('design_candidate_or_ap_proposal', $svc->promotionFor(3)['allowed_promotion']);
        $this->assertSame('research_task', $svc->promotionFor(4)['allowed_promotion']);
        $this->assertSame('question', $svc->promotionFor(5)['allowed_promotion']);

        $this->assertTrue($svc->promotionFor(3)['can_update_canon']);
        $this->assertFalse($svc->promotionFor(4)['can_update_canon']);
        $this->assertFalse($svc->promotionFor(5)['can_update_canon']);
    }

    /**
     * A tier may not promote above its authority: tier 5 (rumor / LLM answer)
     * asking to "update_docs" is refused, while opening a "question" is allowed.
     */
    public function test_tier_cannot_exceed_its_promotion_authority(): void
    {
        $svc = $this->service();

        $overreach = $svc->canPromote(5, 'update_docs');
        $this->assertFalse($overreach['allowed']);
        $this->assertStringContainsString('exceeds_tier_authority', $overreach['reason']);

        $within = $svc->canPromote(5, 'question');
        $this->assertTrue($within['allowed']);

        // A community lead (tier 4) may open a research task but not an AP proposal.
        $this->assertTrue($svc->canPromote(4, 'research_task')['allowed']);
        $this->assertFalse($svc->canPromote(4, 'design_candidate_or_ap_proposal')['allowed']);
    }

    /**
     * Source Scoring Formula: positives add, the named penalties subtract, and
     * the score is reproducible. A paper-with-code minus a conflict of interest
     * lands on the exact expected integer.
     */
    public function test_source_score_sums_signed_signals(): void
    {
        $svc = $this->service();

        $result = $svc->scoreSource([
            'authority' => true,
            'primary_source_proximity' => true,
            'recency' => true,
            'reproducibility' => true,
            'data_or_code_presence' => true,
            'conflict_of_interest' => true,   // -1
            'unknown_signal' => true,         // ignored
        ]);

        // 5 positives (+5) minus 1 penalty (-1) = 4.
        $this->assertSame(4, $result['source_score']);
        $this->assertSame(5, $result['positive_signals']);
        $this->assertSame(1, $result['negative_signals']);
        $this->assertArrayNotHasKey('unknown_signal', $result['applied']);

        // Penalty-heavy item goes negative: dead link + missing date + promo.
        $weak = $svc->scoreSource([
            'dead_link' => true,
            'missing_date' => true,
            'promotional_language' => true,
        ]);
        $this->assertSame(-3, $weak['source_score']);
    }

    /**
     * Anti-Hallucination Gate: ANY of the 7 conditions fails the packet, and a
     * failing packet is archived as a lead only — it can NEVER become memory
     * truth, policy, routing, docs law or implementation.
     */
    public function test_anti_hallucination_gate_blocks_failing_packets_to_lead_only(): void
    {
        $svc = $this->service();

        // Invented URL alone fails the gate.
        $invented = $svc->evaluatePacket(['source_url_invented' => true]);
        $this->assertFalse($invented['passed']);
        $this->assertContains('source_url_invented', $invented['failures']);
        $this->assertSame('archived_as_lead', $invented['disposition']);
        $this->assertFalse($invented['may_become_memory_truth']);
        $this->assertFalse($invented['may_become_docs_law']);
        $this->assertFalse($invented['may_become_implementation']);
        $this->assertSame(
            AtlasSourceQualityAndTrustLadderService::FORBIDDEN_OUTCOMES_ON_FAIL,
            $invented['forbidden_outcomes_on_fail'],
        );

        // Freshness matters but timestamp missing => fail.
        $stale = $svc->evaluatePacket([
            'source_type_known' => true,
            'claim_has_source_pointer' => true,
            'freshness_matters' => true,
            'timestamp_present' => false,
        ]);
        $this->assertFalse($stale['passed']);
        $this->assertContains('freshness_matters_but_timestamp_missing', $stale['failures']);

        // Source conflict ignored => fail.
        $conflict = $svc->evaluatePacket([
            'source_type_known' => true,
            'claim_has_source_pointer' => true,
            'source_conflict' => true,
            'source_conflict_resolved' => false,
        ]);
        $this->assertFalse($conflict['passed']);
        $this->assertContains('source_conflict_ignored', $conflict['failures']);

        // A clean packet passes and becomes eligible for promotion.
        $clean = $svc->evaluatePacket([
            'source_type_known' => true,
            'claim_has_source_pointer' => true,
            'secondary_as_primary' => false,
            'freshness_matters' => true,
            'timestamp_present' => true,
            'source_conflict' => false,
            'llm_text_as_proof' => false,
        ]);
        $this->assertTrue($clean['passed']);
        $this->assertSame('eligible_for_promotion', $clean['disposition']);
        $this->assertTrue($clean['may_become_implementation']);
    }

    /**
     * Recent News Rule: a strong conclusion is allowed only when all 6 steps
     * pass; missing the primary source blocks the conclusion.
     */
    public function test_recent_news_rule_requires_all_six_steps(): void
    {
        $svc = $this->service();

        $partial = $svc->recentNewsChecklist([
            'find_original_source' => true,
            'find_independent_confirmation' => true,
            // remaining 4 steps absent
        ]);
        $this->assertFalse($partial['strong_conclusion_allowed']);
        $this->assertSame(2, $partial['steps_passed']);
        $this->assertContains('verify_date_and_timezone', $partial['missing_steps']);

        $full = $svc->recentNewsChecklist([
            'find_original_source' => true,
            'find_independent_confirmation' => true,
            'verify_date_and_timezone' => true,
            'check_update_correction_history' => true,
            'check_primary_documents_linked' => true,
            'avoid_strong_conclusion_before_primary_source' => true,
        ]);
        $this->assertTrue($full['strong_conclusion_allowed']);
        $this->assertSame([], $full['missing_steps']);
    }

    /**
     * Required Source Fields: all 11 must be present; a missing field is
     * reported and the source is marked incomplete.
     */
    public function test_required_fields_are_enforced(): void
    {
        $svc = $this->service();

        $incomplete = $svc->checkRequiredFields([
            'source_id' => 's1',
            'url_or_repo_path' => 'docs/x.md',
            'title' => 'X',
            // missing the rest
        ]);
        $this->assertFalse($incomplete['complete']);
        $this->assertSame(11, $incomplete['required_total']);
        $this->assertContains('tier', $incomplete['missing_fields']);
        $this->assertContains('claim_supported', $incomplete['missing_fields']);

        $complete = $svc->checkRequiredFields([
            'source_id' => 's1',
            'url_or_repo_path' => 'docs/x.md',
            'title' => 'X',
            'publisher_or_owner' => 'Atlas',
            'retrieved_at' => '2026-06-01',
            'tier' => 0,
            'claim_supported' => 'c',
            'evidence_excerpt_or_pointer' => 'ledger:1',
            'known_limits' => 'none',
            'staleness_risk' => 'low',
            'atlas_impact' => 'doc',
        ]);
        $this->assertTrue($complete['complete']);
        $this->assertSame([], $complete['missing_fields']);
    }
}
