<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasResearchToDocsPromotionService;
use Tests\TestCase;

/**
 * Pins the executable contract from the doc: the Promotion Decision routing
 * table (weak/uncertain research is archived as a lead, never promoted), the
 * 8-field Required Doc Delta, the Source Trace ("at least one accepted
 * reference"), and the Promotion Gate ("if any item fails, implementation pauses
 * or becomes an isolated spike"). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md
 */
class AtlasResearchToDocsPromotionTest extends TestCase
{
    private function service(): AtlasResearchToDocsPromotionService
    {
        return new AtlasResearchToDocsPromotionService;
    }

    /** Helper: a complete, valid 8-field doc delta. */
    private function fullDelta(): array
    {
        $delta = [];
        foreach (AtlasResearchToDocsPromotionService::REQUIRED_DOC_DELTA_FIELDS as $field) {
            $delta[$field] = "value:{$field}";
        }

        return $delta;
    }

    /** Helper: a fully-passing 6-item promotion gate. */
    private function fullGate(): array
    {
        $gate = [];
        foreach (AtlasResearchToDocsPromotionService::PROMOTION_GATE_ITEMS as $item) {
            $gate[$item] = true;
        }

        return $gate;
    }

    public function test_promotion_decision_table_routes_known_results_and_archives_weak_research(): void
    {
        // Doc "Promotion Decision": 8 rows. A structural result routes to its
        // canonical destination; "uncertain or weak" is archived, NOT promoted.
        $svc = $this->service();
        $this->assertCount(8, AtlasResearchToDocsPromotionService::PROMOTION_ROUTES);

        $kernel = $svc->routePromotion('changes_kernel_behavior');
        $this->assertTrue($kernel['known']);
        $this->assertTrue($kernel['is_promotable']);
        $this->assertFalse($kernel['is_archived']);
        $this->assertSame('kernel_doc_and_ap_and_tests', $kernel['destination']);

        // Identity/thesis is the highest-authority destination (Layer -1).
        $this->assertSame('layer_minus_1_doc_and_ap', $svc->routePromotion('changes_identity_or_thesis')['destination']);

        // Weak/uncertain research is archived as a lead and is not promotable.
        $weak = $svc->routePromotion('UNCERTAIN_OR_WEAK'); // case-insensitive
        $this->assertTrue($weak['known']);
        $this->assertFalse($weak['is_promotable']);
        $this->assertTrue($weak['is_archived']);
        $this->assertSame('archive_as_lead', $weak['destination']);

        // An unknown result is not in the table and cannot be promoted.
        $unknown = $svc->routePromotion('changes_something_undocumented');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['is_promotable']);
        $this->assertNull($unknown['destination']);
    }

    public function test_required_doc_delta_demands_all_eight_fields(): void
    {
        // Doc "Required Doc Delta": source basis, decision, allowed actions,
        // forbidden actions, owner, validation, promotion gate, rollback/fail-closed.
        $svc = $this->service();
        $this->assertCount(8, AtlasResearchToDocsPromotionService::REQUIRED_DOC_DELTA_FIELDS);

        $this->assertTrue($svc->validateDocDelta($this->fullDelta())['valid']);

        // Drop forbidden_actions and blank out validation -> invalid, both listed.
        $delta = $this->fullDelta();
        unset($delta['forbidden_actions']);
        $delta['validation'] = '   '; // whitespace does not satisfy a field

        $r = $svc->validateDocDelta($delta);
        $this->assertFalse($r['valid']);
        $this->assertEqualsCanonicalizing(['forbidden_actions', 'validation'], $r['missing_fields']);
        $this->assertSame(6, $r['present_count']);
    }

    public function test_source_trace_requires_at_least_one_accepted_reference(): void
    {
        // Doc "Source Trace": docs need not paste research but MUST point to AP,
        // source packet, source URL/repo evidence, or command/test output.
        $svc = $this->service();

        // No accepted kind (empty + unrecognised) -> trace fails.
        $none = $svc->validateSourceTrace(['blog_post', '']);
        $this->assertFalse($none['has_source_trace']);
        $this->assertSame(['blog_post'], $none['unrecognized_kinds']);

        // A single accepted reference is enough; duplicates collapse.
        $one = $svc->validateSourceTrace(['ap', 'AP', 'rumor']);
        $this->assertTrue($one['has_source_trace']);
        $this->assertSame(['ap'], $one['accepted_kinds']);
        $this->assertSame(['rumor'], $one['unrecognized_kinds']);
    }

    public function test_promotion_gate_passes_only_when_all_six_items_hold(): void
    {
        // Doc "Promotion Gate": 6 items must all hold before implementation.
        $svc = $this->service();
        $this->assertCount(6, AtlasResearchToDocsPromotionService::PROMOTION_GATE_ITEMS);

        $pass = $svc->evaluatePromotionGate($this->fullGate());
        $this->assertTrue($pass['passed']);
        $this->assertSame('implementation_may_proceed', $pass['decision']);
        $this->assertSame([], $pass['unmet']);

        // A missing source tier (not the structural item) -> hard pause.
        $gate = $this->fullGate();
        $gate['source_tier_is_acceptable'] = false;
        $paused = $svc->evaluatePromotionGate($gate);
        $this->assertFalse($paused['passed']);
        $this->assertSame('implementation_paused', $paused['decision']);
        $this->assertContains('source_tier_is_acceptable', $paused['unmet']);
    }

    public function test_only_missing_structural_plan_downgrades_to_isolated_spike(): void
    {
        // Doc: "If any item fails, implementation pauses OR becomes an isolated
        // spike." The spike path is the structural AP/plan item missing alone.
        $svc = $this->service();

        $gate = $this->fullGate();
        $gate['ap_or_plan_exists_for_structural_work'] = false; // only this one fails
        $spike = $svc->evaluatePromotionGate($gate);
        $this->assertFalse($spike['passed']);
        $this->assertSame('isolated_spike', $spike['decision']);
        $this->assertSame(['ap_or_plan_exists_for_structural_work'], $spike['unmet']);

        // But if the structural item AND another item fail, it is a hard pause,
        // not a spike.
        $gate['validation_plan_exists'] = false;
        $two = $svc->evaluatePromotionGate($gate);
        $this->assertSame('implementation_paused', $two['decision']);
    }

    public function test_resolve_promotion_promotes_to_law_only_when_everything_holds(): void
    {
        // End-to-end: a promotable result + complete delta + source trace + full
        // gate -> promoted_to_law. Weakening any leg changes the resolution.
        $svc = $this->service();

        $ok = $svc->resolvePromotion(
            'changes_runtime_boundary',
            $this->fullDelta(),
            ['ap'],
            $this->fullGate(),
        );
        $this->assertTrue($ok['promoted_to_law']);
        $this->assertSame('promoted_to_law', $ok['resolution']);

        // Weak research short-circuits to archive regardless of a perfect delta/gate.
        $archived = $svc->resolvePromotion(
            'uncertain_or_weak',
            $this->fullDelta(),
            ['ap'],
            $this->fullGate(),
        );
        $this->assertFalse($archived['promoted_to_law']);
        $this->assertSame('archived_as_lead', $archived['resolution']);

        // Promotable + complete delta + traced, but the gate only lacks the
        // structural plan -> isolated spike, NOT promoted.
        $gate = $this->fullGate();
        $gate['ap_or_plan_exists_for_structural_work'] = false;
        $spike = $svc->resolvePromotion('changes_domain_behavior', $this->fullDelta(), ['source_packet'], $gate);
        $this->assertFalse($spike['promoted_to_law']);
        $this->assertSame('isolated_spike', $spike['resolution']);

        // Complete gate but an incomplete delta blocks before the gate matters.
        $delta = $this->fullDelta();
        unset($delta['owner']);
        $blocked = $svc->resolvePromotion('changes_domain_behavior', $delta, ['ap'], $this->fullGate());
        $this->assertFalse($blocked['promoted_to_law']);
        $this->assertSame('blocked_incomplete_delta', $blocked['resolution']);
    }
}
