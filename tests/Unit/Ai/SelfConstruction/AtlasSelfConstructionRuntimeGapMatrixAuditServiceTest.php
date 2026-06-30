<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixAuditService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeGapMatrixAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function audit(): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixAuditService(
            $this->app->make(AtlasSelfConstructionReadinessService::class),
        ))->audit();
    }

    public function test_audit_includes_worker_packet_candidates_and_handoff_gaps_keys(): void
    {
        $result = $this->audit();

        $this->assertArrayHasKey('worker_packet_candidates', $result);
        $this->assertArrayHasKey('operator_or_provider_handoff_gaps', $result);
    }

    public function test_worker_packet_candidates_only_contains_worker_safe_closure_classes(): void
    {
        $result = $this->audit();

        $allowedClasses = [
            AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_AUTO,
            AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_SAFE_DRY_RUN,
        ];

        foreach ($result['worker_packet_candidates'] as $gap) {
            $this->assertContains(
                $gap['closure_class'],
                $allowedClasses,
                'worker_packet_candidates must never contain operator/provider-only closure classes',
            );
        }
    }

    public function test_operator_or_provider_handoff_gaps_only_contains_operator_or_provider_classes(): void
    {
        $result = $this->audit();

        $handoffClasses = [
            AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_OPERATOR_RECEIPT,
            AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_REAL_PROVIDER_SMOKE,
        ];

        foreach ($result['operator_or_provider_handoff_gaps'] as $gap) {
            $this->assertContains($gap['closure_class'], $handoffClasses);
        }
    }

    public function test_worker_packet_candidates_and_handoff_gaps_partition_all_gaps_without_overlap(): void
    {
        $result = $this->audit();

        $workerIds = array_column($result['worker_packet_candidates'], 'gap_id');
        $handoffIds = array_column($result['operator_or_provider_handoff_gaps'], 'gap_id');

        $this->assertSame([], array_intersect($workerIds, $handoffIds), 'a gap must never appear in both lists');

        // Every gap with a closure_class in the 4 known classes must land in exactly one of the two lists.
        foreach ((array) $result['gaps'] as $gap) {
            $isWorkerSafe = in_array($gap['closure_class'], [
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_AUTO,
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_SAFE_DRY_RUN,
            ], true);
            $isHandoff = in_array($gap['closure_class'], [
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_OPERATOR_RECEIPT,
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_REAL_PROVIDER_SMOKE,
            ], true);

            if ($isWorkerSafe) {
                $this->assertContains($gap['gap_id'], $workerIds);
            }
            if ($isHandoff) {
                $this->assertContains($gap['gap_id'], $handoffIds);
            }
        }
    }

    public function test_audit_remains_read_only(): void
    {
        $result = $this->audit();

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }
}
