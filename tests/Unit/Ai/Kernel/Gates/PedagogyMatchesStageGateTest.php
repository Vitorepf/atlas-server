<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\PedagogyMatchesStageGate;
use Tests\TestCase;

class PedagogyMatchesStageGateTest extends TestCase
{
    public function test_gate_passes_when_stage_and_mode_are_resolved(): void
    {
        $result = app(PedagogyMatchesStageGate::class)->evaluate([
            'cognitive_decision' => [
                'dreyfus_stage_resolved' => 4,
                'pedagogy_mode_resolved' => 'expert',
            ],
        ]);

        $this->assertSame('atlas.gate.pedagogy_matches_stage.v1', $result['schema_version']);
        $this->assertSame('passed', $result['status']);
        $this->assertSame('pedagogy_matches_stage_resolved', $result['reason']);
    }

    public function test_gate_blocks_missing_invalid_or_unresolved_auto(): void
    {
        $gate = app(PedagogyMatchesStageGate::class);

        $this->assertSame('pedagogy_matches_stage_missing_dreyfus_stage', $gate->evaluate([])['reason']);
        $this->assertSame('pedagogy_matches_stage_invalid_level', $gate->evaluate([
            'cognitive_decision' => [
                'dreyfus_stage_resolved' => 9,
                'pedagogy_mode_resolved' => 'expert',
            ],
        ])['reason']);
        $this->assertSame('pedagogy_matches_stage_unresolved_auto', $gate->evaluate([
            'cognitive_decision' => [
                'dreyfus_stage_resolved' => 2,
                'pedagogy_mode_resolved' => 'auto',
            ],
        ])['reason']);
    }
}
