<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalResearchFrontierTriageEngine;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalResearchFrontierTriageEngineTest extends TestCase
{
    private AtlasExternalBrainLocalResearchFrontierTriageEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new AtlasExternalBrainLocalResearchFrontierTriageEngine;
    }

    private function triage(array $rows): array
    {
        return $this->engine->triage(['frontier_rows' => $rows]);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                          => 'row-1',
            'title'                       => 'Improve reliability of X',
            'evidence_strength'           => 0.75,
            'has_code'                    => true,
            'has_benchmark'               => false,
            'hype_signals'                => [],
            'atlas_fit_score'             => 0.90,
            'implementation_risk'         => 0.10,
            'safety_risk'                 => 0.0,
            'provider_steady_state_dependency' => false,
            'expected_compounding_impact' => 0.80,
            'implementation_target'       => 'app/Services/Ai/Foo.php',
            'test_target'                 => 'tests/Feature/Ai/FooTest.php',
        ], $overrides);
    }

    // ── AC1: high-hype low-fit ranked below boring high-leverage repairs ───────

    public function test_high_hype_low_evidence_is_rejected_not_promoted(): void
    {
        $result = $this->triage([
            $this->row([
                'id'              => 'hype-idea',
                'hype_signals'    => ['viral', 'trending'],
                'evidence_strength' => 0.10,
            ]),
        ]);

        $this->assertCount(1, $result['hype_rejected']);
        $this->assertCount(0, $result['promising']);
        $this->assertEmpty($result['leverage_rank']);
    }

    public function test_boring_high_leverage_repair_outranks_hype_idea_in_leverage_rank(): void
    {
        $result = $this->triage([
            $this->row([
                'id'                          => 'boring-repair',
                'hype_signals'                => [],
                'evidence_strength'           => 0.80,
                'has_code'                    => true,
                'expected_compounding_impact' => 0.90,
            ]),
            $this->row([
                'id'              => 'hype-idea',
                'hype_signals'    => ['viral', 'trending'],
                'evidence_strength' => 0.15,
                'has_code'        => false,
            ]),
        ]);

        // Boring repair is in leverage_rank; hype idea is in hype_rejected
        $leverageIds = array_column($result['leverage_rank'], 'id');
        $hypeIds     = array_column($result['hype_rejected'], 'id');

        $this->assertContains('boring-repair', $leverageIds);
        $this->assertNotContains('hype-idea', $leverageIds);
        $this->assertContains('hype-idea', $hypeIds);
    }

    public function test_leverage_rank_orders_by_compounding_impact_descending(): void
    {
        $result = $this->triage([
            $this->row(['id' => 'low-leverage',  'expected_compounding_impact' => 0.30]),
            $this->row(['id' => 'high-leverage', 'expected_compounding_impact' => 0.90,
                'allowed_files' => ['app/Services/Bar.php', 'tests/Feature/BarTest.php']]),
        ]);

        $ids = array_column($result['leverage_rank'], 'id');
        $this->assertSame('high-leverage', $ids[0]);
    }

    // ── AC2: selected ideas include task_seed_hints ────────────────────────────

    public function test_promising_entry_includes_task_seed_hints(): void
    {
        $result = $this->triage([$this->row()]);

        $this->assertNotEmpty($result['promising']);
        $hints = $result['promising'][0]['task_seed_hints'];
        $this->assertArrayHasKey('implementation_target', $hints);
        $this->assertArrayHasKey('test_target', $hints);
        $this->assertArrayHasKey('expected_leverage', $hints);
    }

    public function test_task_seed_hints_reflect_row_targets(): void
    {
        $result = $this->triage([$this->row([
            'implementation_target' => 'app/Services/Ai/Brain.php',
            'test_target'           => 'tests/Feature/Ai/BrainTest.php',
        ])]);

        $hints = $result['promising'][0]['task_seed_hints'];
        $this->assertSame('app/Services/Ai/Brain.php', $hints['implementation_target']);
        $this->assertSame('tests/Feature/Ai/BrainTest.php', $hints['test_target']);
    }

    public function test_task_seed_hints_expected_leverage_matches_compounding_impact(): void
    {
        $result = $this->triage([$this->row(['expected_compounding_impact' => 0.85])]);

        $this->assertSame(0.85, $result['promising'][0]['task_seed_hints']['expected_leverage']);
    }

    // ── AC3: unknown evidence or high safety risk → hold_for_review ───────────

    public function test_evidence_quality_unknown_produces_hold_for_review(): void
    {
        $result = $this->triage([$this->row(['evidence_quality_unknown' => true])]);

        $this->assertCount(1, $result['hold_for_review']);
        $this->assertCount(0, $result['promising']);
        $this->assertSame('evidence_quality_unknown', $result['hold_for_review'][0]['reason']);
    }

    public function test_high_safety_risk_produces_hold_for_review(): void
    {
        $result = $this->triage([$this->row(['safety_risk' => 0.80])]);

        $this->assertCount(1, $result['hold_for_review']);
        $this->assertCount(0, $result['promising']);
        $this->assertSame('safety_risk_above_ceiling', $result['hold_for_review'][0]['reason']);
    }

    public function test_borderline_safety_risk_below_ceiling_is_not_held(): void
    {
        $result = $this->triage([$this->row(['safety_risk' => 0.50])]);

        $this->assertCount(0, $result['hold_for_review']);
        $this->assertCount(1, $result['promising']);
    }

    public function test_hold_for_review_count_is_accurate(): void
    {
        $result = $this->triage([
            $this->row(['id' => 'a', 'evidence_quality_unknown' => true]),
            $this->row(['id' => 'b', 'safety_risk' => 0.90]),
            $this->row(['id' => 'c']),  // not held
        ]);

        $this->assertSame(2, $result['hold_for_review_count']);
    }

    // ── AC4: deterministic and provider-independent ────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $rows = [$this->row(['id' => 'fixed'])];

        $this->assertSame(json_encode($this->triage($rows)), json_encode($this->triage($rows)));
    }

    public function test_schema_is_correct(): void
    {
        $result = $this->triage([]);

        $this->assertSame(AtlasExternalBrainLocalResearchFrontierTriageEngine::SCHEMA, $result['schema_version']);
    }

    public function test_empty_frontier_returns_empty_buckets(): void
    {
        $result = $this->triage([]);

        $this->assertSame(0, $result['promising_count']);
        $this->assertSame(0, $result['exploratory_count']);
        $this->assertSame(0, $result['hold_for_review_count']);
    }
}
