<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneBaselineCaptureReadinessService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplaySnapshotStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneBaselineCaptureReadinessServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AgentControlPlaneBaselineCaptureReadinessService
    {
        return new AgentControlPlaneBaselineCaptureReadinessService(new AgentControlPlaneReplaySnapshotStore('local'));
    }

    /** @return array{baseline: array, replay: array, diff: array, gate: array} */
    private function readyInputs(array $replayOverrides = []): array
    {
        return [
            'baseline' => ['status' => 'available', 'baseline_hash' => 'abc123', 'baseline_fingerprint' => 'fp1'],
            'replay' => array_merge([
                'status' => 'available',
                'deterministic_replay_hash' => 'replay-hash-1',
                'runtime_safety' => ['runtime_safety_all_false' => true],
                'violations' => [],
            ], $replayOverrides),
            'diff' => ['status' => 'clean', 'diff_hash' => 'diff-hash-1'],
            'gate' => ['status' => 'pass', 'gate_hash' => 'gate-hash-1'],
        ];
    }

    // ── AC2: hash stable across assessed_at despite different call times ─────

    public function test_hash_is_stable_across_repeated_assessments_with_equivalent_inputs(): void
    {
        $inputs = $this->readyInputs();
        $service = $this->service();

        $first = $service->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);
        sleep(1);
        $second = $service->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);

        $this->assertNotSame($first['assessed_at'], $second['assessed_at']);
        $this->assertSame($first['baseline_capture_readiness_hash'], $second['baseline_capture_readiness_hash']);
    }

    // ── AC3: real changes (replay hash, snapshot state) change the hash ───────

    public function test_changing_current_deterministic_replay_hash_changes_the_hash(): void
    {
        $inputs = $this->readyInputs();
        $service = $this->service();

        $first = $service->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);

        $changedInputs = $this->readyInputs(['deterministic_replay_hash' => 'replay-hash-2']);
        $second = $service->assess($changedInputs['baseline'], $changedInputs['replay'], $changedInputs['diff'], $changedInputs['gate']);

        $this->assertNotSame($first['baseline_capture_readiness_hash'], $second['baseline_capture_readiness_hash']);
    }

    public function test_changing_snapshot_state_changes_the_hash(): void
    {
        $inputs = $this->readyInputs();
        $service = $this->service();

        $withoutSnapshot = $service->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);
        $this->assertSame('missing', $withoutSnapshot['snapshot_state']);

        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $store->put($inputs['replay']);

        $withSnapshot = $service->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);

        $this->assertSame('current', $withSnapshot['snapshot_state']);
        $this->assertNotSame($withoutSnapshot['baseline_capture_readiness_hash'], $withSnapshot['baseline_capture_readiness_hash']);
    }

    // ── AC4: blocked readiness keeps can_capture_snapshot=false with the exact next_action ──

    public function test_blocked_baseline_status_blocks_capture(): void
    {
        $inputs = $this->readyInputs();
        $result = $this->service()->assess(
            array_merge($inputs['baseline'], ['status' => 'blocked']),
            $inputs['replay'],
            $inputs['diff'],
            $inputs['gate'],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_capture_snapshot']);
        $this->assertSame('resolve_baseline_capture_blockers_before_recording_snapshot', $result['next_action']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_runtime_safety_not_all_false_blocks_capture(): void
    {
        $inputs = $this->readyInputs(['runtime_safety' => ['runtime_safety_all_false' => false]]);
        $result = $this->service()->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);

        $this->assertFalse($result['can_capture_snapshot']);
        $this->assertContains('runtime_safety_not_all_false', $result['blockers']);
        $this->assertSame('resolve_baseline_capture_blockers_before_recording_snapshot', $result['next_action']);
    }

    public function test_never_allows_runtime_or_execution_flags(): void
    {
        $inputs = $this->readyInputs();
        $result = $this->service()->assess($inputs['baseline'], $inputs['replay'], $inputs['diff'], $inputs['gate']);

        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['runtime_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertTrue($result['read_only']);
    }
}
