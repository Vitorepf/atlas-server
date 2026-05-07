<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Decision\DreyfusReceiptExtensionContract;
use Tests\TestCase;

class DreyfusReceiptExtensionContractTest extends TestCase
{
    public function test_contract_shapes_cognitive_decision_and_envelope_hints(): void
    {
        $contract = app(DreyfusReceiptExtensionContract::class);
        $resolution = [
            'dreyfus_stage_resolved' => 4,
            'pedagogy_mode_resolved' => 'expert',
            'knowledge_node_id' => 'node-1',
            'confidence' => 0.873,
            'source' => 'dreyfus_overlay',
            'selection_explanation' => ['confidence_band' => 'high'],
        ];

        $decision = $contract->cognitiveDecision($resolution);
        $hints = $contract->envelopeHints($resolution);

        $this->assertSame('atlas.decide.extension.dreyfus.v1', $decision['schema_version']);
        $this->assertSame(4, $decision['dreyfus_stage_resolved']);
        $this->assertSame('expert', $decision['pedagogy_mode_resolved']);
        $this->assertSame(0.87, $decision['confidence']);
        $this->assertSame('cognitive', $hints['input_kind']);
        $this->assertSame(4, $hints['dreyfus_stage_target']);
    }
}
