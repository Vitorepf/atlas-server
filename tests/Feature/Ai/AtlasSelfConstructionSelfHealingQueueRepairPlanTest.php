<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionSelfHealingQueueRepairPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSelfHealingQueueRepairPlanTest extends TestCase
{
    private AtlasSelfConstructionSelfHealingQueueRepairPlan $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasSelfConstructionSelfHealingQueueRepairPlan;
    }

    private function go(array $packets): array
    {
        return $this->planner->plan($packets);
    }

    private function packet(array $overrides = []): array
    {
        return array_merge(['packet_id' => 'p-1'], $overrides);
    }

    // ── AC2: emergency → operator_visible, visibility_only, no auto repair ────

    public function test_emergency_packet_goes_to_operator_visible_bucket(): void
    {
        $r = $this->go([$this->packet(['emergency_kind' => 'constitution_edit_attempt'])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE, $r['actions'][0]['bucket']);
    }

    public function test_emergency_action_is_visibility_only(): void
    {
        $r = $this->go([$this->packet(['emergency_kind' => 'constitution_edit_attempt'])]);

        $this->assertSame('visibility_only', $r['actions'][0]['action']);
    }

    public function test_emergency_does_not_produce_automatic_repair_actions(): void
    {
        $r = $this->go([$this->packet(['emergency_kind' => 'forbidden_scope', 'malformed' => true])]);

        $this->assertCount(1, $r['actions']);
        $this->assertSame('visibility_only', $r['actions'][0]['action']);
    }

    // ── AC3: bucket routing ───────────────────────────────────────────────────

    public function test_repeated_returns_at_threshold_goes_to_quarantine(): void
    {
        $r = $this->go([$this->packet(['repeated_returns' => AtlasSelfConstructionSelfHealingQueueRepairPlan::QUARANTINE_THRESHOLD])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_QUARANTINE, $r['actions'][0]['bucket']);
        $this->assertSame('cancel_until_respec', $r['actions'][0]['action']);
    }

    public function test_malformed_packet_goes_to_template_repair(): void
    {
        $r = $this->go([$this->packet(['malformed' => true])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_template_repair_packet', $r['actions'][0]['action']);
    }

    public function test_missing_dependency_goes_to_dependency_rewrite(): void
    {
        $r = $this->go([$this->packet(['missing_dependency' => 'task-x'])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_DEPENDENCY, $r['actions'][0]['bucket']);
    }

    public function test_stuck_packet_goes_to_dependency_rewrite(): void
    {
        $r = $this->go([$this->packet(['stuck' => true])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_DEPENDENCY, $r['actions'][0]['bucket']);
    }

    public function test_low_servable_depth_goes_to_queue_top_up(): void
    {
        $r = $this->go([$this->packet(['low_servable_depth' => true])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TOP_UP, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_top_up_packet', $r['actions'][0]['action']);
    }

    public function test_repeated_give_back_family_at_threshold_goes_to_respec(): void
    {
        $r = $this->go([$this->packet([
            'give_back_family'    => 'scope-repair',
            'repeated_give_backs' => AtlasSelfConstructionSelfHealingQueueRepairPlan::GIVE_BACK_RESPEC_THRESHOLD,
        ])]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RESPEC, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_respec_packet', $r['actions'][0]['action']);
    }

    // ── AC4: deterministic sort + summary counts ──────────────────────────────

    public function test_actions_sorted_by_bucket_and_packet_id(): void
    {
        $r = $this->go([
            $this->packet(['packet_id' => 'p-z', 'malformed' => true]),
            $this->packet(['packet_id' => 'p-a', 'emergency_kind' => 'danger']),
        ]);

        // operator_visible ('o') < template_repair ('t') lexically
        $this->assertSame('operator_visible', $r['actions'][0]['bucket']);
        $this->assertSame('template_repair',  $r['actions'][1]['bucket']);
    }

    public function test_summary_counts_per_bucket(): void
    {
        $r = $this->go([
            $this->packet(['packet_id' => 'p1', 'malformed' => true]),
            $this->packet(['packet_id' => 'p2', 'malformed' => true]),
            $this->packet(['packet_id' => 'p3', 'emergency_kind' => 'danger']),
        ]);

        $this->assertSame(2, $r['summary'][AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE]);
        $this->assertSame(1, $r['summary'][AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE]);
    }

    public function test_output_is_deterministic(): void
    {
        $packets = [
            $this->packet(['packet_id' => 'p1', 'repeated_returns' => 5]),
            $this->packet(['packet_id' => 'p2', 'emergency_kind' => 'x']),
        ];

        $this->assertSame(json_encode($this->go($packets)), json_encode($this->go($packets)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->go([]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::SCHEMA, $r['schema']);
    }
}
