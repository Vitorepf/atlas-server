<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSchemasAndPacketsService;
use Tests\TestCase;

/**
 * Pins the documented Schemas And Packets Fail-Closed rules.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
 */
class AtlasSchemasAndPacketsTest extends TestCase
{
    private function service(): AtlasSchemasAndPacketsService
    {
        return new AtlasSchemasAndPacketsService();
    }

    /**
     * Fail-Closed Rule 1 — "Missing source judgment blocks promotion."
     * A packet with no source_ids / no judgment is promotion-blocked; supplying
     * a matching judgment opens it.
     */
    public function test_missing_source_judgment_blocks_promotion(): void
    {
        $svc = $this->service();

        $blocked = $svc->gatePromotionBySourceJudgment(['source_ids' => []], []);
        $this->assertFalse($blocked['promotion_allowed']);
        $this->assertSame('no_source_judgment_promotion_blocked', $blocked['reason']);

        // source_ids present but NO judgment record => still blocked.
        $halfBlocked = $svc->gatePromotionBySourceJudgment(['source_ids' => ['s1']], []);
        $this->assertFalse($halfBlocked['promotion_allowed']);

        $allowed = $svc->gatePromotionBySourceJudgment(
            ['source_ids' => ['s1']],
            [['source_id' => 's1', 'tier' => 1]],
        );
        $this->assertTrue($allowed['promotion_allowed']);
        $this->assertSame('source_judgment_present', $allowed['reason']);
    }

    /**
     * Fail-Closed Rule 2 — "Tier 4 or Tier 5 blocks memory truth and policy."
     * Tiers 0..3 may back truth/policy; tiers 4 and 5 may not; an unknown tier
     * clamps to the least-trusted tier 5 (default-deny).
     */
    public function test_tier_four_and_five_block_memory_truth_and_policy(): void
    {
        $svc = $this->service();

        $t0 = $svc->gateMemoryTruthByTier(['tier' => 0]);
        $this->assertTrue($t0['may_back_memory_truth']);
        $this->assertTrue($t0['may_back_policy']);

        $t3 = $svc->gateMemoryTruthByTier(['tier' => 3]);
        $this->assertTrue($t3['may_back_memory_truth']);

        $t4 = $svc->gateMemoryTruthByTier(['tier' => 4]);
        $this->assertFalse($t4['may_back_memory_truth']);
        $this->assertFalse($t4['may_back_policy']);
        $this->assertSame('tier_4_blocked_from_memory_truth_and_policy', $t4['reason']);

        $t5 = $svc->gateMemoryTruthByTier(['tier' => 5]);
        $this->assertFalse($t5['may_back_memory_truth']);

        // Missing / bogus tier => clamps to 5 and is blocked.
        $unknown = $svc->gateMemoryTruthByTier([]);
        $this->assertSame(5, $unknown['tier']);
        $this->assertFalse($unknown['may_back_memory_truth']);
    }

    /**
     * Fail-Closed Rules 3 & 4 — "promotion_allowed=false blocks docs law" and
     * "implementation_allowed=false blocks code." Both flags are fail-closed:
     * only an explicit true opens the stage.
     */
    public function test_promotion_and_implementation_flags_are_fail_closed(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->gateDocsLaw([])['may_change_docs_law']);
        $this->assertFalse($svc->gateDocsLaw(['promotion_allowed' => false])['may_change_docs_law']);
        // A truthy non-true value must NOT open the gate.
        $this->assertFalse($svc->gateDocsLaw(['promotion_allowed' => 1])['may_change_docs_law']);
        $this->assertTrue($svc->gateDocsLaw(['promotion_allowed' => true])['may_change_docs_law']);

