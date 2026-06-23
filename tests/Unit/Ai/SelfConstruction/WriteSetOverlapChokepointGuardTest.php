<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use Tests\TestCase;

/**
 * PART 2 · A5/MF-12 — the anti-regression GUARD. Every write-set / scope OVERLAP chokepoint in the Agent
 * Control Plane must route conflict detection through the SINGLE {@see \App\Services\Ai\SelfConstruction\WriteSetOverlap}
 * predicate. Without this guard a future edit could reintroduce a raw `array_intersect` in one corner and
 * silently lose the dir-vs-file / read-vs-write fix there while the others stay correct.
 */
final class WriteSetOverlapChokepointGuardTest extends TestCase
{
    /** The enumerated overlap/forbidden chokepoints (relative to app/Services/Ai/SelfConstruction/). */
    private const CHOKEPOINTS = [
        'AgentControlPlaneClaimLeaseRepository.php',
        'AgentControlPlaneScopeLockPlanner.php',
        'AgentControlPlaneScopeLockRuntimeValidator.php',
        'AgentControlPlaneTaskPacketBuilder.php',
        'AgentControlPlaneMultiAgentLoopCertificationService.php',
        'AgentDispatchPlannerScopeConflictAnalyzer.php',
        'AgentControlPlaneMultiAgentParallelismPlanner.php',
        'AgentControlPlaneClaimLeaseSimulator.php',
        'AtlasSelfConstructionReservationRepository.php',
        'AtlasSelfConstructionReadinessService.php',
    ];

    public function test_every_overlap_chokepoint_routes_through_the_single_predicate(): void
    {
        $base = base_path('app/Services/Ai/SelfConstruction/');
        foreach (self::CHOKEPOINTS as $file) {
            $src = (string) @file_get_contents($base.$file);
            $this->assertNotSame('', $src, "$file must exist");
            $this->assertStringContainsString(
                'WriteSetOverlap::',
                $src,
                "$file must route write-set/scope overlap through the single WriteSetOverlap predicate (A5/MF-12)",
            );
        }
    }
}
