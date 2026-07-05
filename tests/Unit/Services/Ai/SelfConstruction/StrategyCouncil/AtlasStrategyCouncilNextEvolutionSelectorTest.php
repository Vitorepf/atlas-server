<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilNextEvolutionSelector;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilNextEvolutionSelectorTest extends TestCase
{
    private AtlasStrategyCouncilNextEvolutionSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new AtlasStrategyCouncilNextEvolutionSelector;
    }

    public function test_selected_candidate_includes_capability_reason(): void
    {
        $result = $this->selector->select([
            ['id' => 'c1', 'capability_gap' => 0.9, 'implementation_surface' => 0.5, 'proof_path_score' => 0.8, 'queue_safety' => 0.9],
        ]);

        $this->assertNotNull($result['selected']);
        $this->assertStringContainsString('capability_gap', $result['selected']['capability_reason']);
    }

    public function test_selected_candidate_includes_target_surface_reason(): void
    {
        $result = $this->selector->select([
            ['id' => 'c1', 'capability_gap' => 0.9, 'implementation_surface' => 0.5, 'proof_path_score' => 0.8, 'queue_safety' => 0.9],
        ]);

        $this->assertStringContainsString('implementation_surface', $result['selected']['target_surface_reason']);
    }

    public function test_selected_candidate_includes_acceptance_proof_reason(): void
    {
        $result = $this->selector->select([
            ['id' => 'c1', 'capability_gap' => 0.9, 'implementation_surface' => 0.5, 'proof_path_score' => 0.8, 'queue_safety' => 0.9],
        ]);

        $this->assertStringContainsString('proof_path', $result['selected']['acceptance_proof_reason']);
    }

    public function test_selected_candidate_includes_collision_safety_reason(): void
    {
        $result = $this->selector->select([
            ['id' => 'c1', 'capability_gap' => 0.9, 'implementation_surface' => 0.5, 'proof_path_score' => 0.8, 'queue_safety' => 0.9],
        ]);

        $this->assertStringContainsString('queue_safety', $result['selected']['collision_safety_reason']);
    }

    public function test_highest_score_selected(): void
    {
        $result = $this->selector->select([
            ['id' => 'low', 'capability_gap' => 0.1, 'implementation_surface' => 0.1, 'proof_path_score' => 0.1, 'queue_safety' => 0.1],
            ['id' => 'high', 'capability_gap' => 0.9, 'implementation_surface' => 0.9, 'proof_path_score' => 0.9, 'queue_safety' => 0.9],
        ]);

        $this->assertSame('high', $result['selected']['id']);
    }

    public function test_empty_candidates_returns_null_selected(): void
    {
        $result = $this->selector->select([]);
        $this->assertNull($result['selected']);
    }

    public function test_schema_present(): void
    {
        $result = $this->selector->select([]);
        $this->assertSame(AtlasStrategyCouncilNextEvolutionSelector::SCHEMA, $result['schema']);
    }
}
