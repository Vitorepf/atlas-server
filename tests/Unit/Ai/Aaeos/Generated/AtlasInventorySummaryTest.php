<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasInventorySummaryService;
use Tests\TestCase;

/**
 * Pins the documented Legacy Cleanup Inventory Summary rules: the seven Classes
 * + default actions, the "Decision Criteria" priority order, the "Current
 * Families" handling and the four "Risk Rules".
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
 */
class AtlasInventorySummaryTest extends TestCase
{
    private function service(): AtlasInventorySummaryService
    {
        return new AtlasInventorySummaryService();
    }

    /**
     * Decision Criteria row 1: an item listed by README / START_HERE / canonical
     * index is keep_canonical with the "keep and link from index" action.
     */
    public function test_index_listed_item_is_keep_canonical(): void
    {
        $c = $this->service()->classify(['listed_in_index' => true]);

        $this->assertSame(AtlasInventorySummaryService::CLASS_KEEP_CANONICAL, $c['class']);
        $this->assertSame('keep_and_link_from_index_or_readme', $c['default_action']);
    }

    /**
     * Decision Criteria order is load-bearing: index membership is checked BEFORE
     * the personal/research question, so an item that is both index-listed and
     * research-heavy resolves to keep_canonical, not human_vault_only. This is
     * the ordering the inventory summary documents (and is intentionally
     * distinct from the cleanup report's vault-first ordering).
     */
    public function test_decision_criteria_priority_index_beats_research(): void
    {
        $c = $this->service()->classify([
            'listed_in_index' => true,
            'is_personal_or_research' => true,
            'has_decision_without_canonical' => true,
        ]);

        $this->assertSame(AtlasInventorySummaryService::CLASS_KEEP_CANONICAL, $c['class']);
        $this->assertSame('listed_in_readme_start_here_or_canonical_index', $c['reason']);
    }

    /**
     * Classes table: a provider/agent projection is always archived_projection
     * and "never source of truth", even if it would otherwise look index-listed.
     */
    public function test_projection_is_archived_projection_never_source_of_truth(): void
    {
        $c = $this->service()->classify([
            'is_projection' => true,
            'listed_in_index' => true,
        ]);

        $this->assertSame(AtlasInventorySummaryService::CLASS_ARCHIVED_PROJECTION, $c['class']);
        $this->assertSame('preserve_as_historical_never_source_of_truth', $c['default_action']);
    }

    /**
     * Decision Criteria last row: an obsolete, unreferenced item is
     * archived_quarantine and is explicitly flagged "never immediate delete".
     */
    public function test_obsolete_unreferenced_is_quarantine_never_immediate_delete(): void
    {
        $c = $this->service()->classify(['is_obsolete_unreferenced' => true]);

        $this->assertSame(AtlasInventorySummaryService::CLASS_ARCHIVED_QUARANTINE, $c['class']);
        $this->assertTrue($c['never_immediate_delete']);
    }

    /**
     * Current Families: a deprecated KB stub is kept with redirect and never
     * expanded; an unrecognized family is reported rather than silently kept.
     */
    public function test_family_handling_known_and_unknown(): void
    {
        $service = $this->service();

        $known = $service->handleFamily('deprecated_kb_stubs');
        $this->assertTrue($known['known']);
        $this->assertSame('keep_with_redirect_do_not_expand', $known['handling']);

        $unknown = $service->handleFamily('some_made_up_family');
        $this->assertFalse($unknown['known']);
        $this->assertSame('unknown_family_requires_inventory_update', $unknown['handling']);
    }

    /**
     * Risk Rule 4: any class change must preserve traceability for future
     * audits; a class change without it is rejected, and the same change WITH
     * traceability is allowed.
     */
    public function test_risk_rule_class_change_requires_traceability(): void
    {
        $service = $this->service();

        $rejected = $service->checkRiskRules([
            'changes_class' => true,
            'preserves_traceability' => false,
        ]);
        $this->assertFalse($rejected['allowed']);
        $this->assertContains('class_change_without_traceability_for_audit', $rejected['violations']);

        $this->assertTrue($service->isProposalAllowed([
            'changes_class' => true,
            'preserves_traceability' => true,
        ]));
    }

    /**
     * Risk Rules 1-3: treating human_vault_only as low value, treating
     * archived_quarantine as delete-approved, and pasting the full source on a
     * promote_to_kb are each independent violations; a clean proposal passes.
     */
    public function test_remaining_risk_rules_and_clean_proposal(): void
    {
        $service = $this->service();

        $r = $service->checkRiskRules([
            'treats_vault_as_low_value' => true,
            'treats_quarantine_as_delete_ok' => true,
            'promote_to_kb' => true,
            'pastes_full_source' => true,
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('human_vault_only_treated_as_low_value', $r['violations']);
        $this->assertContains('archived_quarantine_treated_as_delete_approved', $r['violations']);
        $this->assertContains('promote_to_kb_pasted_full_source_instead_of_synthesizing', $r['violations']);

        // promote_to_kb that synthesizes (does not paste the full source) is fine.
        $this->assertTrue($service->isProposalAllowed([
            'promote_to_kb' => true,
            'pastes_full_source' => false,
        ]));
    }
}
