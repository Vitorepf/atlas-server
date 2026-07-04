<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainUnifiedControlPlaneSnapshotTest extends TestCase
{
    private AtlasExternalBrainUnifiedControlPlaneSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = new AtlasExternalBrainUnifiedControlPlaneSnapshot();
    }

    // AC: stale evidence routes to refresh_evidence before generic create_more_tasks
    public function test_stale_evidence_routes_to_refresh(): void
    {
        $result = $this->snapshot->snapshot([
            'stale_evidence_count' => 3,
            'stop_go_verdict' => 'green',
        ]);

        $this->assertSame('refresh_evidence', $result['recommended_next_decision']);
    }

    // AC: provider_independence=failing routes to close_provider_dependency with red
    public function test_provider_failing_routes_to_close_dependency(): void
    {
        $result = $this->snapshot->snapshot([
            'provider_independence' => 'failing',
            'stop_go_verdict' => 'green',
        ]);

        $this->assertSame('close_provider_dependency', $result['recommended_next_decision']);
        $this->assertSame('red', $result['stop_go_verdict']);
    }

    // AC: maturity_gap_count with no red/yellow → compile_gap_chain
    public function test_maturity_gaps_no_blockers_routes_to_compile(): void
    {
        $result = $this->snapshot->snapshot([
            'maturity_gap_count' => 5,
            'red_blocker_count' => 0,
            'yellow_blocker_count' => 0,
        ]);

        $this->assertSame('compile_gap_chain', $result['recommended_next_decision']);
    }

    public function test_no_blockers_routes_to_create_more_tasks(): void
    {
        $result = $this->snapshot->snapshot([
            'stale_evidence_count' => 0,
            'provider_independence' => 'passing',
            'maturity_gap_count' => 0,
            'red_blocker_count' => 0,
            'yellow_blocker_count' => 0,
        ]);

        $this->assertSame('create_more_tasks', $result['recommended_next_decision']);
    }

    public function test_yellow_blockers_route_to_harden_task_fabric(): void
    {
        $result = $this->snapshot->snapshot([
            'yellow_blocker_count' => 2,
            'red_blocker_count' => 0,
        ]);

        $this->assertSame('harden_task_fabric', $result['recommended_next_decision']);
    }

    // AC: unified snapshot includes external_brain_state, muscle_state, proof_state, task_fabric_state, queue_state
    public function test_snapshot_includes_all_subsystem_states(): void
    {
        $result = $this->snapshot->snapshot([]);

        $this->assertArrayHasKey('external_brain_state', $result);
        $this->assertArrayHasKey('muscle_state', $result);
        $this->assertArrayHasKey('proof_state', $result);
        $this->assertArrayHasKey('task_fabric_state', $result);
        $this->assertArrayHasKey('queue_state', $result);
    }

    // AC: snapshot emits recommended_decision plus evidence_refs
    public function test_snapshot_emits_recommended_decision_and_evidence_refs(): void
    {
        $result = $this->snapshot->snapshot([
            'evidence_refs' => ['ref:001', 'ref:002'],
        ]);

        $this->assertArrayHasKey('recommended_decision', $result);
        $this->assertNotEmpty($result['recommended_decision']);
        $this->assertArrayHasKey('evidence_refs', $result);
        $this->assertSame(['ref:001', 'ref:002'], $result['evidence_refs']);
    }

    // AC: provider-sensitive internals are not emitted
    public function test_provider_sensitive_fields_are_filtered(): void
    {
        $result = $this->snapshot->snapshot([
            'external_brain_state' => [
                'autonomy_level' => 'full_autonomous',
                'raw_prompt' => 'this should be filtered',
                'provider_trace' => 'also filtered',
                'secret' => 'never included',
            ],
        ]);

        $state = $result['external_brain_state'];
        $this->assertArrayHasKey('autonomy_level', $state);
        $this->assertArrayNotHasKey('raw_prompt', $state);
        $this->assertArrayNotHasKey('provider_trace', $state);
        $this->assertArrayNotHasKey('secret', $state);
    }

    // AC: subsystem states pass through provided data
    public function test_subsystem_states_pass_through_provided_data(): void
    {
        $result = $this->snapshot->snapshot([
            'external_brain_state' => ['autonomy_level' => 'supervised', 'maturity_risk' => 'medium'],
            'muscle_state' => ['active_workers' => 3, 'yield_rate' => 0.85],
            'proof_state' => ['evidence_freshness' => 'fresh', 'cert_gate_status' => 'passing'],
            'task_fabric_state' => ['queue_depth' => 12, 'blocked_count' => 0],
            'queue_state' => ['pending' => 5, 'in_progress' => 3, 'completed' => 100],
        ]);

        $this->assertSame(['autonomy_level' => 'supervised', 'maturity_risk' => 'medium'], $result['external_brain_state']);
        $this->assertSame(['active_workers' => 3, 'yield_rate' => 0.85], $result['muscle_state']);
        $this->assertSame(['evidence_freshness' => 'fresh', 'cert_gate_status' => 'passing'], $result['proof_state']);
        $this->assertSame(['queue_depth' => 12, 'blocked_count' => 0], $result['task_fabric_state']);
        $this->assertSame(['pending' => 5, 'in_progress' => 3, 'completed' => 100], $result['queue_state']);
    }
}
