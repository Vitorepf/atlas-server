<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPoisonPacketRemediationRouter;
use Tests\TestCase;

final class AtlasExternalBrainPoisonPacketRemediationRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainPoisonPacketRemediationRouter
    {
        return new AtlasExternalBrainPoisonPacketRemediationRouter;
    }

    // ── AC: malformed missing scope routes to repair ──

    public function test_missing_scope_routes_to_repair(): void
    {
        $result = $this->router()->route([
            'task_id' => 't1',
            'reason' => 'missing_scope',
        ]);

        $this->assertSame('repair', $result['action']);
        $this->assertTrue($result['requeue_allowed']);
    }

    public function test_missing_objective_routes_to_repair(): void
    {
        $result = $this->router()->route([
            'task_id' => 't2',
            'reason' => 'missing_objective',
        ]);

        $this->assertSame('repair', $result['action']);
    }

    public function test_missing_allowed_files_routes_to_repair(): void
    {
        $result = $this->router()->route([
            'task_id' => 't3',
            'reason' => 'missing_allowed_files',
        ]);

        $this->assertSame('repair', $result['action']);
    }

    // ── AC: forbidden self-target routes to retire ──

    public function test_forbidden_self_target_routes_to_retire(): void
    {
        $result = $this->router()->route([
            'task_id' => 't4',
            'reason' => 'forbidden_self_target',
        ]);

        $this->assertSame('retire', $result['action']);
        $this->assertFalse($result['requeue_allowed']);
    }

    // ── AC: duplicate satisfied work routes to give_back ──

    public function test_duplicate_satisfied_work_routes_to_give_back(): void
    {
        $result = $this->router()->route([
            'task_id' => 't5',
            'reason' => 'duplicate_satisfied_work',
        ]);

        $this->assertSame('give_back', $result['action']);
    }

    public function test_already_completed_routes_to_give_back(): void
    {
        $result = $this->router()->route([
            'task_id' => 't6',
            'reason' => 'already_completed',
        ]);

        $this->assertSame('give_back', $result['action']);
    }

    // ── AC: active protected governance routes to keep_blocked ──

    public function test_active_protected_governance_routes_to_keep_blocked(): void
    {
        $result = $this->router()->route([
            'task_id' => 't7',
            'reason' => 'active_protected_governance',
        ]);

        $this->assertSame('keep_blocked', $result['action']);
        $this->assertFalse($result['requeue_allowed']);
    }

    public function test_operator_hold_routes_to_keep_blocked(): void
    {
        $result = $this->router()->route([
            'task_id' => 't8',
            'reason' => 'operator_hold',
        ]);

        $this->assertSame('keep_blocked', $result['action']);
    }

    // ── unknown reason defaults to keep_blocked ──

    public function test_unknown_reason_defaults_to_keep_blocked(): void
    {
        $result = $this->router()->route([
            'task_id' => 't9',
            'reason' => 'some_unknown_reason',
        ]);

        $this->assertSame('keep_blocked', $result['action']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->router()->route(['task_id' => 't', 'reason' => 'missing_scope']);

        $this->assertSame(AtlasExternalBrainPoisonPacketRemediationRouter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('remediation_guidance', $result);
        $this->assertArrayHasKey('requeue_allowed', $result);
    }

    public function test_batch_routing(): void
    {
        $result = $this->router()->routeBatch([
            ['task_id' => 't1', 'reason' => 'missing_scope'],
            ['task_id' => 't2', 'reason' => 'forbidden_self_target'],
            ['task_id' => 't3', 'reason' => 'duplicate_satisfied_work'],
        ]);

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['action_counts']['repair']);
        $this->assertSame(1, $result['action_counts']['retire']);
        $this->assertSame(1, $result['action_counts']['give_back']);
    }
}
