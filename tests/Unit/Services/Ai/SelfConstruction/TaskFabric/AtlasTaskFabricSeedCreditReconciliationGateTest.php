<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSeedCreditReconciliationGate;
use Tests\TestCase;

final class AtlasTaskFabricSeedCreditReconciliationGateTest extends TestCase
{
    private function gate(): AtlasTaskFabricSeedCreditReconciliationGate
    {
        return new AtlasTaskFabricSeedCreditReconciliationGate;
    }

    private function cleanRecord(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'enqueue_result' => 'enqueued',
            'post_round_check' => 'passed',
            'target_collision' => false,
            'malformed_spec' => false,
            'requested_seeds' => 10,
        ];
    }

    // ── AC: clean enqueues are credited ──

    public function test_clean_enqueue_is_credited(): void
    {
        $result = $this->gate()->reconcile($this->cleanRecord());

        $this->assertSame('credited', $result['verdict']);
        $this->assertSame(10, $result['credited_seeds']);
        $this->assertSame([], $result['denial_reasons']);
    }

    // ── AC: rejected specs receive zero seed credit ──

    public function test_rejected_spec_receives_zero_credit(): void
    {
        $result = $this->gate()->reconcile(array_merge($this->cleanRecord(), [
            'enqueue_result' => 'rejected',
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credited_seeds']);
        $this->assertNotEmpty($result['denial_reasons']);
    }

    // ── AC: malformed post-checks receive zero seed credit ──

    public function test_malformed_post_check_receives_zero_credit(): void
    {
        $result = $this->gate()->reconcile(array_merge($this->cleanRecord(), [
            'post_round_check' => 'failed',
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credited_seeds']);
    }

    // ── AC: target collisions receive zero seed credit ──

    public function test_target_collision_receives_zero_credit(): void
    {
        $result = $this->gate()->reconcile(array_merge($this->cleanRecord(), [
            'target_collision' => true,
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credited_seeds']);
    }

    public function test_malformed_spec_receives_zero_credit(): void
    {
        $result = $this->gate()->reconcile(array_merge($this->cleanRecord(), [
            'malformed_spec' => true,
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credited_seeds']);
    }

    // ── batch reconciliation ──

    public function test_batch_reconciliation_tracks_progress_toward_goal(): void
    {
        $result = $this->gate()->reconcileBatch([
            $this->cleanRecord(),
            array_merge($this->cleanRecord(), ['enqueue_result' => 'rejected']),
            array_merge($this->cleanRecord(), ['requested_seeds' => 20]),
        ]);

        $this->assertSame(3, count($result['results']));
        $this->assertSame(30, $result['total_credited_seeds']);
        $this->assertSame(40, $result['total_requested_seeds']);
        $this->assertFalse($result['goal_reached']);
        $this->assertGreaterThan(0, $result['progress_pct']);
    }

    public function test_goal_reached_when_credited_seeds_exceed_500(): void
    {
        $result = $this->gate()->reconcileBatch([
            array_merge($this->cleanRecord(), ['requested_seeds' => 500]),
        ]);

        $this->assertTrue($result['goal_reached']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->reconcile($this->cleanRecord());

        $this->assertSame(AtlasTaskFabricSeedCreditReconciliationGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('credited_seeds', $result);
        $this->assertArrayHasKey('denial_reasons', $result);
        $this->assertArrayHasKey('seed_goal', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $record = $this->cleanRecord();
        $a = $this->gate()->reconcile($record);
        $b = $this->gate()->reconcile($record);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
