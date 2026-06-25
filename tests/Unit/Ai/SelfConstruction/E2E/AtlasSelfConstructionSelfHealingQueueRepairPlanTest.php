<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionSelfHealingQueueRepairPlan;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionSelfHealingQueueRepairPlan: repeated_returns >= QUARANTINE_THRESHOLD ⇒
 * quarantine (poison re-serve prevented); missing_dependency ⇒ dependency_rewrite; clean queue ⇒
 * empty actions; emergency_kind ⇒ operator_visible with action='visibility_only' (operator NOT
 * automated); malformed packet ⇒ template_repair.
 */
final class AtlasSelfConstructionSelfHealingQueueRepairPlanTest extends TestCase
{
    public function test_poison_re_serve_prevention_quarantine_after_threshold(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-poison', 'repeated_returns' => 5],
        ]);
        $this->assertCount(1, $r['actions']);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_QUARANTINE, $r['actions'][0]['bucket']);
        $this->assertSame('cancel_until_respec', $r['actions'][0]['action']);
    }

    public function test_missing_dependency_rewrites_packet(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-dep', 'missing_dependency' => 'svc-A'],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_DEPENDENCY, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_dependency_rewrite_packet', $r['actions'][0]['action']);
    }

    public function test_clean_queue_with_no_signals_emits_no_actions(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-clean'],
        ]);
        $this->assertSame([], $r['actions']);
    }

    public function test_emergency_kind_yields_operator_visible_visibility_only(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-em', 'emergency_kind' => 'constitution_edit_attempt'],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE, $r['actions'][0]['bucket']);
        $this->assertSame('visibility_only', $r['actions'][0]['action']);
    }

    public function test_malformed_packet_routes_to_template_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-mal', 'malformed' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_template_repair_packet', $r['actions'][0]['action']);
    }

    public function test_scope_repaired_packet_emits_no_action_observed(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-sr', 'scope_repaired' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_SCOPE_REPAIRED, $r['actions'][0]['bucket']);
        $this->assertSame('no_action', $r['actions'][0]['action']);
    }

    public function test_actions_sorted_byte_stably_by_bucket_then_packet_id(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-z', 'repeated_returns' => 5],
            ['packet_id' => 'p-a', 'repeated_returns' => 5],
            ['packet_id' => 'p-m', 'malformed' => true],
        ]);
        $ids = array_column($r['actions'], 'packet_id');
        $this->assertSame(['p-a', 'p-z', 'p-m'], $ids); // quarantine bucket sorted first, then template
    }
}
