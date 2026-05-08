<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAiApAgentWorkflowCommandTest extends TestCase
{
    public function test_command_exposes_ap_agent_workflow_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:ap-agent-workflow', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.ap_agent_workflow_registry.v1', data_get($payload, 'ap_agent_workflow.schema_version'));
        $this->assertSame('read_only_workflow_registry', data_get($payload, 'ap_agent_workflow.mode'));
        $this->assertSame(['AP-200', 'AP-201', 'AP-202', 'AP-205', 'AP-203'], array_column(data_get($payload, 'ap_agent_workflow.steps'), 'ap'));
        $this->assertContains('AP-228', array_column(data_get($payload, 'ap_agent_workflow.post_completion_review_chain'), 'ap'));
        $this->assertSame('atlas engineering knowledge docs-health', data_get($payload, 'ap_agent_workflow.required_validation_commands.docs_health'));
        $this->assertFalse(data_get($payload, 'ap_agent_workflow.guardrails.executes_commands'));
    }

    public function test_command_human_output_lists_primary_and_post_completion_steps(): void
    {
        $exit = Artisan::call('atlas:ai:ap-agent-workflow');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AP Agent Workflow', $output);
        $this->assertStringContainsString('AP-200', $output);
        $this->assertStringContainsString('AP-228', $output);
        $this->assertStringContainsString('Post-completion steps', $output);
    }
}
