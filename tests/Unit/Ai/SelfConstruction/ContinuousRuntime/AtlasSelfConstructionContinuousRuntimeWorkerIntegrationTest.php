<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeWorkerIntegration;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeWorkerIntegrationTest extends TestCase
{
    private function packet(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-1',
            'lease_id' => 'lease-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'required_evidence_kinds' => ['phpunit', 'mutop'],
            'quality_facts' => ['bite_proof' => true],
        ];
    }

    public function test_accepted_packet_emits_bounded_request_and_evidence_expectation(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet());

        $this->assertTrue($verdict['accepted']);
        $this->assertSame([], $verdict['blockers']);
        $req = $verdict['request'];
        $this->assertSame('pkt-1', $req['task_packet_id']);
        $this->assertSame('lease-1', $req['lease_id']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $req['allowed_files']);
        $this->assertSame(['phpunit', 'mutop'], $req['required_evidence_kinds']);
        $this->assertSame('storage/atlas/self_construction/evidence/pkt-1.jsonl', $req['write_expectation']['evidence_path']);
        $this->assertSame(['phpunit', 'mutop'], $req['write_expectation']['gate_outputs_required']);
    }

    public function test_acceptance_contract_alone_satisfies_quality_facts_signal(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet([
            'quality_facts' => ['acceptance_contract' => ['phpunit' => true]],
        ]));

        $this->assertTrue($verdict['accepted']);
    }

    public function test_empty_allowed_files_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet(['allowed_files' => []]));
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('allowed_files_empty', $verdict['blockers']);
        $this->assertNull($verdict['request']);
    }

    public function test_empty_required_evidence_kinds_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet(['required_evidence_kinds' => []]));
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('required_evidence_kinds_empty', $verdict['blockers']);
    }

    public function test_missing_quality_signal_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet(['quality_facts' => []]));
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('quality_facts_missing_self_sufficient_signal', $verdict['blockers']);
    }

    public function test_missing_ids_are_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet(['task_packet_id' => '', 'lease_id' => '']));
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('task_packet_id_missing', $verdict['blockers']);
        $this->assertContains('lease_id_missing', $verdict['blockers']);
    }

    public function test_allowed_files_boundary_is_preserved_in_request(): void
    {
        $files = ['app/A.php', 'app/B.php', 'tests/Unit/CTest.php'];
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet(['allowed_files' => $files]));

        $this->assertSame($files, $verdict['request']['allowed_files'], 'allowed_files must be preserved verbatim');
    }

    public function test_missing_native_pool_receipt_reports_facts_as_not_ready_without_breaking_compat(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet());

        $this->assertTrue($verdict['accepted'], 'old-compatible packet (no native_pool_receipt) must still be accepted');
        $this->assertFalse($verdict['native_pool_facts']['native_pool_ready']);
        $this->assertFalse($verdict['native_pool_facts']['native_pool_apply_cycle_proven']);
        $this->assertSame('native_pool_receipt_missing', $verdict['native_pool_facts']['reason']);
    }

    public function test_native_pool_apply_cycle_receipt_marks_pool_ready_and_apply_proven(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet([
            'native_pool_receipt' => [
                'schema_version' => AtlasSelfConstructionContinuousRuntimeWorkerIntegration::NATIVE_POOL_SCHEMA,
                'dry_run' => false,
                'cycle_count' => 3,
                'success_count' => 3,
                'safety_stop' => false,
                'supervisor_hash' => 'abc123',
                'receipts' => [['outcome' => 'success']],
            ],
        ]));

        $this->assertTrue($verdict['accepted']);
        $this->assertTrue($verdict['native_pool_facts']['native_pool_ready']);
        $this->assertTrue($verdict['native_pool_facts']['native_pool_dry_run_proven']);
        $this->assertTrue($verdict['native_pool_facts']['native_pool_apply_cycle_proven']);
        $this->assertSame(3, $verdict['native_pool_facts']['cycle_count']);
        $this->assertSame('abc123', $verdict['native_pool_facts']['supervisor_hash']);
    }

    public function test_external_dependency_in_execution_dependencies_is_refused(): void
    {
        foreach (['operator', 'human', 'external_provider', 'claude_code', 'codex', 'cursor', 'network', 'unrestricted_shell'] as $dep) {
            $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet([
                'execution_dependencies' => [$dep],
            ]));
            $this->assertFalse($verdict['accepted'], "dependency {$dep} must be refused");
            $this->assertContains('execution_dependency_refused:'.$dep, $verdict['blockers']);
        }
    }

    public function test_schema_mismatch_in_native_pool_receipt_is_classified_as_not_ready(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeWorkerIntegration)->integrate($this->packet([
            'native_pool_receipt' => [
                'schema_version' => 'something.else.v1',
                'cycle_count' => 5,
            ],
        ]));

        $this->assertTrue($verdict['accepted']);
        $this->assertFalse($verdict['native_pool_facts']['native_pool_ready']);
        $this->assertSame('native_pool_receipt_schema_mismatch', $verdict['native_pool_facts']['reason']);
    }
}
