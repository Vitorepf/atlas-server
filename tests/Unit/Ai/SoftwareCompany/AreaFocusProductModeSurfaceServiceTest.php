<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompany;

use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SoftwareCompany\AreaFocusProductModeSurfaceService;
use Tests\TestCase;

/**
 * Unit tests for the Area Focus Product Mode surface shaping (AP-721).
 *
 * The scan is injected through the loop's `gap_read_model` override, so the
 * surface is projected deterministically with zero side effects.
 */
class AreaFocusProductModeSurfaceServiceTest extends TestCase
{
    private function service(): AreaFocusProductModeSurfaceService
    {
        return app(AreaFocusProductModeSurfaceService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        $hash = $overrides['candidate_hash'] ?? ('sha256:'.hash('sha256', 'seed'));

        return array_merge([
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'candidate_id' => 'gapc_'.substr(hash('sha256', (string) $hash), 0, 12),
            'candidate_hash' => $hash,
            'source_owner' => 'self_improvement',
            'gap_kind' => 'self_improvement_backlog_item',
            'title' => 'Improve something',
            'risk_level' => 'medium',
            'priority_score' => 201,
            'evidence_refs' => ['ev1'],
            'owner_doc_refs' => ['docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md'],
            'proposed_next_action' => 'surface',
        ], $overrides);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function project(array $candidates): array
    {
        return $this->service()->project('agentic_engineering_os', [
            'gap_read_model' => [
                'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
                'status' => 'ready',
                'candidates' => $candidates,
                'blockers' => [],
                'report_hash' => 'sha256:'.hash('sha256', 'scan'),
            ],
        ]);
    }

    public function test_emits_canonical_schema_with_all_desktop_sections(): void
    {
        $surface = $this->project([$this->candidate()]);

        $this->assertSame('atlas.night_shift.area_focus_product_mode_surface.v1', $surface['schema_version']);
        $this->assertTrue($surface['read_only']);
        foreach (['area_summary', 'health', 'findings', 'inbox_items', 'work_orders', 'budgets', 'evidence_packs', 'kill_switch_state', 'next_actions', 'surface_hash', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $surface, "missing surface key {$key}");
        }
        $this->assertStringStartsWith('sha256:', $surface['surface_hash']);
    }

    public function test_health_is_healthy_with_no_risk_and_watch_with_high_risk(): void
    {
        $healthy = $this->project([$this->candidate(['candidate_hash' => 'sha256:low', 'risk_level' => 'low'])]);
        $this->assertSame('healthy', $healthy['health']['overall']);
        $this->assertSame(0, $healthy['health']['high_risk_count']);

        $watch = $this->project([$this->candidate(['candidate_hash' => 'sha256:hi', 'risk_level' => 'high'])]);
        $this->assertSame('watch', $watch['health']['overall']);
        $this->assertSame(1, $watch['health']['high_risk_count']);
    }

    public function test_kill_switch_required_but_not_engaged(): void
    {
        $surface = $this->project([$this->candidate()]);
        $this->assertTrue($surface['kill_switch_state']['required']);
        $this->assertFalse($surface['kill_switch_state']['engaged']);
        $this->assertFalse($surface['kill_switch_state']['execution_enabled']);
        $this->assertSame('operator', $surface['kill_switch_state']['controlled_by']);
    }

    public function test_work_orders_never_executed_and_pending_operator(): void
    {
        $surface = $this->project([
            $this->candidate(['candidate_hash' => 'sha256:a', 'risk_level' => 'low']),
            $this->candidate(['candidate_hash' => 'sha256:b', 'source_owner' => 'aael', 'gap_kind' => 'aael_promotion_blocked']),
        ]);

        $this->assertNotEmpty($surface['work_orders']);
        foreach ($surface['work_orders'] as $order) {
            $this->assertFalse($order['execution_executed']);
            $this->assertSame('planned_pending_operator', $order['status']);
            $this->assertTrue($order['evidence_required']);
        }
        $this->assertFalse($surface['budgets']['execution_executed']);
    }

    public function test_inbox_items_carry_decision_options(): void
    {
        $surface = $this->project([$this->candidate(['candidate_hash' => 'sha256:hi', 'risk_level' => 'high'])]);

        $this->assertNotEmpty($surface['inbox_items']);
        foreach ($surface['inbox_items'] as $item) {
            $this->assertTrue($item['decision_required']);
            $this->assertContains('approve', $item['decision_options']);
            $this->assertContains('reject', $item['decision_options']);
        }
    }

    public function test_evidence_packs_empty_in_read_only_slice(): void
    {
        $surface = $this->project([$this->candidate()]);
        $this->assertTrue($surface['evidence_packs']['required']);
        $this->assertSame([], $surface['evidence_packs']['packs']);
    }

    public function test_surface_hash_is_deterministic(): void
    {
        $candidates = [
            $this->candidate(['candidate_hash' => 'sha256:1', 'risk_level' => 'low']),
            $this->candidate(['candidate_hash' => 'sha256:2', 'source_owner' => 'self_construction', 'gap_kind' => 'partial_canon']),
        ];

        $a = $this->project($candidates);
        $b = $this->project($candidates);

        $this->assertSame($a['surface_hash'], $b['surface_hash']);
        $this->assertSame($a['work_orders'], $b['work_orders']);
    }

    public function test_unknown_area_is_blocked(): void
    {
        $surface = $this->service()->project('not_a_real_area', [
            'gap_read_model' => ['schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA, 'status' => 'ready', 'candidates' => [], 'blockers' => []],
        ]);

        $this->assertSame('blocked', $surface['status']);
        $this->assertSame('unknown_area', $surface['reason']);
        $this->assertContains('agentic_engineering_os', $surface['supported_areas']);
    }
}
