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
}
