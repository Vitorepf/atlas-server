<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves worker-executable seeds reach the fill target even when
 * operator-handoff seeds are present (they used to eat the slice budget).
 */
final class AgentControlPlaneTaskAutoReplenishmentServiceWorkerFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Call the private plan() method via reflection.
     *
     * @param  array<string,mixed>  $sources
     * @return list<array<string,mixed>>
     */
    private function callPlan(array $sources, int $targetMinClaimable, int $maxNewTasks, int $claimableBefore, int $totalBefore): array
    {
        $service = app(AgentControlPlaneTaskAutoReplenishmentService::class);
        $ref = new \ReflectionMethod($service, 'plan');
        $ref->setAccessible(true);

        return $ref->invoke($service, $sources, $targetMinClaimable, $maxNewTasks, $claimableBefore, $totalBefore);
    }

    public function test_worker_executable_fill_target_reached_with_handoff_seeds(): void
    {
        // claimable_before=0, target=3, needed=3 (maxNewTasks).
        // Seeds: 2 operator-handoff (completion_audit criteria) + 3 worker-executable
        // (chain_integrity, docs, tests). Before the fix, the handoff seeds were
        // inserted first and ate the slice budget, minting fewer than target
        // worker-executable packets. After the fix, worker-executable is partitioned
        // before the slice, so all target slots go to real governed seeds.
        $sources = [
            ['source' => 'completion_audit_failed_criteria', 'value' => ['human_only:close_operator_receipt', 'operator:sign_offline'],
                'details_by_id' => [
                    'human_only:close_operator_receipt' => ['blocker_type' => 'human_required'],
                    'operator:sign_offline' => ['blocker_type' => 'operator_required'],
                ],
            ],
            ['source' => 'chain_integrity_violations', 'value' => ['violation_1']],
            ['source' => 'not_yet_runtime_capable', 'value' => []],
        ];

        $plan = $this->callPlan($sources, 3, 3, 0, 0);

        // Count worker-executable seeds (default true when key absent).
        $executable = array_values(array_filter(
            $plan,
            static fn (array $s): bool => (bool) ($s['worker_executable'] ?? true),
        ));

        $this->assertGreaterThanOrEqual(3, count($executable),
            'worker-executable seeds must reach the fill target of 3');
    }
}
