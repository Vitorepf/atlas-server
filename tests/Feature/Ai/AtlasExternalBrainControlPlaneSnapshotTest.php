<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainControlPlaneSnapshotTest extends TestCase
{
    private AtlasExternalBrainControlPlaneSnapshot $snap;

    protected function setUp(): void
    {
        $this->snap = new AtlasExternalBrainControlPlaneSnapshot;
    }

    private function shot(array $inputs = []): array
    {
        return $this->snap->snapshot($inputs);
    }

    // ── AC1: snapshot includes required control-plane fields ──────────────────

    public function test_snapshot_includes_queue_health(): void
    {
        $r = $this->shot(['queue_health' => ['status' => 'healthy']]);

        $this->assertArrayHasKey('queue_health', $r);
        $this->assertSame('healthy', $r['queue_health']['status']);
    }

    public function test_snapshot_includes_worker_state(): void
    {
        $r = $this->shot(['worker_state' => ['status' => 'active']]);

        $this->assertArrayHasKey('worker_state', $r);
        $this->assertSame('active', $r['worker_state']);
    }

    public function test_snapshot_includes_risk(): void
    {
        $r = $this->shot();

        $this->assertArrayHasKey('risk', $r);
        $this->assertIsString($r['risk']);
    }

    public function test_snapshot_includes_maturity_gaps(): void
    {
        $r = $this->shot();

        $this->assertArrayHasKey('maturity_gaps', $r);
        $this->assertIsArray($r['maturity_gaps']);
    }

    public function test_snapshot_includes_quality(): void
    {
        $r = $this->shot(['audit_result' => ['verdict' => 'pass']]);

        $this->assertArrayHasKey('quality', $r);
        $this->assertSame('good', $r['quality']);
    }

    public function test_snapshot_includes_recommended_decision(): void
    {
        $r = $this->shot();

        $this->assertArrayHasKey('recommended_decision', $r);
        $this->assertIsString($r['recommended_decision']);
    }

    // ── AC2: severe queue / high give_back changes recommended_decision ────────

    public function test_stalled_queue_changes_decision_away_from_create_more(): void
    {
        $r = $this->shot([
            'queue_health'  => ['status' => 'stalled'],
            'ledger_summary' => ['total' => 10, 'success_rate' => 0.9],
        ]);

        $this->assertNotSame('create_more_tasks', $r['recommended_decision']);
        $this->assertSame('repair_queue_before_creating_more', $r['recommended_decision']);
    }

    public function test_high_give_back_rate_changes_decision_away_from_create_more(): void
    {
        $r = $this->shot([
            'queue_health'   => ['status' => 'healthy', 'give_back_rate' => 0.50],
            'ledger_summary' => ['total' => 10, 'success_rate' => 0.5],
            'audit_result'   => ['verdict' => 'pass'],
        ]);

        $this->assertNotSame('create_more_tasks', $r['recommended_decision']);
        $this->assertSame('high', $r['risk']);
    }

    public function test_degraded_queue_gives_reduce_risk_decision(): void
    {
        $r = $this->shot([
            'queue_health'   => ['status' => 'degraded'],
            'ledger_summary' => ['total' => 5, 'success_rate' => 0.8],
            'audit_result'   => ['verdict' => 'pass'],
        ]);

        $this->assertSame('reduce_risk_before_creating_more', $r['recommended_decision']);
    }

    public function test_healthy_queue_with_ledger_and_clean_audit_allows_create_more(): void
    {
        $r = $this->shot([
            'queue_health'   => ['status' => 'healthy', 'give_back_rate' => 0.05],
            'ledger_summary' => ['total' => 20, 'success_rate' => 0.95],
            'audit_result'   => ['verdict' => 'pass'],
        ]);

        $this->assertSame('create_more_tasks', $r['recommended_decision']);
    }

    // ── AC3: missing inputs → explicit unknown/blocked, not fake green ─────────

    public function test_missing_queue_health_produces_unknown_status(): void
    {
        $r = $this->shot([]);

        $this->assertSame('unknown', $r['queue_health']['status']);
    }

    public function test_missing_worker_state_input_produces_unknown(): void
    {
        $r = $this->shot([]);

        $this->assertSame('unknown', $r['worker_state']);
    }

    public function test_missing_audit_result_produces_unknown_quality(): void
    {
        $r = $this->shot(['ledger_summary' => ['total' => 1, 'success_rate' => 1.0]]);

        $this->assertSame('unknown', $r['quality']);
    }

    public function test_reject_audit_verdict_produces_blocked_quality(): void
    {
        $r = $this->shot(['audit_result' => ['verdict' => 'reject']]);

        $this->assertSame('blocked', $r['quality']);
    }

    public function test_missing_ledger_produces_resolve_blockers_decision(): void
    {
        // No ledger → blocker added → not 'create_more_tasks'
        $r = $this->shot([
            'queue_health' => ['status' => 'healthy', 'give_back_rate' => 0.0],
            'audit_result' => ['verdict' => 'pass'],
        ]);

        $this->assertNotSame('create_more_tasks', $r['recommended_decision']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $inputs = ['queue_health' => ['status' => 'degraded']];

        $this->assertSame(json_encode($this->shot($inputs)), json_encode($this->shot($inputs)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->shot();

        $this->assertSame(AtlasExternalBrainControlPlaneSnapshot::SCHEMA, $r['schema']);
    }
}
