<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityCorridorAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AgentControlPlaneGapCollector is wired via thin seams on
 * AgentControlPlaneChainIntegrityCorridorAnalyzer, mirroring how the sibling
 * AgentControlPlaneCorridorProjector (extracted from the same god-class) is wired.
 */
final class AgentControlPlaneGapCollectorWiringWiredTest extends TestCase
{
    public function test_capability_gaps_delegates_to_static_collector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $deepChain = [['slice_key' => 'a']];
        $sliceReports = [['checks' => ['capability_contract_registered' => false]]];

        $gaps = $analyzer->capabilityGaps($deepChain, $sliceReports);

        $this->assertNotEmpty($gaps);
        $this->assertSame('a', $gaps[0]['slice_key']);
        $this->assertSame('capability_contract_registered', $gaps[0]['capability_check']);
    }

    public function test_collect_invoker_gaps_delegates_to_static_collector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $sliceReports = [['slice_key' => 'a', 'checks' => ['invoker_class_exists' => false]]];

        $gaps = $analyzer->collectInvokerGaps($sliceReports);

        $this->assertCount(1, $gaps);
        $this->assertSame('create_invoker_class', $gaps[0]['repair_hint']);
    }

    public function test_collect_readiness_gaps_delegates_to_static_collector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $sliceReports = [['slice_key' => 'a', 'checks' => ['contract_method_exists' => false]]];

        $gaps = $analyzer->collectReadinessGaps($sliceReports);

        $this->assertNotEmpty($gaps);
        $this->assertSame('contract_method_exists', $gaps[0]['readiness_check']);
    }

    public function test_runtime_safety_gaps_delegates_to_static_collector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $gaps = $analyzer->runtimeSafetyGaps(['dispatch_allowed_anywhere' => true]);

        $this->assertNotEmpty($gaps);
        $this->assertSame('dispatch_allowed_anywhere', $gaps[0]['flag']);
    }

    public function test_runtime_safety_gaps_clean_when_all_false(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $gaps = $analyzer->runtimeSafetyGaps(['runtime_safety_all_false' => true]);

        $this->assertSame([], $gaps);
    }
}
