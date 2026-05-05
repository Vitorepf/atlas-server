<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiPipelineCommandTest extends TestCase
{
    public function test_command_renders_pipeline_plan_as_json_without_runtime_execution(): void
    {
        $exit = Artisan::call('atlas:ai:pipeline', [
            'text' => 'planeje uma tarefa de programacao',
            '--surface' => 'atlas_cli_dev',
            '--operator' => 'tester',
            '--hint' => ['domain=programming', 'flow=programming.dev'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('planned_scaffold', $payload['status']);
        $this->assertFalse(data_get($payload, 'pipeline.provider_execution_allowed'));
        $this->assertFalse(data_get($payload, 'pipeline.runtime_execution_allowed'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'pipeline.input.surface_id'));
        $this->assertSame('programming', data_get($payload, 'pipeline.input.safe_hints.domain'));
        $this->assertIsString(data_get($payload, 'pipeline.input.hints_hash'));
        $this->assertSame('input', data_get($payload, 'pipeline.stages.0.stage'));
        $this->assertSame('operation_envelope', data_get($payload, 'pipeline.stages.1.stage'));
        $this->assertSame('decision_receipt', data_get($payload, 'pipeline.stages.4.stage'));
        $this->assertSame('output', data_get($payload, 'pipeline.stages.13.stage'));
        $this->assertTrue(data_get($payload, 'compliance.ok'));
    }

    public function test_command_can_return_scaffold_execution_stage_results(): void
    {
        $exit = Artisan::call('atlas:ai:pipeline', [
            'text' => 'execute somente scaffold',
            '--execute' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('executed_scaffold', $payload['status']);
        $this->assertSame('planned_scaffold', data_get($payload, 'pipeline.status'));
        $this->assertTrue(data_get($payload, 'pipeline.dry_run'));
        $this->assertFalse(data_get($payload, 'pipeline.provider_execution_attempted'));
        $this->assertCount(14, data_get($payload, 'pipeline.stage_results'));
        $this->assertStringStartsWith('evidence://kernel-pipeline/', data_get($payload, 'pipeline.evidence_refs.0'));
        $this->assertTrue(data_get($payload, 'pipeline.compliance_report.ok'));
    }
}
