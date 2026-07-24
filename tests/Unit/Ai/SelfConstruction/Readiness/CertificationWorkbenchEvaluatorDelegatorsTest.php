<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\HubDelegators\CertificationWorkbenchEvaluatorDelegators;
use BadMethodCallException;
use PHPUnit\Framework\TestCase;

final class CertificationWorkbenchEvaluatorDelegatorsTest extends TestCase
{
    public function test_map_lists_all_workbench_methods(): void
    {
        $host = new class
        {
            use CertificationWorkbenchEvaluatorDelegators;
        };

        $names = $host::certificationWorkbenchMethodNames();

        $this->assertCount(219, $names);
        $this->assertContains('agentControlPlaneCertificationBaselineContract', $names);
        $this->assertContains('agentControlPlaneCertificationBaselinePreflight', $names);
        $this->assertContains('agentControlPlaneCertificationBaselineImplementationPacket', $names);
    }

    public function test_call_dispatches_to_certify_payload(): void
    {
        $host = new class
        {
            use CertificationWorkbenchEvaluatorDelegators;
        };

        $payload = $host->agentControlPlaneCertificationBaselineContract([]);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('schema_version', $payload);
        $this->assertArrayHasKey('status', $payload);
    }

    public function test_unknown_method_throws(): void
    {
        $host = new class
        {
            use CertificationWorkbenchEvaluatorDelegators;
        };

        $this->expectException(BadMethodCallException::class);
        $host->definitelyNotAWorkbenchMethod([]);
    }
}
