<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiVisionService;
use Tests\TestCase;

/**
 * Pins the canonical rules of the Atlas AI Vision founding spec.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-vision.md
 */
class AtlasAiVisionTest extends TestCase
{
    private function service(): AtlasAiVisionService
    {
        return new AtlasAiVisionService();
    }

    /**
     * Doc "Estrutura Mae" + "Pipeline": the mother structure is exactly
     * Core/Domains/Pipeline/Surfaces/Curation, and the canonical pipeline is the
     * 11-stage order in sequence (which validates clean).
     */
    public function test_vision_structure_and_canonical_pipeline_are_exact(): void
    {
        $vision = $this->service()->vision();

        $this->assertSame(
            ['core', 'domains', 'pipeline', 'surfaces', 'curation'],
            $vision['mother_structure'],
        );
        $this->assertSame(
            [
                'input', 'intent', 'domain', 'context', 'policy', 'executor',
                'gate', 'repair_escalation', 'evidence', 'learning', 'output',
            ],
            $vision['pipeline'],
        );
        $this->assertSame(11, $vision['pipeline_stage_count']);

        $valid = $this->service()->validatePipeline([
            'stages' => AtlasAiVisionService::CANONICAL_PIPELINE,
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame('pipeline_plan_valid', $valid['reason']);
    }

    /**
     * Doc "Pipeline": "nao deve pular etapas sem justificativa auditavel."
     * Dropping the evidence + gate stages with no justification is invalid; the
     * SAME drop becomes valid once both are declared as auditable justified
     * skips.
     */
    public function test_skipping_stages_requires_auditable_justification(): void
    {
        $partial = array_values(array_filter(
            AtlasAiVisionService::CANONICAL_PIPELINE,
            static fn (string $s): bool => ! in_array($s, ['gate', 'evidence'], true),
        ));

        $unjustified = $this->service()->validatePipeline(['stages' => $partial]);
        $this->assertFalse($unjustified['valid']);
        $this->assertSame('pipeline_skips_stage_without_auditable_justification', $unjustified['reason']);
        $this->assertSame(['gate', 'evidence'], $unjustified['unjustified_skips']);

        $justified = $this->service()->validatePipeline([
            'stages' => $partial,
            'justified_skips' => ['gate', 'evidence'],
        ]);
        $this->assertTrue($justified['valid']);
        $this->assertSame([], $justified['unjustified_skips']);
    }

    /**
     * Doc "Pipeline": fixed order. Reordering canon members (domain before
     * intent) is an order violation even though every stage is present.
     */
    public function test_out_of_order_pipeline_is_rejected(): void
    {
        $order = AtlasAiVisionService::CANONICAL_PIPELINE;
        // Swap intent (1) and domain (2).
        [$order[1], $order[2]] = [$order[2], $order[1]];

        $result = $this->service()->validatePipeline(['stages' => $order]);

        $this->assertFalse($result['order_valid']);
        $this->assertFalse($result['valid']);
        $this->assertSame('pipeline_order_diverges_from_canon', $result['reason']);
    }

    /**
     * Doc "Regra Final": "Nada nasce em uma surface se pode nascer no Core."
     * A capability serving two surfaces is horizontal -> required placement is
     * Core, and requesting a surface placement violates the final rule.
     */
    public function test_horizontal_capability_must_live_in_core_not_surface(): void
    {
        $result = $this->service()->classifyPlacement([
            'capability' => 'memory',
            'surfaces_served' => ['cli', 'app'],
            'requested_placement' => 'surface',
        ]);

        $this->assertTrue($result['horizontal']);
        $this->assertSame('core', $result['required_placement']);
        $this->assertFalse($result['placement_ok']);
        $this->assertSame('nothing_born_in_surface_if_it_can_be_core', $result['violated_rule']);
    }

    /**
     * Doc "Regra Final": "Nada vira domain se ainda e capability horizontal."
     * A capability serving two domains may not be promoted to a domain; and a
     * capability serving exactly one domain legitimately lives in that domain.
     */
    public function test_domain_promotion_rules(): void
    {
        $horizontal = $this->service()->classifyPlacement([
            'capability' => 'profiling',
            'domains_served' => ['programming', 'finance'],
            'requested_placement' => 'domain',
        ]);
        $this->assertTrue($horizontal['horizontal']);
        $this->assertSame('core', $horizontal['required_placement']);
        $this->assertFalse($horizontal['placement_ok']);
        $this->assertSame('nothing_becomes_domain_while_still_horizontal', $horizontal['violated_rule']);

        $singleDomain = $this->service()->classifyPlacement([
            'capability' => 'broker_adapter',
            'domains_served' => ['finance'],
            'requested_placement' => 'domain',
        ]);
        $this->assertFalse($singleDomain['horizontal']);
        $this->assertSame('domain', $singleDomain['required_placement']);
        $this->assertTrue($singleDomain['placement_ok']);
    }

    /**
     * Doc "Regra Final": "Nada declara sucesso sem evidence." + doc "Surfaces"
     * (surfaces must not own business logic) + doc "Tese Central" (direct
     * provider use breaks the single channel).
     */
    public function test_success_surface_and_channel_guards(): void
    {
        $service = $this->service();

        $this->assertFalse(
            $service->canDeclareSuccess(['claims_success' => true, 'evidence' => []])['may_declare_success'],
        );
        $this->assertTrue(
            $service->canDeclareSuccess(['claims_success' => true, 'evidence' => ['receipt-123']])['may_declare_success'],
        );

        $this->assertFalse(
            $service->auditSurface(['surface' => 'cli', 'owns_business_logic' => true])['compliant'],
        );
        $this->assertTrue(
            $service->auditSurface(['surface' => 'cli', 'owns_business_logic' => false])['compliant'],
        );

        $direct = $service->auditChannel(['provider' => 'claude', 'routed_through_atlas' => false]);
        $this->assertFalse($direct['compliant']);
        $this->assertTrue($direct['breaks_multiplier_cycle']);
    }
}
