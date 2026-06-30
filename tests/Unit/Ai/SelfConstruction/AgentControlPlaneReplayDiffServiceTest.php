<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneReplayDiffServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function newDiffService(): AgentControlPlaneReplayDiffService
    {
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return new AgentControlPlaneReplayDiffService($store, $replay);
    }

    /** @return array<string, mixed> */
    private function freshReplay(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return $replay->replay();
    }

    public function test_no_regression_yields_none_severity(): void
    {
        $replay = $this->freshReplay();

        $diff = $this->newDiffService()->diff(null, $replay);

        $this->assertSame([], $diff['regression_severity_map']);
        $this->assertSame('none', $diff['highest_regression_severity']);
    }

    public function test_runtime_safety_loss_is_critical(): void
    {
        $before = $this->freshReplay();
        $before['runtime_safety'] = ['runtime_safety_all_false' => true];
        $after = $this->freshReplay();
        $after['runtime_safety'] = ['runtime_safety_all_false' => false];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('critical', $diff['regression_severity_map']['runtime_safety_dropped_from_all_false']);
        $this->assertSame('critical', $diff['highest_regression_severity']);
    }

    public function test_pointer_regression_is_high(): void
    {
        $before = $this->freshReplay();
        $before['current_pointer'] = 'slice_a';
        $after = $this->freshReplay();
        $after['current_pointer'] = 'slice_b';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));
        $after['cycle_integrity'] = [
            'intentional_reentry_detected' => false,
            'regressions' => ['pointer_jump'],
            'cycle_ok' => false,
        ];

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('high', $diff['regression_severity_map']['pointer_regression']);
        $this->assertSame('high', $diff['highest_regression_severity']);
    }

    public function test_large_violation_increase_is_high(): void
    {
        $before = $this->freshReplay();
        $before['violations'] = [];
        $after = $this->freshReplay();
        $after['violations'] = ['v1', 'v2', 'v3'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('high', $diff['regression_severity_map']['violation_increase']);
        $this->assertSame('high', $diff['highest_regression_severity']);
    }

    public function test_small_violation_increase_is_medium(): void
    {
        $before = $this->freshReplay();
        $before['violations'] = [];
        $after = $this->freshReplay();
        $after['violations'] = ['v1'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('medium', $diff['regression_severity_map']['violation_increase']);
        $this->assertSame('medium', $diff['highest_regression_severity']);
    }

    public function test_proof_bundle_drift_without_regressions_is_low_or_medium(): void
    {
        $before = $this->freshReplay();
        $before['proof_bundle_hash'] = 'before_hash';
        $after = $this->freshReplay();
        $after['proof_bundle_hash'] = 'after_hash';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame([], $diff['regressions']);
        $this->assertContains($diff['regression_severity_map']['proof_bundle_hash_drift'], ['low', 'medium']);
        $this->assertContains($diff['highest_regression_severity'], ['low', 'medium']);
    }

    public function test_no_baseline_has_none_severity(): void
    {
        $diff = $this->newDiffService()->diff();

        $this->assertSame('none', $diff['highest_regression_severity']);
        $this->assertSame([], $diff['regression_severity_map']);
    }
}
