<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringDocumentationInventoryService;
use Tests\TestCase;

/**
 * Pins the documented Agentic Engineering Documentation Inventory rules: the
 * "Classes" governance verdicts, the "Familias Canonicas" routing (incl. the
 * most-specific demotion of handoff/part), the 6-step "Fluxo" gate, and the 7
 * "Regras para IA".
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
 */
class AtlasAgenticEngineeringDocumentationInventoryTest extends TestCase
{
    private function service(): AtlasAgenticEngineeringDocumentationInventoryService
    {
        return new AtlasAgenticEngineeringDocumentationInventoryService();
    }

    /**
     * Classes table: a `mother` doc governs implementation (verdict "yes"), while
     * `handoff`, `research` and `prompt` are context only (verdict "no") and never
     * govern.
     */
    public function test_class_governance_verdicts_match_the_classes_table(): void
    {
        $svc = $this->service();

        $mother = $svc->classifyClass('mother');
        $this->assertTrue($mother['can_govern_implementation']);
        $this->assertSame('yes', $mother['governance_scope']);

        foreach (['handoff', 'research', 'prompt'] as $contextOnly) {
            $row = $svc->classifyClass($contextOnly);
            $this->assertFalse(
                $row['can_govern_implementation'],
                "{$contextOnly} must not govern implementation"
            );
            $this->assertSame('no', $row['governance_scope']);
        }
    }

    /**
     * Classes table edge cases: `index` is partial, `runbook` is within-contract,
     * `surface` is ux-only — all three still count as governing — whereas
     * `visual-projection`, `part`, `comparison-battery` and `north-star` do NOT
     * govern on their own.
     */
    public function test_partial_and_scoped_classes_govern_but_standalone_context_classes_do_not(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->classifyClass('index')['can_govern_implementation']);
        $this->assertSame('partial', $svc->classifyClass('index')['governance_scope']);

        $this->assertTrue($svc->classifyClass('runbook')['can_govern_implementation']);
        $this->assertSame('within_contract', $svc->classifyClass('runbook')['governance_scope']);

        $this->assertTrue($svc->classifyClass('surface')['can_govern_implementation']);
        $this->assertSame('ux_only', $svc->classifyClass('surface')['governance_scope']);

