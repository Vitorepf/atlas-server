<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionAutonomyModePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionAutonomyModePolicy: force_disabled override ⇒ disabled; nothing ready ⇒
 * observe; basic organs only ⇒ propose; add native_worker + rollback ⇒ execute_guarded; add
 * server_side_verification + knowledge_sync ⇒ execute_continuous; missing rollback at
 * execute_continuous yields downgrade to execute_guarded NOT continuous.
 */
final class AtlasSelfConstructionAutonomyModePolicyTest extends TestCase
{
    private function organ(bool $ready, array $blockers = []): array
    {
        return ['ready' => $ready, 'blockers' => $blockers];
    }

    public function test_force_disabled_yields_disabled(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide(['operator_overrides' => ['force_disabled' => true]]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_DISABLED, $r['mode']);
    }

    public function test_nothing_ready_yields_observe(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_OBSERVE, $r['mode']);
    }

    public function test_basic_organs_only_yields_propose(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([
            'task_fabric' => $this->organ(true),
            'maestro' => $this->organ(true),
            'verification_court' => $this->organ(true),
            'merge_governor' => $this->organ(true),
            // native_worker / rollback / server_side_verification / knowledge_sync intentionally absent
        ]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_PROPOSE, $r['mode']);
    }

    public function test_native_worker_plus_rollback_yields_execute_guarded(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([
            'task_fabric' => $this->organ(true),
            'maestro' => $this->organ(true),
            'verification_court' => $this->organ(true),
            'merge_governor' => $this->organ(true),
            'native_worker' => $this->organ(true),
            'rollback' => $this->organ(true),
            // server_side_verification / knowledge_sync missing ⇒ guarded, not continuous
        ]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_EXECUTE_GUARDED, $r['mode']);
    }

    public function test_full_readiness_yields_execute_continuous(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([
            'task_fabric' => $this->organ(true),
            'maestro' => $this->organ(true),
            'verification_court' => $this->organ(true),
            'merge_governor' => $this->organ(true),
            'native_worker' => $this->organ(true),
            'rollback' => $this->organ(true),
            'server_side_verification' => $this->organ(true),
            'knowledge_sync' => $this->organ(true),
        ]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_EXECUTE_CONTINUOUS, $r['mode']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_missing_rollback_at_otherwise_continuous_downgrades_to_propose_or_guarded(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([
            'task_fabric' => $this->organ(true),
            'maestro' => $this->organ(true),
            'verification_court' => $this->organ(true),
            'merge_governor' => $this->organ(true),
            'native_worker' => $this->organ(true),
            'rollback' => $this->organ(false, ['rollback_plan_missing']),
            'server_side_verification' => $this->organ(true),
            'knowledge_sync' => $this->organ(true),
        ]);
        // native_worker ready but rollback NOT ready ⇒ guarded is gated → falls back to PROPOSE.
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_PROPOSE, $r['mode']);
        $this->assertContains('rollback:rollback_plan_missing', $r['blockers']);
    }

    public function test_force_observe_overrides_otherwise_ready_state(): void
    {
        $r = (new AtlasSelfConstructionAutonomyModePolicy)->decide([
            'task_fabric' => $this->organ(true),
            'maestro' => $this->organ(true),
            'verification_court' => $this->organ(true),
            'merge_governor' => $this->organ(true),
            'native_worker' => $this->organ(true),
            'rollback' => $this->organ(true),
            'server_side_verification' => $this->organ(true),
            'knowledge_sync' => $this->organ(true),
            'operator_overrides' => ['force_observe' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_OBSERVE, $r['mode']);
    }
}
