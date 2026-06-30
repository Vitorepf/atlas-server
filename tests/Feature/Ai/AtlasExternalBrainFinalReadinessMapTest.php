<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalReadinessMap;
use Tests\TestCase;

final class AtlasExternalBrainFinalReadinessMapTest extends TestCase
{
    private function svc(): AtlasExternalBrainFinalReadinessMap
    {
        return new AtlasExternalBrainFinalReadinessMap;
    }

    private function fullyProvenRecord(array $overrides = []): array
    {
        return array_merge([
            'status'                 => AtlasExternalBrainFinalReadinessMap::STATUS_PROVEN,
            'unresolved_count'       => 0,
            'has_runnable_proof'     => true,
            'knowledge_sync_current' => true,
            'operator_independence'  => true,
            'poison_blocker_open'    => false,
            'impact'                 => 'medium',
        ], $overrides);
    }

    private function mapArea(string $area, array $record): array
    {
        $r = $this->svc()->map([$area => $record]);
        return $r['area_readiness'][0];
    }

    // ── AC1: area evidence maps to readiness bands with risks, gaps and next_leverage ──

    public function test_ac1_area_entry_has_required_fields(): void
    {
        $entry = $this->mapArea('maestro', $this->fullyProvenRecord());

        $this->assertArrayHasKey('risks',        $entry);
        $this->assertArrayHasKey('gaps',         $entry);
        $this->assertArrayHasKey('next_leverage', $entry);
        $this->assertArrayHasKey('impact',       $entry);
        $this->assertArrayHasKey('priority_score', $entry);
    }

    public function test_ac1_fully_proven_area_has_empty_risks_and_gaps(): void
    {
        $entry = $this->mapArea('maestro', $this->fullyProvenRecord());

        $this->assertSame([], $entry['risks'],  'fully proven area must have no risks');
        $this->assertSame([], $entry['gaps'],   'fully proven area must have no gaps');
        $this->assertSame(0,  $entry['priority_score']);
    }

    public function test_ac1_partially_proven_area_lists_risks(): void
    {
        $entry = $this->mapArea('learning', [
            'status'                 => AtlasExternalBrainFinalReadinessMap::STATUS_PARTIALLY_PROVEN,
            'has_runnable_proof'     => false,
            'knowledge_sync_current' => false,
            'operator_independence'  => false,
            'impact'                 => 'high',
        ]);

        $this->assertNotEmpty($entry['risks']);
        $this->assertContains('status_not_proven', $entry['risks']);
    }

    public function test_ac1_next_leverage_is_non_empty_for_non_proven_area(): void
    {
        $entry = $this->mapArea('originator', [
            'status' => AtlasExternalBrainFinalReadinessMap::STATUS_MISSING,
            'impact' => 'high',
        ]);

        $this->assertNotSame('none', $entry['next_leverage']);
        $this->assertNotEmpty($entry['next_leverage']);
    }

    // ── AC2: missing or stale evidence lowers readiness ───────────────────────

    public function test_ac2_proven_status_without_runnable_proof_is_not_fully_ready(): void
    {
        $r = $this->svc()->map([
            'anti_goodhart' => $this->fullyProvenRecord(['has_runnable_proof' => false]),
        ]);

        $this->assertContains('anti_goodhart', $r['blocking_areas'],
            'proven status without runnable proof must still block final_ready');
        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $r['overall_status']);
    }

    public function test_ac2_stale_knowledge_sync_appears_in_risks(): void
    {
        $entry = $this->mapArea('task_fabric', $this->fullyProvenRecord([
            'knowledge_sync_current' => false,
        ]));

        $this->assertContains('stale_knowledge_sync', $entry['risks'],
            'stale knowledge_sync must appear as a risk even when status is proven');
    }

    public function test_ac2_stale_evidence_on_critical_area_blocks_final_ready(): void
    {
        $r = $this->svc()->map([
            'runtime' => $this->fullyProvenRecord(['knowledge_sync_current' => false]),
        ]);

        $this->assertContains('runtime', $r['blocking_areas']);
        $this->assertArrayHasKey('runtime', $r['missing_evidence_by_area']);
    }

    public function test_ac2_missing_status_area_does_not_emit_stale_knowledge_sync(): void
    {
        // missing area has no knowledge to be stale
        $entry = $this->mapArea('consolidation', [
            'status'                 => AtlasExternalBrainFinalReadinessMap::STATUS_MISSING,
            'knowledge_sync_current' => false,
        ]);

        $this->assertNotContains('stale_knowledge_sync', $entry['risks'],
            'missing status area must not emit stale_knowledge_sync risk');
    }

    // ── AC3: high-impact low-readiness areas outrank cosmetic mature areas ────

    public function test_ac3_high_impact_not_ready_area_has_higher_priority_score_than_low_impact_mature(): void
    {
        $r = $this->svc()->map([
            'mature_low_impact' => $this->fullyProvenRecord(['impact' => 'low']),  // priority_score=0
            'broken_high_impact' => [
                'status'                 => AtlasExternalBrainFinalReadinessMap::STATUS_MISSING,
                'impact'                 => 'high',
                'has_runnable_proof'     => false,
                'knowledge_sync_current' => false,
                'operator_independence'  => false,
            ],
        ]);

        $byArea = array_column($r['area_readiness'], null, 'area');
        $this->assertGreaterThan(
            $byArea['mature_low_impact']['priority_score'],
            $byArea['broken_high_impact']['priority_score'],
            'high-impact broken area must have higher priority_score than mature low-impact area'
        );
    }

    public function test_ac3_area_readiness_sorted_by_priority_score_desc(): void
    {
        $r = $this->svc()->map([
            'proven_medium' => $this->fullyProvenRecord(['impact' => 'medium']),  // score=0
            'missing_low'   => ['status' => 'missing', 'impact' => 'low'],        // score=1
            'missing_high'  => ['status' => 'missing', 'impact' => 'high'],       // score=3
        ]);

        $scores = array_column($r['area_readiness'], 'priority_score');
        $this->assertGreaterThanOrEqual($scores[1], $scores[0],
            'first area must have highest priority_score');
        $this->assertGreaterThanOrEqual($scores[2], $scores[1],
            'second area must have priority_score >= third');
    }

    // ── AC4: deterministic / pure ─────────────────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $areas = [
            'originator'  => $this->fullyProvenRecord(['impact' => 'high']),
            'task_fabric' => ['status' => 'missing', 'impact' => 'medium'],
        ];

        $this->assertSame(
            json_encode($this->svc()->map($areas), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->map($areas), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_empty_input_produces_final_ready(): void
    {
        $r = $this->svc()->map([]);
        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_FINAL_READY, $r['overall_status']);
        $this->assertSame(100.0, $r['final_readiness_percent']);
    }
}
