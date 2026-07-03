<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasAreaFocusLoopReadModelService::seed keeps two findings distinct
 * when a summary contains a pipe character.
 */
final class AtlasAreaFocusLoopReadModelServiceHardeningTest extends TestCase
{
    private function createService(): AtlasAreaFocusLoopReadModelService
    {
        // Registry class doesn't exist yet — use a runtime-created anonymous class
        $registryClass = 'App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry';
        if (!class_exists($registryClass)) {
            eval("namespace App\\Services\\Ai\\NightShift; class AtlasNightShiftAreaFocusContractRegistry {}");
        }
        $registry = $this->createMock($registryClass);
        return new AtlasAreaFocusLoopReadModelService($registry);
    }

    private function seed(
        AtlasAreaFocusLoopReadModelService $service,
        string $areaId,
        string $kind,
        string $severity,
        string $summary,
        string $recommendedOwner,
        array $evidenceRefs
    ): array {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('seed');
        return $method->invoke($service, $areaId, $kind, $severity, $summary, $recommendedOwner, $evidenceRefs);
    }

    public function test_two_findings_with_pipe_in_summary_are_distinct(): void
    {
        $service = $this->createService();

        $result1 = $this->seed($service, 'area-1', 'bug', 'high', 'fix|review needed', 'owner-a', []);
        $result2 = $this->seed($service, 'area-1', 'bug', 'high', 'fix', 'owner-a', []);

        $this->assertNotSame($result1['seed_id'], $result2['seed_id']);
    }

    public function test_pipe_in_summary_does_not_collide_with_different_fields(): void
    {
        $service = $this->createService();

        $result1 = $this->seed($service, 'area-1', 'kind-a', 'high', 'summary-with|pipe', 'owner-a', []);
        $result2 = $this->seed($service, 'area-1', 'kind-a', 'high', 'summary-without-pipe', 'owner-a', []);

        $this->assertNotSame($result1['seed_id'], $result2['seed_id']);
    }

    public function test_same_finding_is_idempotent(): void
    {
        $service = $this->createService();

        $result1 = $this->seed($service, 'area-1', 'kind-a', 'high', 'same summary', 'owner-a', []);
        $result2 = $this->seed($service, 'area-1', 'kind-a', 'high', 'same summary', 'owner-a', []);

        $this->assertSame($result1['seed_id'], $result2['seed_id']);
    }
}
