<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasObrasContractsAndInvariantsService;
use Tests\TestCase;

/**
 * Pins the documented Obras contracts, invariants, MVP acceptance, persistence
 * boundary, level-promotion rules and anti-patterns.
 *
 * @see docs/engineering-knowledge-base/obras/contracts-and-invariants.md
 */
class AtlasObrasContractsAndInvariantsTest extends TestCase
{
    private function service(): AtlasObrasContractsAndInvariantsService
    {
        return new AtlasObrasContractsAndInvariantsService();
    }

    /** @return array<string, bool> all 12 invariants asserted true */
    private function allInvariantsTrue(): array
    {
        return array_fill_keys(
            array_keys(AtlasObrasContractsAndInvariantsService::INVARIANTS),
            true,
        );
    }

    /** The doc lists exactly 12 implementation invariants that must hold from the first MVP. */
    public function test_there_are_exactly_twelve_implementation_invariants(): void
    {
        $this->assertCount(12, AtlasObrasContractsAndInvariantsService::INVARIANTS);

        $report = $this->service()->auditInvariants($this->allInvariantsTrue());
        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['violated']);
        $this->assertSame(12, $report['satisfied_count']);
    }

    /** A single violated invariant fails the audit and surfaces the documented reason. */
    public function test_missing_foundry_asset_classification_violates_invariants(): void
    {
        $obra = $this->allInvariantsTrue();
        $obra['foundry_has_asset_classification'] = false;

        $report = $this->service()->auditInvariants($obra);

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['foundry_has_asset_classification'], $report['violated']);
        $this->assertContains(
            'invariant_violated: No Foundry claim without asset classification.',
            $report['blocking_reasons'],
        );
    }

    /**
     * MVP point 7 + the "complete needs output or closure" invariant: an Obra
     * with no next step is incomplete, even if a caller claims output.
     */
    public function test_obra_without_next_step_is_incomplete(): void
    {
        $report = $this->service()->validateObraCompleteness([
            'next_step' => '   ',
            'has_output' => true,
            'explicitly_closed' => true,
        ]);

        $this->assertFalse($report['is_complete']);
        $this->assertSame('fail', $report['status']);
        $this->assertContains(
            'incomplete: an Obra without next step is incomplete.',
            $report['reasons'],
        );
    }

    /** An Obra with a next step but no output and no explicit closure is not complete. */
    public function test_obra_with_next_step_but_no_output_or_closure_is_incomplete(): void
    {
        $report = $this->service()->validateObraCompleteness([
            'next_step' => 'write chapter 2',
            'has_output' => false,
            'explicitly_closed' => false,
        ]);

        $this->assertFalse($report['is_complete']);
        $this->assertContains(
            'incomplete: no Obra may be called complete without output or explicit closure.',
            $report['reasons'],
        );

        // With next step AND explicit closure, it is complete.
        $ok = $this->service()->validateObraCompleteness([
            'next_step' => 'write chapter 2',
            'has_output' => false,
            'explicitly_closed' => true,
        ]);
        $this->assertTrue($ok['is_complete']);
        $this->assertSame('pass', $ok['status']);
    }

    /**
     * Level promotion is a single forward step gated by the documented
     * required-evidence list: skipping a level is rejected, and a missing
     * requirement blocks even a valid step.
     */
    public function test_promotion_requires_single_forward_step_and_full_evidence(): void
    {
        $svc = $this->service();

        // Skipping L0 -> L2 is an invalid transition.
        $skip = $svc->evaluatePromotion('L0', 'L2', []);
        $this->assertFalse($skip['valid_transition']);
        $this->assertFalse($skip['can_promote']);

        // Valid L0 -> L1 but missing one required item is blocked.
        $partial = $svc->evaluatePromotion('L0', 'L1', [
            'structure_nodes' => true,
            'notes_tasks_sources_attach' => true,
            'obra_summary_generated' => true,
            // ai_context_scoped_by_obra_id missing
        ]);
        $this->assertTrue($partial['valid_transition']);
        $this->assertFalse($partial['can_promote']);
        $this->assertSame(['ai_context_scoped_by_obra_id'], $partial['missing']);

        // All four documented requirements present -> promotion allowed.
        $full = $svc->evaluatePromotion('L0', 'L1', array_fill_keys(
            AtlasObrasContractsAndInvariantsService::PROMOTION_REQUIREMENTS['L0->L1'],
            true,
        ));
        $this->assertTrue($full['can_promote']);
        $this->assertSame('pass', $full['status']);
    }

    /**
     * Persistence boundary: the full minimum passes; an incomplete minimum
     * passes only via the reserved-identifier + extension-point fallback, and
     * fails otherwise.
     */
    public function test_persistence_boundary_minimum_and_reserved_fallback(): void
    {
        $svc = $this->service();

        $full = $svc->checkPersistenceBoundary(array_fill_keys(
            AtlasObrasContractsAndInvariantsService::PERSISTENCE_MINIMUM,
            true,
        ));
        $this->assertSame('pass', $full['status']);
        $this->assertSame('full', $full['mode']);

        // Below minimum but with reserved fallback present -> pass via fallback.
        $fallback = $svc->checkPersistenceBoundary([
            'obra_id' => true,
            'lifecycle_status' => true,
            'reserved_relationship_identifiers' => true,
            'relationship_extension_points' => true,
        ]);
        $this->assertSame('pass', $fallback['status']);
        $this->assertSame('reserved_fallback', $fallback['mode']);

        // Below minimum and no reserved fallback -> fail.
        $insufficient = $svc->checkPersistenceBoundary([
            'obra_id' => true,
        ]);
        $this->assertSame('fail', $insufficient['status']);
        $this->assertSame('insufficient', $insufficient['mode']);
    }

    /** Any single anti-pattern flag fails the detector with the documented reason. */
    public function test_anti_pattern_chat_only_memory_is_detected(): void
    {
        $report = $this->service()->detectAntiPatterns(['chat_only_memory' => true]);

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['chat_only_memory'], $report['detected']);
        $this->assertContains('anti_pattern_present: chat-only memory.', $report['blocking_reasons']);

        $clean = $this->service()->detectAntiPatterns([]);
        $this->assertSame('pass', $clean['status']);
    }

    /** The whole-contract audit is green only when every surface passes. */
    public function test_full_audit_is_green_for_a_conformant_bundle(): void
    {
        $bundle = [
            'invariants' => $this->allInvariantsTrue(),
            'persistence' => array_fill_keys(
                AtlasObrasContractsAndInvariantsService::PERSISTENCE_MINIMUM,
                true,
            ),
            'mvp' => array_fill_keys(
                array_keys(AtlasObrasContractsAndInvariantsService::MVP_CAPABILITIES),
                true,
            ),
            'completeness' => [
                'next_step' => 'render artifact',
                'has_output' => true,
            ],
            'promotion' => [
                'from' => 'L0',
                'to' => 'L1',
                'evidence' => array_fill_keys(
                    AtlasObrasContractsAndInvariantsService::PROMOTION_REQUIREMENTS['L0->L1'],
                    true,
                ),
            ],
            'anti_patterns' => [],
        ];

        $report = $this->service()->audit($bundle);
        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['blocking_reasons']);

        // Flip one MVP capability off -> whole audit fails.
        $bundle['mvp']['independent_from_tcc_fields'] = false;
        $failing = $this->service()->audit($bundle);
        $this->assertSame('fail', $failing['status']);
        $this->assertNotEmpty($failing['blocking_reasons']);
    }
}
