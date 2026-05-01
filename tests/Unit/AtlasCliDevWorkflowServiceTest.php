<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDevWorkflowServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dev-workflow-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php -r "exit(0);"',
            ],
        ], JSON_PRETTY_PRINT));

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.default_provider' => 'claude_cli',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_preflight_builds_native_dev_workflow_packet(): void
    {
        $payload = app(AtlasCliDevWorkflowService::class)->preflight(
            workspace: $this->workspace,
            task: 'implementar workflow',
            provider: 'codex_cli',
        );

        $this->assertSame($this->workspace, $payload['workspace']);
        $this->assertSame('implementar workflow', $payload['task']);
        $this->assertSame('codex_cli', $payload['selected_provider']);
        $this->assertArrayHasKey('preflight_quality', $payload);
        $this->assertFalse($payload['requires_override']);
    }

    public function test_chat_command_wraps_ai_chat_in_dev_mode(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
        );

        $this->assertContains('atlas:ai:chat', $command);
        $this->assertContains(base_path('artisan'), $command);
        $this->assertContains('corrigir bug', $command);
        $this->assertContains('--dev', $command);
        $this->assertContains('--provider=codex_cli', $command);
        $this->assertContains('--no-quality-gate', $command);
        $this->assertContains('--no-run', $command);
    }

    public function test_chat_command_forwards_image_options(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir UI',
            workspace: $this->workspace,
            provider: 'codex_cli',
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
            imagePaths: ['/tmp/tela.png'],
            clipboardImage: true,
            noAutoImage: true,
        );

        $this->assertContains('--image=/tmp/tela.png', $command);
        $this->assertContains('--clipboard-image', $command);
        $this->assertContains('--no-auto-image', $command);
    }

    public function test_quality_gate_skills_auto_attach_for_complete_or_multi_iteration(): void
    {
        $workflow = app(AtlasCliDevWorkflowService::class);

        $completeSkills = $workflow->qualityGateSkills(['code-reviewer'], complete: true, maxIterations: 1);
        $multiIterationSkills = $workflow->qualityGateSkills([], complete: false, maxIterations: 3);
        $singleShotSkills = $workflow->qualityGateSkills([], complete: false, maxIterations: 1);

        $this->assertSame(['code-reviewer', 'dev-quality-gate'], $completeSkills);
        $this->assertSame(['dev-quality-gate'], $multiIterationSkills);
        $this->assertSame([], $singleShotSkills);
    }

    public function test_engineering_contract_skills_attach_blueprint_once(): void
    {
        $skills = app(AtlasCliDevWorkflowService::class)->engineeringContractSkills([
            'code-reviewer',
            'engineering-blueprint',
        ]);

        $this->assertSame(['code-reviewer', 'engineering-blueprint'], $skills);
    }

    public function test_prompt_with_engineering_contract_wraps_task_scope_and_acceptance(): void
    {
        $prompt = app(AtlasCliDevWorkflowService::class)->promptWithEngineeringContract('implementar contrato', [
            'type' => 'feature',
            'goal' => 'Gerar contrato tecnico normalizado.',
            'acceptance_criteria' => ['Plan-only expoe engineering_contract.'],
            'test_coverage' => ['Rodar teste do comando.'],
            'refs' => ['task_id' => '33333333-3333-4333-8333-333333333333'],
        ]);

        $this->assertStringContainsString('# Atlas Engineering Task Contract', $prompt);
        $this->assertStringContainsString('Objetivo: Gerar contrato tecnico normalizado.', $prompt);
        $this->assertStringContainsString('- Plan-only expoe engineering_contract.', $prompt);
        $this->assertStringContainsString('# Pedido do operador', $prompt);
        $this->assertStringContainsString('implementar contrato', $prompt);
    }

    public function test_quality_gate_policy_requires_passed_status_in_complete_mode(): void
    {
        $policy = app(AtlasCliDevWorkflowService::class)->qualityGatePolicy(
            complete: true,
            maxIterations: 3,
            skills: ['dev-quality-gate'],
        );

        $this->assertTrue($policy['complete_mode']);
        $this->assertSame('passed', $policy['required_final_status']);
        $this->assertSame('plan_validate_execute', $policy['procedure']);
    }
}
