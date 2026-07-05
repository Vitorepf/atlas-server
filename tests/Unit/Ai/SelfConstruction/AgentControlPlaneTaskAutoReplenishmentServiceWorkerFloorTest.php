<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use Tests\TestCase;

/**
 * Proves worker-executable seeds reach the fill target even when
 * operator-handoff seeds are present (they used to eat the slice budget).
 */
final class AgentControlPlaneTaskAutoReplenishmentServiceWorkerFloorTest extends TestCase
{
    private function service(): AgentControlPlaneTaskAutoReplenishmentService
    {
        return new AgentControlPlaneTaskAutoReplenishmentService;
    }

    public function test_worker_executable_fill_target_reached_with_handoff_seeds(): void
    {
        // claimable_before=0, target=3, needed=3.
        // Seeds include 2 operator-handoff (completion_audit with worker_executable=false)
        // and worker-executable chain_integrity + docs + tests seeds.
        $sourceMap = [
            'completion_audit_failed_criteria' => [
                'value' => ['human_only:close_operator_receipt', 'operator:sign_offline'],
                'details_by_id' => [
                    'human_only:close_operator_receipt' => ['blocker_type' => 'human_required'],
                    'operator:sign_offline' => ['blocker_type' => 'operator_required'],
                ],
            ],
            'chain_integrity_violations' => ['value' => ['violation_1']],
        ];

        $result = $this->service()->plan(0, 3, $sourceMap);

        // Count claimable worker-executable entries.
        $claimable = array_values(array_filter(
            $result,
            static fn (array $entry): bool => (bool) ($entry['status'] ?? '') === 'claimable'
                && $entry['worker_executable'] !== false,
        ));

        // Must reach target: 3 worker-executable claimable packets.
        $this->assertGreaterThanOrEqual(3, count($claimable),
            'worker-executable claimable packets must reach the fill target of 3');
    }
}
