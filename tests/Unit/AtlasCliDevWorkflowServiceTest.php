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
            'atlas.cli.php_binary' => '/opt/homebrew/bin/php',
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
            model: null,
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
        );

        $this->assertSame('/opt/homebrew/bin/php', $command[0]);
        $this->assertContains('atlas:ai:chat', $command);
        $this->assertContains(base_path('artisan'), $command);
        $this->assertContains('corrigir bug', $command);
        $this->assertContains('--dev', $command);
        $this->assertContains('--new-thread', $command);
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
            model: null,
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

    public function test_chat_command_forwards_model_override(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: 'gpt-5.3-codex-spark',
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
        );

        $this->assertContains('--model=gpt-5.3-codex-spark', $command);
    }

    public function test_chat_command_forwards_open_brain_policy_options(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: null,
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: false,
            noRun: true,
            openBrain: [
                'mode' => 'required',
                'refresh' => true,
                'budget_chars' => 6000,
            ],
        );

        $this->assertContains('--require-open-brain', $command);
        $this->assertContains('--open-brain-refresh', $command);
        $this->assertContains('--open-brain-budget=6000', $command);
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

    public function test_fair_claude_prompt_contract_wraps_task_with_benchmark_constraints(): void
    {
        $prompt = app(AtlasCliDevWorkflowService::class)->fairClaudePromptContract('implementar feature');

        $this->assertStringContainsString('# Atlas Fair Claude Mode', $prompt);
        $this->assertStringContainsString('Provider is locked to claude_cli.', $prompt);
        $this->assertStringContainsString('Model is locked to the configured Claude Opus premium model.', $prompt);
        $this->assertStringContainsString('Do not suggest switching provider', $prompt);
        $this->assertStringContainsString('implementar feature', $prompt);
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

    public function test_fair_quality_gate_policy_requires_verified_pass_even_without_complete_mode(): void
    {
        $policy = app(AtlasCliDevWorkflowService::class)->qualityGatePolicy(
            complete: false,
            maxIterations: 1,
            skills: [],
            fairMode: true,
        );

        $this->assertTrue($policy['fair_mode']);
        $this->assertSame('passed', $policy['required_final_status']);
        $this->assertTrue($policy['deterministic_gate_required']);
        $this->assertFalse($policy['unverified_counts_as_passed']);
    }

    public function test_fair_protocol_status_does_not_count_needs_review_as_passed(): void
    {
        $result = app(AtlasCliDevWorkflowService::class)->fairClaudeProtocolStatus([
            'status' => 'needs_review',
            'quality_gates' => [
                ['name' => 'git_status', 'status' => 'passed'],
                ['name' => 'tests', 'status' => 'needs_review'],
            ],
        ], providerOk: true);

        $this->assertSame('unverified', $result['status']);
        $this->assertFalse($result['deterministic_gates_passed']);
        $this->assertFalse($result['pass_without_human']);
        $this->assertContains('quality_status_needs_review', $result['blocking_reasons']);
    }

    public function test_fair_protocol_status_allows_pass_without_human_only_for_verified_gates(): void
    {
        $result = app(AtlasCliDevWorkflowService::class)->fairClaudeProtocolStatus([
            'status' => 'passed',
            'quality_gates' => [
                ['name' => 'git_status', 'status' => 'passed'],
                ['name' => 'git_diff', 'status' => 'passed'],
                ['name' => 'tests', 'status' => 'passed'],
            ],
        ], providerOk: true, humanInterventionCount: 0);

        $this->assertSame('valid', $result['status']);
        $this->assertTrue($result['deterministic_gates_passed']);
        $this->assertTrue($result['pass_without_human']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_fair_claude_repair_capsule_keeps_same_provider_and_model_contract(): void
    {
        $capsule = app(AtlasCliDevWorkflowService::class)->fairClaudeRepairCapsule(
            task: 'implementar feature',
            completion: [
                'status' => 'failed',
                'changed_files' => ['app/Foo.php'],
                'completion_packet' => [
                    'tests' => [['command' => 'php artisan test', 'ok' => false]],
                    'risks' => ['Teste falhou.'],
                ],
            ],
            iteration: 2,
            maxIterations: 3,
            devPlan: [
                'plan_id' => 'plan_1',
                'selected_provider' => 'claude_cli',
                'selected_model' => ['model' => 'claude-opus-test'],
                'fair_mode' => ['fair_mode' => true],
            ],
        );

        $this->assertStringContainsString('# Atlas Fair Claude Repair Capsule', $capsule);
        $this->assertStringContainsString('claude_cli + Claude Opus', $capsule);
        $this->assertStringContainsString('Do not switch provider', $capsule);
        $this->assertStringContainsString('unverified, needs_review, missing gates, or self-assessment never count as passed', $capsule);
        $this->assertStringContainsString('implementar feature', $capsule);
        $this->assertStringContainsString('app/Foo.php', $capsule);
    }
}
