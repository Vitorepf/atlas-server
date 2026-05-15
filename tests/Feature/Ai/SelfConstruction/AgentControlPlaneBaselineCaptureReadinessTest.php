<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneBaselineCaptureReadinessService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneBaselineCaptureReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_missing_snapshot_is_ready_to_capture_without_writing(): void
    {
        $result = $this->newService()->assess(
            $this->baseline(),
            $this->replay('a'),
            $this->diff('no_baseline'),
            $this->gate('no_baseline'),
        );

        $this->assertSame('ready_to_capture_snapshot', $result['status']);
        $this->assertTrue((bool) $result['can_capture_snapshot']);
        $this->assertTrue((bool) $result['snapshot_capture_required']);
        $this->assertSame('missing', $result['snapshot_state']);
        $this->assertSame(0, (int) $result['registry_entry_count']);
        $this->assertSame(0, $this->store()->registry()['entry_count']);
        $this->assertFalse((bool) data_get($result, 'capture_plan.automatic_capture_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['baseline_capture_readiness_hash']);
    }

    public function test_current_snapshot_is_detected(): void
    {
        $store = $this->store();
        $replay = $this->replay('b');
        $store->put($replay, ['label' => 'current']);

        $result = (new AgentControlPlaneBaselineCaptureReadinessService($store))->assess(
            $this->baseline(),
            $replay,
            $this->diff('unchanged'),
            $this->gate('passed'),
        );

        $this->assertSame('current_snapshot_present', $result['status']);
        $this->assertFalse((bool) $result['snapshot_capture_required']);
        $this->assertSame('current', $result['snapshot_state']);
        $this->assertNotEmpty($result['latest_snapshot_id']);
        $this->assertSame($replay['deterministic_replay_hash'], $result['latest_snapshot_hash']);
    }

    public function test_stale_snapshot_requires_refresh(): void
    {
        $store = $this->store();
        $store->put($this->replay('old'), ['label' => 'old']);

        $result = (new AgentControlPlaneBaselineCaptureReadinessService($store))->assess(
            $this->baseline(),
            $this->replay('new'),
            $this->diff('changed'),
            $this->gate('warning'),
        );

        $this->assertSame('ready_to_refresh_snapshot', $result['status']);
        $this->assertTrue((bool) $result['snapshot_capture_required']);
        $this->assertSame('stale', $result['snapshot_state']);
        $this->assertContains('baseline_snapshot_stale', $result['warnings']);
    }

    public function test_degraded_baseline_is_warning_but_still_capturable(): void
    {
        $result = $this->newService()->assess(
            $this->baseline('degraded'),
            $this->replay('c'),
            $this->diff('no_baseline'),
            $this->gate('no_baseline'),
        );

        $this->assertSame('ready_to_capture_snapshot', $result['status']);
        $this->assertTrue((bool) $result['can_capture_snapshot']);
        $this->assertContains('certification_baseline_status_is_degraded', $result['warnings']);
    }

    public function test_blocked_when_baseline_is_blocked(): void
    {
        $result = $this->newService()->assess(
            $this->baseline('blocked'),
            $this->replay('blocked'),
            $this->diff('no_baseline'),
            $this->gate('no_baseline'),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse((bool) $result['can_capture_snapshot']);
        $this->assertContains('certification_baseline_status_is_blocked', $result['blockers']);
    }

    public function test_readiness_never_enables_runtime_flags(): void
    {
        $result = $this->newService()->assess(
            $this->baseline(),
            $this->replay('d'),
            $this->diff('no_baseline'),
            $this->gate('no_baseline'),
        );

        $this->assertTrue((bool) $result['read_only']);
        $this->assertFalse((bool) $result['execution_allowed']);
        $this->assertFalse((bool) $result['dispatch_allowed']);
        $this->assertFalse((bool) $result['ledger_write_allowed']);
        $this->assertFalse((bool) $result['runtime_write_allowed']);
        $this->assertFalse((bool) $result['external_provider_call']);
        $this->assertFalse((bool) $result['token_spend']);
        $this->assertFalse((bool) $result['process_started']);
        $this->assertFalse((bool) $result['provider_call_allowed']);
        $this->assertFalse((bool) $result['adapter_execution_allowed']);
        $this->assertFalse((bool) $result['self_programming_allowed']);
        $this->assertFalse((bool) $result['completion_claim_allowed']);
    }

    private function newService(): AgentControlPlaneBaselineCaptureReadinessService
    {
        return new AgentControlPlaneBaselineCaptureReadinessService($this->store());
    }

    private function store(): AgentControlPlaneReplaySnapshotStore
    {
        return new AgentControlPlaneReplaySnapshotStore('local');
    }

    /** @return array<string, mixed> */
    private function baseline(string $status = 'available'): array
    {
        return [
            'status' => $status,
            'baseline_hash' => str_repeat('1', 64),
            'baseline_fingerprint' => '111111111111',
        ];
    }

    /** @return array<string, mixed> */
    private function replay(string $seed): array
    {
        $hash = hash('sha256', 'replay-'.$seed);

        return [
            'status' => 'available',
            'replay_hash' => hash('sha256', 'volatile-'.$seed),
            'deterministic_replay_hash' => $hash,
            'proof_bundle_hash' => hash('sha256', 'proof-'.$seed),
            'current_pointer' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
            'expected_pointer' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
            'next_build_slices' => ['activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract'],
            'not_yet_runtime_capable' => ['adapter_execution_runtime'],
            'chain_integrity_hash' => hash('sha256', 'chain-'.$seed),
            'replayed_slice_count' => 1,
            'replayed_edge_count' => 1,
            'runtime_safety' => ['runtime_safety_all_false' => true],
            'violations' => [],
            'warnings' => [],
            'proof_bundle' => ['control_plane_summary' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function diff(string $status): array
    {
        return [
            'status' => $status,
            'diff_hash' => hash('sha256', 'diff-'.$status),
        ];
    }

    /** @return array<string, mixed> */
    private function gate(string $status): array
    {
        return [
            'status' => $status,
            'gate_hash' => hash('sha256', 'gate-'.$status),
        ];
    }
}