        $this->assertFalse($svc->classifyClass('visual-projection')['can_govern_implementation']);
        $this->assertFalse($svc->classifyClass('part')['can_govern_implementation']);
        $this->assertSame('not_for_architecture', $svc->classifyClass('comparison-battery')['governance_scope']);
        $this->assertFalse($svc->classifyClass('comparison-battery')['can_govern_implementation']);
        $this->assertSame('not_as_current_runtime', $svc->classifyClass('north-star')['governance_scope']);
    }

    /**
     * Familias Canonicas: the doc's own example — a Forge continuum
     * session-handoff fragment — must resolve to the `handoff` class under the
     * Forge Continuum owner, NOT to the Forge mother family, even though the file
     * name also contains "atlas-forge-continuum-os".
     */
    public function test_session_handoff_fragment_demotes_to_handoff_under_forge_continuum_owner(): void
    {
        $result = $this->service()->classifyFile(
            'atlas-forge-continuum-os-session-handoff-2026-05-16-part-03.md'
        );

        $this->assertTrue($result['matched']);
        $this->assertSame('handoff', $result['class']);
        $this->assertSame('atlas-forge-continuum-os.md', $result['owner_doc']);
        $this->assertFalse($result['can_govern_implementation']);
    }

    /**
     * Familias Canonicas: the doc's second example — a surface-local workspace OS
     * — classifies as `surface` (ux-only) under the Atlas Code surface owner, and
     * is explicitly NOT a runtime-mother. An unrecognized file is reported as a
     * gap rather than silently trusted.
     */
    public function test_atlas_code_workspace_os_is_surface_and_unknown_file_is_a_gap(): void
    {
        $svc = $this->service();

        $code = $svc->classifyFile('atlas-code-multi-project-workspace-os.md');
        $this->assertSame('surface', $code['class']);
        $this->assertSame('ux_only', $code['governance_scope']);
        $this->assertSame('atlas-desktop-code-surface.md', $code['owner_doc']);

        // The advantage-architecture family routes to the comparison-battery
        // class: it measures/reports but must NOT govern architecture.
        $advantage = $svc->classifyFile('atlas-programming-advantage-architecture.md');
        $this->assertSame('comparison-battery', $advantage['class']);
        $this->assertSame('not_for_architecture', $advantage['governance_scope']);
        $this->assertFalse($advantage['can_govern_implementation']);

        $gap = $svc->classifyFile('atlas-some-brand-new-unfiled-doc.md');
        $this->assertFalse($gap['matched']);
        $this->assertFalse($gap['can_govern_implementation']);
        $this->assertSame('unclassified_file_register_as_gap_before_implementation', $gap['reason']);
    }

    /**
     * Fluxo step 6: a mother/contract/runbook file's reading plan reaches
     * "implement", but a handoff/surface file's plan stops at context-only — the
     * 6-step ordered flow is emitted in both cases.
     */
    public function test_reading_plan_gates_implementation_on_class(): void
    {
        $svc = $this->service();

        $mother = $svc->readingPlan('atlas-agentic-engineering-os.md');
        $this->assertCount(6, $mother['steps']);
        $this->assertTrue($mother['may_implement']);
        $this->assertSame('identify_family_by_file_name', $mother['steps'][0]);
        $this->assertSame(
            'implement_only_because_mother_contract_or_runbook_authorizes',
            $mother['steps'][5]
        );

        $handoff = $svc->readingPlan('atlas-forge-continuum-os-session-handoff-2026-05-16-part-03.md');
        $this->assertFalse($handoff['may_implement']);
        $this->assertSame(
            'do_not_implement_class_is_context_only_escalate_to_authority_map_if_needed',
            $handoff['steps'][5]
        );
    }

    /**
     * Regras para IA: each hard "never" rule is flagged, the conflict-escalation
     * rule fires only when a conflict is NOT escalated, and a clean proposal is
     * allowed. The TEOS rule fires only when status is north-star.
     */
    public function test_ai_rules_flag_every_violation_and_allow_a_clean_proposal(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->isProposalAllowed(['starts_from_part' => true]));
        $this->assertFalse($svc->isProposalAllowed(['starts_from_session_handoff' => true]));
        $this->assertFalse($svc->isProposalAllowed(['uses_comparison_battery_as_primary_architecture' => true]));
        $this->assertFalse($svc->isProposalAllowed(['uses_provider_dossier_as_default' => true]));
        $this->assertFalse($svc->isProposalAllowed(['treats_atlas_code_as_runtime_mother' => true]));

        // TEOS-as-current is only a violation when its status is north-star.
        $this->assertTrue($svc->isProposalAllowed([
            'treats_teos_as_current_runtime' => true,
            'teos_status_is_north_star' => false,
        ]));
        $this->assertFalse($svc->isProposalAllowed([
            'treats_teos_as_current_runtime' => true,
            'teos_status_is_north_star' => true,
        ]));

        // Conflict must be escalated to the Authority Map.
        $notEscalated = $svc->checkAiRules(['has_conflict' => true, 'escalates_to_authority_map' => false]);
        $this->assertFalse($notEscalated['allowed']);
        $this->assertContains('conflict_not_escalated_to_authority_map', $notEscalated['violations']);

        $escalated = $svc->checkAiRules(['has_conflict' => true, 'escalates_to_authority_map' => true]);
        $this->assertTrue($escalated['allowed']);

        // A wholly clean proposal passes with the documented reason.
        $clean = $svc->checkAiRules([]);
        $this->assertTrue($clean['allowed']);
        $this->assertSame('no_ai_rule_violated', $clean['reason']);
        $this->assertSame([], $clean['violations']);
    }
}
