<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiObrasOperatingSystemService;
use Tests\TestCase;

/**
 * Pins the parent-doc Obras OS contracts: the 13-state lifecycle and its 8
 * evidence-gated "important transitions", the 12 universal quality gates, the
 * non-negotiable product law and the final-criteria ladder.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
 */
class AtlasAiObrasOperatingSystemTest extends TestCase
{
    private function service(): AtlasAiObrasOperatingSystemService
    {
        return new AtlasAiObrasOperatingSystemService();
    }

    /** @return array<string, bool> all 12 quality gates answered true */
    private function allGatesAnswered(): array
    {
        return array_fill_keys(
            array_keys(AtlasAiObrasOperatingSystemService::QUALITY_GATES),
            true,
        );
    }

    /** @return array<string, bool> full product-law compliance */
    private function fullProductLaw(): array
    {
        return array_fill_keys(
            array_keys(AtlasAiObrasOperatingSystemService::PRODUCT_LAW),
            true,
        );
    }

    /** The doc lists exactly 13 lifecycle states, in the documented order. */
    public function test_lifecycle_has_thirteen_states_in_documented_order(): void
    {
        $this->assertCount(13, AtlasAiObrasOperatingSystemService::STATES);
        $this->assertSame('Idea', AtlasAiObrasOperatingSystemService::STATES[0]);
        $this->assertSame('Replaced', AtlasAiObrasOperatingSystemService::STATES[12]);
        // "Awaiting Approval" sits between In Review/Blocked and Approved.
        $this->assertContains('Awaiting Approval', AtlasAiObrasOperatingSystemService::STATES);
    }

    /**
     * The documented gated transition "In Review -> Approved requires gates":
     * blocked without the evidence, allowed with it.
     */
    public function test_in_review_to_approved_requires_gates_evidence(): void
    {
        $blocked = $this->service()->evaluateTransition('In Review', 'Approved', []);
        $this->assertSame('fail', $blocked['status']);
        $this->assertFalse($blocked['allowed']);
        $this->assertTrue($blocked['gated']);
        $this->assertSame('gates', $blocked['required_evidence']);
        $this->assertContains(
            'missing_required_evidence: In Review->Approved requires gates',
            $blocked['blocking_reasons'],
        );

        $allowed = $this->service()->evaluateTransition('In Review', 'Approved', ['gates' => true]);
        $this->assertSame('pass', $allowed['status']);
        $this->assertTrue($allowed['allowed']);
    }

    /**
     * "Approved -> Published requires output": the right evidence token is the one
     * that authorises the move; a different token does not satisfy it.
     */
    public function test_approved_to_published_requires_output_not_a_different_token(): void
    {
        $wrongToken = $this->service()->evaluateTransition('Approved', 'Published', ['gates' => true]);
        $this->assertSame('fail', $wrongToken['status']);
        $this->assertSame('output', $wrongToken['required_evidence']);

        $rightToken = $this->service()->evaluateTransition('Approved', 'Published', ['output' => true]);
        $this->assertSame('pass', $rightToken['status']);
        $this->assertTrue($rightToken['allowed']);
    }

    /** A jump that is neither a gated nor a recovery edge is rejected as undocumented. */
    public function test_undocumented_transition_is_rejected(): void
    {
        $result = $this->service()->evaluateTransition('Idea', 'Published', ['output' => true]);
        $this->assertSame('fail', $result['status']);
        $this->assertFalse($result['documented_edge']);
        $this->assertContains(
            'undocumented_transition: Idea->Published is not a lifecycle edge',
            $result['blocking_reasons'],
        );
    }

    /**
     * The 12 universal quality gates: complete only when ALL are answered; a single
     * missing gate fails and surfaces the documented question.
     */
    public function test_quality_gates_require_all_twelve_answered(): void
    {
        $this->assertCount(12, AtlasAiObrasOperatingSystemService::QUALITY_GATES);

        $complete = $this->service()->evaluateQualityGates($this->allGatesAnswered());
        $this->assertSame('pass', $complete['status']);
        $this->assertTrue($complete['complete']);
        $this->assertSame(12, $complete['answered_count']);

        $missingOne = $this->allGatesAnswered();
        $missingOne['definition_of_done_defined'] = false;
        $report = $this->service()->evaluateQualityGates($missingOne);
        $this->assertSame('fail', $report['status']);
        $this->assertSame(['definition_of_done_defined'], $report['unanswered']);
        $this->assertContains(
            'quality_gate_unanswered: Is the definition of done defined?',
            $report['blocking_reasons'],
        );
    }

    /**
     * Final-criteria ladder is monotonic: an asset cannot reach Foundry without a
     * delivery, and a delivery that is not yet an asset stops at "delivery".
     */
    public function test_final_criteria_ladder_is_monotonic(): void
    {
        $noDelivery = $this->service()->classifyFinalCriteria([
            'has_delivery' => false,
            'became_asset' => true,
            'increased_autonomy' => true,
        ]);
        $this->assertSame('incomplete', $noDelivery['stage']);
        $this->assertFalse($noDelivery['reached_foundry']);
        $this->assertFalse($noDelivery['reached_sovereign']);

        $deliveryOnly = $this->service()->classifyFinalCriteria([
            'has_delivery' => true,
            'became_asset' => false,
        ]);
        $this->assertSame('delivery', $deliveryOnly['stage']);
        $this->assertFalse($deliveryOnly['reached_foundry']);

        $foundry = $this->service()->classifyFinalCriteria([
            'has_delivery' => true,
            'became_asset' => true,
            'increased_autonomy' => false,
        ]);
        $this->assertSame('foundry', $foundry['stage']);
        $this->assertTrue($foundry['reached_foundry']);
        $this->assertFalse($foundry['reached_sovereign']);

        $sovereign = $this->service()->classifyFinalCriteria([
            'has_delivery' => true,
            'became_asset' => true,
            'increased_autonomy' => true,
        ]);
        $this->assertSame('sovereign', $sovereign['stage']);
        $this->assertTrue($sovereign['reached_sovereign']);
    }

    /**
     * Non-negotiable product law: a breach of "If it has no next step, it is an
     * idea, not an active Obra" fails the law surface with the documented label.
     */
    public function test_product_law_blocks_obra_without_next_step(): void
    {
        $claims = $this->fullProductLaw();
        $claims['has_next_step'] = false;

        $report = $this->service()->evaluateProductLaw($claims);
        $this->assertSame('fail', $report['status']);
        $this->assertFalse($report['compliant']);
        $this->assertContains('has_next_step', $report['breached']);
        $this->assertContains(
            'product_law_breached: If it has no next step, it is an idea, not an active Obra.',
            $report['blocking_reasons'],
        );
    }

    /** The aggregate audit is green for a fully conformant reference Obra. */
    public function test_audit_passes_for_a_fully_conformant_obra(): void
    {
        $result = $this->service()->audit([
            'transition' => ['from' => 'In Review', 'to' => 'Approved', 'evidence' => ['gates' => true]],
            'quality_gates' => $this->allGatesAnswered(),
            'product_law' => $this->fullProductLaw(),
            'final_criteria' => ['has_delivery' => true, 'became_asset' => true, 'increased_autonomy' => true],
            'claims_complete' => true,
        ]);

        $this->assertSame('pass', $result['status']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame('sovereign', $result['surfaces']['final_criteria']['stage']);
    }
}
