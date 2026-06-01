<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableSkillCommandFlowService;
use Tests\TestCase;

/**
 * Pins the Impeccable Skill And Command Flow contract:
 *  - the 23 commands partition into exactly 5 families; `craft` -> Build,
 *    `audit` -> Evaluate, an unknown verb is flagged (Evidencias taxonomy);
 *  - PRODUCT.md missing blocks and forces `teach`; DESIGN.md missing only nudges
 *    `document` (Regras 1-3 / Contratos);
 *  - the register lane must be brand or product (Regra 4);
 *  - `craft` cannot skip `shape` (Regra 5) and stops at 4 gates before code when
 *    image generation exists (Regra 6);
 *  - a screenshot with no read/inspection is not evidence (Regra 7);
 *  - the Fluxo is an ordered 7-step pipeline; routing before context is loaded is
 *    a violation.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
 */
class AtlasProgrammingFrontendImpeccableSkillCommandFlowTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendImpeccableSkillCommandFlowService
    {
        return new AtlasProgrammingFrontendImpeccableSkillCommandFlowService;
    }

    public function test_commands_partition_into_the_five_documented_families(): void
    {
        $service = $this->service();
        $families = $service->families();

        // The doc's Evidencias table lists exactly five families.
        $this->assertSame(['Build', 'Evaluate', 'Refine', 'Enhance', 'Fix'], array_keys($families));

        // craft is a Build command; audit is an Evaluate command; live is a Fix command.
        $this->assertSame('Build', $service->classifyCommand('craft')['family']);
        $this->assertSame('Evaluate', $service->classifyCommand('audit')['family']);
        $this->assertSame('Fix', $service->classifyCommand('live')['family']);

        // Case-insensitive on the verb.
        $polish = $service->classifyCommand('POLISH');
        $this->assertTrue($polish['known']);
        $this->assertSame('Refine', $polish['family']);

        // An undocumented verb is flagged, never bucketed into a family.
        $unknown = $service->classifyCommand('teleport');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['family']);
        $this->assertSame('command_is_not_in_documented_taxonomy', $unknown['conclusion']);
    }

    public function test_missing_product_md_blocks_and_forces_teach(): void
    {
        $service = $this->service();

        // No PRODUCT.md (and no DESIGN.md): hard block, only legal move is teach.
        $blocked = $service->evaluateContextGate(false, false);
        $this->assertFalse($blocked['can_start']);
        $this->assertTrue($blocked['blocked']);
        $this->assertSame('product_md_missing', $blocked['blocking_reason']);
        $this->assertSame('teach', $blocked['forced_command']);

        // DESIGN.md missing must NOT surface as a nudge while still blocked on PRODUCT.md.
        $this->assertSame([], $blocked['nudges']);
    }

    public function test_missing_design_md_only_nudges_document_and_does_not_block(): void
    {
        $service = $this->service();

        // PRODUCT.md present, DESIGN.md absent: not blocked, nudge to document.
        $nudge = $service->evaluateContextGate(true, false);
        $this->assertTrue($nudge['can_start']);
        $this->assertFalse($nudge['blocked']);
        $this->assertNull($nudge['forced_command']);
        $this->assertSame(['document'], $nudge['nudges']);
        $this->assertSame('context_ready_but_design_md_recommended_nudge_document', $nudge['conclusion']);

        // Both present: fully ready, no nudges.
        $ready = $service->evaluateContextGate(true, true);
        $this->assertTrue($ready['can_start']);
        $this->assertSame([], $ready['nudges']);
    }

    public function test_register_lane_must_be_brand_or_product(): void
    {
        $service = $this->service();

        $this->assertTrue($service->selectRegister('brand')['valid']);
        $this->assertSame('product', $service->selectRegister('product')['reference_lane']);

        $invalid = $service->selectRegister('marketing');
        $this->assertFalse($invalid['valid']);
        $this->assertNull($invalid['reference_lane']);
        $this->assertSame('register_must_be_brand_or_product', $invalid['conclusion']);
    }

    public function test_craft_cannot_skip_shape_and_stops_at_four_gates_before_code(): void
    {
        $service = $this->service();

        // The four pre-code gates are exactly: questions, palette, mocks, approved_direction.
        $this->assertSame(['questions', 'palette', 'mocks', 'approved_direction'], $service->craftPreCodeGates());

        // Rule 5: craft without shape is refused regardless of gates.
        $noShape = $service->evaluateCraftGate([
            'shape_passed' => false,
            'image_generation_available' => true,
            'gate_questions' => true,
            'gate_palette' => true,
            'gate_mocks' => true,
            'gate_approved_direction' => true,
        ]);
        $this->assertTrue($noShape['refused']);
        $this->assertFalse($noShape['may_write_code']);
        $this->assertContains('craft_cannot_skip_shape', $noShape['refusal_reasons']);

        // Rule 6: shape passed, image-gen on, but mocks + approved_direction pending -> refused.
        $gatesPending = $service->evaluateCraftGate([
            'shape_passed' => true,
            'image_generation_available' => true,
            'gate_questions' => true,
            'gate_palette' => true,
        ]);
        $this->assertTrue($gatesPending['refused']);
        $this->assertContains('craft_must_clear_four_gates_before_code', $gatesPending['refusal_reasons']);
        $this->assertSame(['mocks', 'approved_direction'], $gatesPending['pending_gates']);

        // Shape passed + all four gates cleared -> may write code.
        $cleared = $service->evaluateCraftGate([
            'shape_passed' => true,
            'image_generation_available' => true,
            'gate_questions' => true,
            'gate_palette' => true,
            'gate_mocks' => true,
            'gate_approved_direction' => true,
        ]);
        $this->assertFalse($cleared['refused']);
        $this->assertTrue($cleared['may_write_code']);
        $this->assertSame([], $cleared['pending_gates']);
    }

    public function test_screenshot_without_read_or_inspection_is_not_evidence(): void
    {
        $service = $this->service();

        // Captured but never read/inspected -> not evidence (Regra 7).
        $bare = $service->evaluateScreenshotEvidence(true, false, false);
        $this->assertFalse($bare['counts_as_evidence']);
        $this->assertSame('screenshot_without_read_or_inspection_is_not_evidence', $bare['reason']);

        // Read but not inspected -> still not evidence.
        $readOnly = $service->evaluateScreenshotEvidence(true, true, false);
        $this->assertFalse($readOnly['counts_as_evidence']);

        // Captured + read + inspected -> evidence.
        $full = $service->evaluateScreenshotEvidence(true, true, true);
        $this->assertTrue($full['counts_as_evidence']);
        $this->assertSame('screenshot_read_and_inspected_counts_as_evidence', $full['reason']);
    }

    public function test_routing_a_command_before_context_is_loaded_is_a_flow_violation(): void
    {
        $service = $this->service();
        $flow = $service->flow();

        // The doc's Fluxo lists exactly 7 ordered steps.
        $this->assertCount(7, $flow);
        $this->assertSame('load_product_design', $flow[0]);
        $this->assertSame('verify_visually_technically', $flow[6]);

        // Full canonical flow in order is contract-clean.
        $clean = $service->evaluateFlowOrder($flow);
        $this->assertTrue($clean['ordered']);
        $this->assertSame('skill_flow_respects_documented_order', $clean['conclusion']);

        // Routing a command before context load / register inference -> violation.
        $outOfOrder = $service->evaluateFlowOrder([
            AtlasProgrammingFrontendImpeccableSkillCommandFlowService::STEP_ROUTE_COMMAND,
        ]);
        $this->assertFalse($outOfOrder['ordered']);
        $missing = $outOfOrder['order_violations'][0]['missing_prerequisites'];
        $this->assertContains(AtlasProgrammingFrontendImpeccableSkillCommandFlowService::STEP_LOAD_CONTEXT, $missing);
        $this->assertContains(AtlasProgrammingFrontendImpeccableSkillCommandFlowService::STEP_INFER_REGISTER, $missing);
    }
}
