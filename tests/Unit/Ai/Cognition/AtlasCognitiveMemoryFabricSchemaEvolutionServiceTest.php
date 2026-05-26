<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasCognitiveMemoryFabricSchemaEvolutionServiceTest extends TestCase
{
    private string $kernelLog;

    private string $admissionLog;

    private string $proposalsLog;

    private AtlasCognitiveMemoryFabricSchemaEvolutionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->kernelLog = sys_get_temp_dir()."/atlas_acmf_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_acmf_admission_{$u}.jsonl";
        $this->proposalsLog = sys_get_temp_dir()."/atlas_acmf_proposals_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $this->svc = new AtlasCognitiveMemoryFabricSchemaEvolutionService($kernel, $admission);
        $this->svc->setProposalsLogPathForTesting($this->proposalsLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->proposalsLog);
        parent::tearDown();
    }

    public function test_proposal_envelope_shape(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.example.subsystem.v1',
            'trigger' => 'operator_request',
            'added_fields' => ['retry_count' => 'int'],
        ]);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::PROPOSAL_SCHEMA, $p['schema_version']);
        $this->assertSame('atlas.example.subsystem.v1', $p['current_schema']);
        $this->assertSame('atlas.example.subsystem.v2', $p['proposed_next_schema']);
        $this->assertTrue($p['requires_human_approval']);
        $this->assertTrue($p['is_proposal']);
        $this->assertStringStartsWith('sha256:', $p['proposal_hash']);
    }

    public function test_unknown_trigger_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'current_schema' => 'atlas.example.v1',
            'trigger' => 'martian',
        ]);
    }

    public function test_invalid_schema_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'current_schema' => 'not_a_schema',
        ]);
    }

    public function test_v9_bumps_to_v10(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.acme.runtime.v9',
        ]);
        $this->assertSame('atlas.acme.runtime.v10', $p['proposed_next_schema']);
    }

    public function test_proposal_carries_doc_skeleton(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.example.v1',
            'added_fields' => ['field_a', 'field_b'],
            'deprecated_fields' => ['old_field'],
        ]);
        $this->assertStringContainsString('field_a', $p['doc_skeleton']);
        $this->assertStringContainsString('old_field', $p['doc_skeleton']);
        $this->assertStringContainsString('Schema Evolution Proposal', $p['doc_skeleton']);
    }

    public function test_proposals_persisted_append_only(): void
    {
        $this->svc->propose(['current_schema' => 'atlas.x.v1']);
        $this->svc->propose(['current_schema' => 'atlas.y.v1']);
        $this->assertCount(2, $this->svc->listProposals());
    }

    public function test_kernel_and_admission_carried_in_envelope(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.x.v1',
            'trigger' => 'operator_request',
        ]);
        $this->assertContains($p['kernel_decision'], ['allow', 'block', 'allow_with_human_approval']);
        $this->assertContains($p['admission_decision'], ['allow_autonomous', 'allow_with_approval', 'deny']);
    }
}