        $this->assertFalse($svc->gateCode([])['may_change_code']);
        $this->assertFalse($svc->gateCode(['implementation_allowed' => false])['may_change_code']);
        $this->assertTrue($svc->gateCode(['implementation_allowed' => true])['may_change_code']);
    }

    /**
     * Fail-Closed Rule 5 — "review_required=true blocks auto-apply." Auto-apply
     * needs review cleared AND autonomy_level=approved_apply. Absent review flag
     * defaults to required (default-deny).
     */
    public function test_review_required_blocks_auto_apply(): void
    {
        $svc = $this->service();

        // Review required => blocked even with the strongest autonomy.
        $reviewed = $svc->gateAutoApply([
            'review_required' => true,
            'autonomy_level' => 'approved_apply',
        ]);
        $this->assertFalse($reviewed['auto_apply_allowed']);
        $this->assertSame('review_required_auto_apply_blocked', $reviewed['reason']);

        // Review cleared but autonomy too weak => still blocked.
        $weak = $svc->gateAutoApply([
            'review_required' => false,
            'autonomy_level' => 'proposal_only',
        ]);
        $this->assertFalse($weak['auto_apply_allowed']);

        // Review cleared + approved_apply => allowed.
        $allowed = $svc->gateAutoApply([
            'review_required' => false,
            'autonomy_level' => 'approved_apply',
        ]);
        $this->assertTrue($allowed['auto_apply_allowed']);

        // Absent review flag => treated as required (default-deny).
        $defaulted = $svc->gateAutoApply(['autonomy_level' => 'approved_apply']);
        $this->assertTrue($defaulted['review_required']);
        $this->assertFalse($defaulted['auto_apply_allowed']);
    }

    /**
     * Full pipeline: a finding with a judged source, allowed docs+code, but a
     * proposal that still requires review must terminate as blocked_auto_apply
     * (not applied) — proving the first closed gate stops the chain.
     */
    public function test_pipeline_stops_at_first_closed_gate(): void
    {
        $svc = $this->service();

        $blockedAtApply = $svc->evaluatePipeline(
            ['source_ids' => ['s1']],
            [['source_id' => 's1', 'tier' => 1]],
            ['promotion_allowed' => true],
            ['implementation_allowed' => true],
            ['review_required' => true, 'autonomy_level' => 'approved_apply'],
        );
        $this->assertFalse($blockedAtApply['applied']);
        $this->assertSame('auto_apply', $blockedAtApply['blocked_at']);
        $this->assertSame('blocked_auto_apply', $blockedAtApply['disposition']);

        // No source judgment => the very first gate stops it.
        $blockedAtStart = $svc->evaluatePipeline(
            ['source_ids' => []],
            [],
            ['promotion_allowed' => true],
            ['implementation_allowed' => true],
            ['review_required' => false, 'autonomy_level' => 'approved_apply'],
        );
        $this->assertSame('promotion', $blockedAtStart['blocked_at']);
        $this->assertSame('blocked_no_source_judgment', $blockedAtStart['disposition']);

        // Everything open => applied.
        $applied = $svc->evaluatePipeline(
            ['source_ids' => ['s1']],
            [['source_id' => 's1', 'tier' => 0]],
            ['promotion_allowed' => true],
            ['implementation_allowed' => true],
            ['review_required' => false, 'autonomy_level' => 'approved_apply'],
        );
        $this->assertTrue($applied['applied']);
        $this->assertNull($applied['blocked_at']);
        $this->assertSame('applied', $applied['disposition']);
    }

    /**
     * Packet structural validation: known schema_version + required non-empty
     * fields + in-range enums pass; an out-of-enum recommended_action and an
     * unknown schema fail with named errors.
     */
    public function test_packet_validation_enforces_schema_fields_and_enums(): void
    {
        $svc = $this->service();

        $valid = $svc->validatePacket([
            'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_RESEARCH_PACKET,
            'packet_id' => 'rp1',
            'objective' => 'o',
            'question' => 'q',
            'recommended_action' => 'promote_to_doc',
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['missing_fields']);

        // Out-of-enum recommended_action.
        $badEnum = $svc->validatePacket([
            'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_RESEARCH_PACKET,
            'packet_id' => 'rp1',
            'objective' => 'o',
            'question' => 'q',
            'recommended_action' => 'delete_everything',
        ]);
        $this->assertFalse($badEnum['valid']);
        $this->assertContains('recommended_action_out_of_enum', $badEnum['enum_errors']);

        // Missing required field (proposal without autonomy_level).
        $missing = $svc->validatePacket([
            'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_SELF_IMPROVEMENT,
            'proposal_id' => 'p1',
            'expected_gain' => 'x',
            'risk' => 'low',
        ]);
        $this->assertFalse($missing['valid']);
        $this->assertContains('autonomy_level', $missing['missing_fields']);

        // Unknown schema_version.
        $unknown = $svc->validatePacket(['schema_version' => 'atlas.not_a_packet.v9']);
        $this->assertFalse($unknown['valid']);
        $this->assertFalse($unknown['schema_known']);
        $this->assertSame('unknown_schema_version', $unknown['reason']);
    }
}
