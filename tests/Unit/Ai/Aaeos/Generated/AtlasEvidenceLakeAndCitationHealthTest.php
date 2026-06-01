<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLakeAndCitationHealthService;
use Tests\TestCase;

/**
 * Pins the documented Evidence Lake & Citation Health rules.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
 */
class AtlasEvidenceLakeAndCitationHealthTest extends TestCase
{
    private function service(): AtlasEvidenceLakeAndCitationHealthService
    {
        return new AtlasEvidenceLakeAndCitationHealthService();
    }

    /**
     * A clean, primary, supporting, preserved citation is promoted and the
     * claim resolves to "supported" with all eight health checks green.
     */
    public function test_clean_supporting_citation_is_promoted(): void
    {
        $d = $this->service()->decide([
            'claim_kind' => 'fact',
            'critical' => true,
            'url_exists' => true,
            'canonical_url_stable' => true,
            'retrievable' => true,
            'snapshot_exists' => true,
            'content_changed' => false,
            'support_label' => 'supports',
            'source_type' => 'paper',
            'authority_level' => 'primary',
            'raw_evidence_preserved' => true,
        ]);

        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_PROMOTE, $d['action']);
        $this->assertTrue($d['promotion_eligible']);
        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::CLAIM_SUPPORTED, $d['claim_status']);
        $this->assertSame([], $d['blocking_conditions']);
        $this->assertTrue($d['health_ok']);
        $this->assertNotContains(false, array_values($d['health_checks']), 'every health check must pass');
    }

    /**
     * Blocking Condition #1: an invented citation URL blocks promotion even when
     * everything else looks fine.
     */
    public function test_invented_url_blocks_promotion(): void
    {
        $d = $this->service()->decide([
            'url_invented' => true,
            'support_label' => 'supports',
            'source_type' => 'docs',
            'authority_level' => 'primary',
            'raw_evidence_preserved' => true,
        ]);

        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_BLOCK, $d['action']);
        $this->assertFalse($d['promotion_eligible']);
        $this->assertContains('citation_url_invented', $d['blocking_conditions']);
    }

    /**
     * Blocking Condition #2 + #7: a source that cannot be retrieved with no
     * snapshot is blocked AND its claim is unverifiable; a content change with
     * no snapshot is likewise blocked.
     */
    public function test_unretrievable_or_changed_without_snapshot_blocks(): void
    {
        $unretrievable = $this->service()->decide([
            'retrievable' => false,
            'snapshot_exists' => false,
            'support_label' => 'supports',
            'source_type' => 'docs',
        ]);
        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_BLOCK, $unretrievable['action']);
        $this->assertContains('unretrievable_without_snapshot', $unretrievable['blocking_conditions']);
        $this->assertSame(
            AtlasEvidenceLakeAndCitationHealthService::CLAIM_UNVERIFIABLE,
            $unretrievable['claim_status'],
        );

        $changed = $this->service()->decide([
            'content_changed' => true,
            'snapshot_exists' => false,
            'support_label' => 'supports',
            'source_type' => 'docs',
        ]);
        $this->assertContains('content_changed_without_snapshot', $changed['blocking_conditions']);
    }

    /**
     * Blocking Condition #3 + #4: a quote that does not support the claim, and a
     * claim stronger than its source, both block promotion. Contradiction also
     * flips the claim status to "contradicted".
     */
    public function test_unsupported_or_overstated_claim_blocks(): void
    {
        $contradicted = $this->service()->decide([
            'support_label' => 'contradicts',
            'source_type' => 'paper',
            'authority_level' => 'primary',
        ]);
        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_BLOCK, $contradicted['action']);
        $this->assertContains('quote_contradicts_claim', $contradicted['blocking_conditions']);
        $this->assertSame(
            AtlasEvidenceLakeAndCitationHealthService::CLAIM_CONTRADICTED,
            $contradicted['claim_status'],
        );

        $overstated = $this->service()->decide([
            'support_label' => 'supports',
            'claim_stronger_than_source' => true,
            'source_type' => 'paper',
        ]);
        $this->assertContains('claim_stronger_than_source', $overstated['blocking_conditions']);
        $this->assertFalse($overstated['promotion_eligible']);
    }

    /**
     * Blocking Condition #5 + #6: a stale source for a time-sensitive claim is
     * blocked and marked outdated; a social/community source used as final proof
     * is blocked regardless of an otherwise-supporting label.
     */
    public function test_stale_time_sensitive_and_social_final_proof_block(): void
    {
        $stale = $this->service()->decide([
            'time_sensitive' => true,
            'source_stale' => true,
            'support_label' => 'supports',
            'source_type' => 'news',
        ]);
        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_BLOCK, $stale['action']);
        $this->assertContains('stale_source_for_time_sensitive_claim', $stale['blocking_conditions']);
        $this->assertSame(
            AtlasEvidenceLakeAndCitationHealthService::CLAIM_OUTDATED,
            $stale['claim_status'],
        );

        $social = $this->service()->decide([
            'support_label' => 'supports',
            'source_type' => 'social',
            'authority_level' => 'unknown',
        ]);
        $this->assertContains('social_source_as_final_proof', $social['blocking_conditions']);
        $this->assertFalse($social['promotion_eligible']);
    }

    /**
     * Storage Principle: raw evidence must be preserved or the claim loses
     * promotion eligibility — even a perfectly supporting citation is blocked
     * when the raw object was not kept.
     */
    public function test_unpreserved_raw_evidence_loses_promotion_eligibility(): void
    {
        $d = $this->service()->decide([
            'support_label' => 'supports',
            'source_type' => 'paper',
            'authority_level' => 'primary',
            'raw_evidence_preserved' => false,
        ]);

        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_BLOCK, $d['action']);
        $this->assertFalse($d['promotion_eligible']);
        $this->assertContains('raw_evidence_not_preserved', $d['blocking_conditions']);
        $this->assertFalse($this->service()->mayPromote($d === [] ? [] : [
            'support_label' => 'supports',
            'source_type' => 'paper',
            'authority_level' => 'primary',
            'raw_evidence_preserved' => false,
        ]));
    }

    /**
     * Recoverable gaps (unstable canonical URL, superseded source, unresolved
     * contradiction, critical-claim-on-unknown-authority) yield a REPAIR
     * decision — not a hard block, not a promote — so the citation can be fixed.
     */
    public function test_recoverable_gaps_yield_repair_not_block(): void
    {
        $d = $this->service()->decide([
            'support_label' => 'supports',
            'source_type' => 'docs',
            'authority_level' => 'unknown',
            'critical' => true,
            'canonical_url_stable' => false,
            'newer_source_supersedes' => true,
            'contradiction_exists' => true,
            'raw_evidence_preserved' => true,
        ]);

        $this->assertSame(AtlasEvidenceLakeAndCitationHealthService::ACTION_REPAIR, $d['action']);
        $this->assertFalse($d['promotion_eligible']);
        $this->assertContains('unstable_canonical_url', $d['repair_conditions']);
        $this->assertContains('superseded_by_newer_source', $d['repair_conditions']);
        $this->assertContains('unresolved_contradiction', $d['repair_conditions']);
        $this->assertContains('critical_claim_needs_stronger_authority', $d['repair_conditions']);
    }

    /** Every decision is auditable and carries the stable receipt schema. */
    public function test_decision_is_auditable_with_stable_schema(): void
    {
        $d = $this->service()->decide([]);

        $this->assertSame('atlas.evidence.citation_health.v1', $d['schema']);
        $this->assertTrue($d['auditable']);
    }
}
