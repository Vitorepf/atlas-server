<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasExecutedPromotionsService;
use Tests\TestCase;

/**
 * Pins the executed-promotions registry, the Redirect Principle and the ordered
 * Re-Promotion Rule gate from the doc. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
 */
class AtlasExecutedPromotionsTest extends TestCase
{
    private function service(): AtlasExecutedPromotionsService
    {
        return new AtlasExecutedPromotionsService;
    }

    public function test_promoted_source_resolves_to_its_documented_destination_authority(): void
    {
        $svc = $this->service();

        // From the 2026-05-05 Promotions table.
        $this->assertSame('atlas-ai-skill-system.md', $svc->destinationFor('Skill system'));
        $this->assertSame('atlas-ai-layer-0-glossary.md', $svc->destinationFor('Atlas identity, glossary and master prompt'));
        $this->assertTrue($svc->isPromoted('Mobile gateway, push and inbox'));
        // Canonicalization: whitespace/case differences still match.
        $this->assertSame('atlas-ai-runtime-packets.md', $svc->destinationFor('  RUNTIME   packets '));
    }

    public function test_redirect_principle_forbids_a_parallel_flow_from_a_promoted_source(): void
    {
        $r = $this->service()->resolveAuthority('Skill system');

        $this->assertTrue($r['promoted']);
        $this->assertSame('atlas-ai-skill-system.md', $r['authority']);
        $this->assertSame(AtlasExecutedPromotionsService::VERDICT_ALLOW, $r['verdict']);
        // "must not be used by an AI to create a parallel flow."
        $this->assertFalse($r['may_build_parallel_flow_from_source']);
        $this->assertContains('no_parallel_flow: the legacy source may inform historical nuance but must not seed a parallel flow', $r['reasons']);
    }

    public function test_unknown_source_has_no_destination_authority(): void
    {
        $svc = $this->service();

        $this->assertNull($svc->destinationFor('some legacy thing never promoted'));
        $this->assertFalse($svc->isPromoted('some legacy thing never promoted'));

        $r = $svc->resolveAuthority('some legacy thing never promoted');
        $this->assertFalse($r['promoted']);
        $this->assertNull($r['authority']);
        $this->assertSame(AtlasExecutedPromotionsService::VERDICT_BLOCK, $r['verdict']);
    }

    public function test_re_promotion_is_allowed_only_when_all_five_ordered_steps_hold(): void
    {
        $r = $this->service()->evaluateRePromotion([
            'source_theme' => 'Skill system',
            'destination_read' => true,
            'missing_decision_identified' => true,
            'owner_doc_patched' => true,
            'source_link_preserved' => true,
            'docs_health_validated' => true,
        ]);

        $this->assertTrue($r['allowed']);
        $this->assertSame(AtlasExecutedPromotionsService::VERDICT_ALLOW, $r['verdict']);
        $this->assertNull($r['blocking_step']);
        $this->assertCount(5, $r['completed_steps']);
        $this->assertSame([], $r['pending_steps']);
    }

    public function test_re_promotion_blocks_at_first_unmet_step_in_document_order(): void
    {
        // Skips reading the destination first (the frontmatter "diff against the
        // destination" decision) even though later steps are asserted done.
        $r = $this->service()->evaluateRePromotion([
            'source_theme' => 'Skill system',
            'missing_decision_identified' => true,
            'owner_doc_patched' => true,
            'source_link_preserved' => true,
            'docs_health_validated' => true,
        ]);

        $this->assertFalse($r['allowed']);
        $this->assertSame(AtlasExecutedPromotionsService::VERDICT_BLOCK, $r['verdict']);
        // The gate is ordered: the FIRST step (read destination) is the blocker,
        // and every step is pending regardless of later flags being true.
        $this->assertSame('read_destination_doc', $r['blocking_step']);
        $this->assertSame([], $r['completed_steps']);
        $this->assertCount(5, $r['pending_steps']);
    }

    public function test_re_promotion_blocks_at_the_specific_missing_later_step(): void
    {
        // First four steps done; only docs-health validation missing -> blocks there.
        $r = $this->service()->evaluateRePromotion([
            'source_theme' => 'Skill system',
            'destination_read' => true,
            'missing_decision_identified' => true,
            'owner_doc_patched' => true,
            'source_link_preserved' => true,
        ]);

        $this->assertFalse($r['allowed']);
        $this->assertSame('run_docs_health_and_architecture_validation', $r['blocking_step']);
        $this->assertCount(4, $r['completed_steps']);
        $this->assertSame(['run_docs_health_and_architecture_validation'], $r['pending_steps']);
    }

    public function test_registry_pins_nine_promotions_and_the_five_step_gate(): void
    {
        $reg = $this->service()->registry();

        $this->assertSame(9, $reg['promotion_count']);
        $this->assertCount(9, $reg['promotions']);
        $this->assertCount(4, $reg['already_canonical']);
        $this->assertSame([
            'read_destination_doc',
            'identify_missing_decision',
            'patch_owner_doc',
            'preserve_source_link',
            'run_docs_health_and_architecture_validation',
        ], $reg['re_promotion_steps']);
    }
}
