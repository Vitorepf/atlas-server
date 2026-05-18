<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\EvidencePackService;
use App\Services\Ai\Evidence\GateRunService;
use App\Services\Ai\Evidence\TestResultService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeGateRunTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_gate_run_records_checked_and_missing_requirements(): void
    {
        /** @var GateRunService $svc */
        $svc = app(GateRunService::class);
        $gateRun = $svc->record([
            'gate_type' => 'release.readiness',
            'target_type' => EvidencePackService::TARGET_DOMAIN_DELIVERY,
            'target_id' => 'delivery-001',
            'status' => GateRunService::STATUS_FAILED,
            'checked_requirements' => [
                ['requirement' => 'tests_green', 'status' => 'passed'],
                ['requirement' => 'security_review', 'status' => 'failed'],
            ],
            'missing_requirements' => [
                ['requirement' => 'security_review', 'detail' => 'pending sign-off'],
            ],
        ]);

        $this->assertSame(GateRunService::STATUS_FAILED, $gateRun->status);
        $this->assertCount(2, $gateRun->checked_requirements);
        $this->assertCount(1, $gateRun->missing_requirements);
        $this->assertNotEmpty($gateRun->gate_hash);
    }

    public function test_test_result_records_status_and_output_hash(): void
    {
        /** @var TestResultService $svc */
        $svc = app(TestResultService::class);
        $result = $svc->record([
            'test_scope' => 'phpunit:EvidenceRuntime',
            'command' => 'php artisan test --filter=Evidence',
            'status' => TestResultService::STATUS_PASSED,
            'output_ref' => 'tests/output/evidence-runtime.log',
        ]);

        $this->assertSame(TestResultService::STATUS_PASSED, $result->status);
        $this->assertNotEmpty($result->output_hash);
    }
}
