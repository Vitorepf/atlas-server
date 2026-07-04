<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateResultRepository;
use Tests\TestCase;

final class AgentValidationGateResultRepositoryTest extends TestCase
{
    private function repo(): AgentValidationGateResultRepository
    {
        return new AgentValidationGateResultRepository();
    }

    public function test_digest_reports_failed_gate_count(): void
    {
        $repo = $this->repo();
        $repo->store([
            'result_set_id' => 'r1',
            'overall_status' => 'failed',
            'gates' => [
                ['name' => 'diff_minimality', 'status' => 'failed'],
                ['name' => 'scope_respect', 'status' => 'passed'],
            ],
        ]);

        $digest = $repo->digest();

        $this->assertSame(1, $digest['failed_gate_count']);
    }

    public function test_digest_reports_stale_evidence_count(): void
    {
        $repo = $this->repo();
        $repo->store([
            'result_set_id' => 'r1',
            'overall_status' => 'passed',
            'evidence' => [
                ['source' => 'test', 'stale' => true],
                ['source' => 'gate', 'stale' => false],
                ['source' => 'ledger', 'stale' => true],
            ],
        ]);

        $digest = $repo->digest();

        $this->assertSame(2, $digest['stale_evidence_count']);
    }

    public function test_digest_reports_proxy_evidence_count(): void
    {
        $repo = $this->repo();
        $repo->store([
            'result_set_id' => 'r1',
            'overall_status' => 'passed',
            'evidence' => [
                ['source' => 'test', 'proxy' => true],
                ['source' => 'gate', 'proxy' => false],
            ],
        ]);

        $digest = $repo->digest();

        $this->assertSame(1, $digest['proxy_evidence_count']);
    }

    public function test_digest_reports_latest_passing_result_id(): void
    {
        $repo = $this->repo();
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'failed']);
        $repo->store(['result_set_id' => 'r2', 'overall_status' => 'passed']);
        $repo->store(['result_set_id' => 'r3', 'overall_status' => 'passed']);

        $digest = $repo->digest();

        $this->assertSame('r3', $digest['latest_passing_result_id']);
    }

    public function test_digest_empty_store_has_zero_counts(): void
    {
        $repo = $this->repo();
        $digest = $repo->digest();

        $this->assertSame(0, $digest['failed_gate_count']);
        $this->assertSame(0, $digest['stale_evidence_count']);
        $this->assertSame(0, $digest['proxy_evidence_count']);
        $this->assertNull($digest['latest_passing_result_id']);
    }

    public function test_whereOverallStatus_remains_deterministic_after_store_and_forget(): void
    {
        $repo = $this->repo();
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'passed']);
        $repo->store(['result_set_id' => 'r2', 'overall_status' => 'failed']);
        $repo->forget('r1');

        $failed = $repo->failed();
        $this->assertCount(1, $failed);
        $this->assertSame('r2', $failed[0]['result_set_id']);

        $passed = $repo->passed();
        $this->assertCount(0, $passed);
    }

    public function test_result_payloads_are_provider_safe(): void
    {
        $repo = $this->repo();
        $repo->store([
            'result_set_id' => 'r1',
            'overall_status' => 'passed',
            'gates' => [['name' => 'diff_minimality', 'status' => 'passed']],
            'evidence' => [['source' => 'test', 'stale' => false, 'proxy' => false]],
        ]);

        $digest = $repo->digest();

        // No raw prompt or trace fields in digest
        $this->assertArrayNotHasKey('raw_prompt', $digest);
        $this->assertArrayNotHasKey('trace', $digest);
        $this->assertArrayNotHasKey('internal_trace', $digest);
    }
}
