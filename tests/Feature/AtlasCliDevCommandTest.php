<?php

namespace Tests\Feature;

use App\Console\Commands\AtlasCliDevCommand;
use App\Models\AtlasTask;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliDevCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dev-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode(['scripts' => ['test' => 'php -r "exit(0);"']], JSON_PRETTY_PRINT));
        (new Process(['git', 'init'], $this->workspace))->run();

        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        Schema::dropIfExists('atlas_tasks');

        parent::tearDown();
    }

    public function test_plan_only_complete_auto_activates_dev_quality_gate(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--complete' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame('/opt/homebrew/bin/php', $payload['chat_command'][0]);
        $this->assertArrayHasKey('open_brain_preview', $payload);
        $this->assertSame('cli_dev', data_get($payload, 'open_brain_preview.surface'));
        $this->assertSame('required', data_get($payload, 'open_brain_preview.policy.mode'));
        $this->assertNotSame('preview_failed', data_get($payload, 'open_brain_preview.status'));
        $this->assertIsBool(data_get($payload, 'open_brain_preview.provider_execution_allowed'));
        $this->assertArrayNotHasKey('prompt_section', $payload['open_brain_preview']);
        $this->assertArrayNotHasKey('context_refs', $payload['open_brain_preview']);
        $this->assertContains('dev-quality-gate', $payload['activated_skills']);
        $this->assertContains('--skill=dev-quality-gate', $payload['chat_command']);
        $this->assertSame('dev_repair_executor', data_get($payload, 'dev_execution_plan.programming_session_plan.executor_decision.executor'));
        $this->assertSame('dev_repair_executor', data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.execution_policy.executor_preference'));
        $this->assertSame(3, data_get($payload, 'dev_execution_plan.programming_session_plan.execution_profile.max_iterations'));
        $this->assertSame('passed', data_get($payload, 'dev_execution_plan.quality_gate_policy.required_final_status'));
        $this->assertSame('plan_validate_execute', data_get($payload, 'dev_execution_plan.quality_gate_policy.procedure'));
    }

    public function test_plan_only_forge_uses_programming_orchestrator_max_profile(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'fluxo', 'dificil'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--forge' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('forge', data_get($payload, 'workflow.programming_profile'));
        $this->assertSame('programming.forge', data_get($payload, 'workflow.policy_profile_id'));
        $this->assertSame('forge', data_get($payload, 'dev_execution_plan.programming_profile'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($payload, 'dev_execution_plan.orchestrator'));
        $this->assertSame('forge', data_get($payload, 'dev_execution_plan.programming_session_plan.programming_profile'));
        $this->assertSame('engineering_harness', data_get($payload, 'dev_execution_plan.programming_session_plan.executor_decision.executor'));
        $this->assertSame('engineering_harness', data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.execution_policy.executor_preference'));
        $this->assertSame('codex_cli', data_get($payload, 'dev_execution_plan.programming_session_plan.operational_decision.provider_selection.selected_provider'));
        $this->assertSame('evidence_required', data_get($payload, 'dev_execution_plan.programming_session_plan.execution_profile.done_policy'));
        $this->assertSame(5, data_get($payload, 'dev_execution_plan.quality_gate_policy.max_iterations'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.auto_test'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.complete'));
        $this->assertSame('required', data_get($payload, 'dev_execution_plan.operator_options.open_brain.mode'));
        $this->assertContains('--auto-test', $payload['chat_command']);
        $this->assertContains('--require-open-brain', $payload['chat_command']);
    }

    public function test_plan_only_projects_session_ai_policy_override_from_ai_and_model_flags(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'override'],
            '--workspace' => $this->workspace,
            '--ai' => 'codex',
            '--model' => '5.5',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('codex_cli', data_get($payload, 'dev_execution_plan.ai_policy_override.default_provider'));
        $this->assertSame('gpt-5.5', data_get($payload, 'dev_execution_plan.ai_policy_override.providers.codex_cli.model'));
        $this->assertSame(['gpt-5.5'], data_get($payload, 'dev_execution_plan.ai_policy_override.allowed_models.codex_cli'));
        $this->assertSame('codex_cli', data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.default_provider'));
        $this->assertSame(['gpt-5.5'], data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.allowed_models.codex_cli'));
        $this->assertContains('--provider=codex_cli', $payload['chat_command']);
        $this->assertContains('--model=gpt-5.5', $payload['chat_command']);
    }

    public function test_operator_mode_promotes_dev_command_to_explicit_danger_runtime(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['instalar', 'dependencias'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--operator' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('--permission=danger', $payload['chat_command']);
        $this->assertContains('--dangerously-allow-all', $payload['chat_command']);
        $this->assertContains('--allow-unsandboxed', $payload['chat_command']);
    }

    public function test_plan_only_model_override_infers_provider_and_forwards_model(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--model' => 'claude-opus-4-1',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'workflow.selected_provider'));
        $this->assertSame('claude-opus-4-1', data_get($payload, 'workflow.selected_model.model'));
        $this->assertSame('claude-opus-4-1', data_get($payload, 'dev_execution_plan.operator_options.model'));
        $this->assertContains('--model=claude-opus-4-1', $payload['chat_command']);
    }

    public function test_plan_only_claude_only_defaults_to_configured_opus_and_exposes_fair_metadata(): void
    {
        config([
            'atlas.ai.providers.claude_cli.premium_model' => 'claude-opus-test',
            'atlas.ai.providers.claude_cli.premium_model_label' => 'Claude Opus Test',
            'atlas.ai.providers.claude_cli.allow_auto' => false,
            'atlas.ai.providers.claude_cli.allow_manual' => true,
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'workflow.selected_provider'));
        $this->assertSame('claude-opus-test', data_get($payload, 'workflow.selected_model.model'));
        $this->assertSame('opus', data_get($payload, 'workflow.selected_model.alias'));
        $this->assertTrue(data_get($payload, 'workflow.fair_mode.fair_mode'));
        $this->assertTrue(data_get($payload, 'workflow.fair_mode.single_provider'));
        $this->assertTrue(data_get($payload, 'workflow.fair_mode.fallback_disabled'));
        $this->assertSame(['claude_cli'], data_get($payload, 'workflow.fair_mode.allowed_providers'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.single_provider'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.no_decide'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.fallback_disabled'));
        $this->assertSame(['claude_cli'], data_get($payload, 'dev_execution_plan.ai_policy_override.enabled_providers'));
        $this->assertSame(['codex_cli', 'gemini_cli'], data_get($payload, 'dev_execution_plan.ai_policy_override.disabled_providers'));
        $this->assertSame(['claude_cli'], data_get($payload, 'dev_execution_plan.ai_policy_override.fallback_order'));
        $this->assertSame(['claude-opus-test'], data_get($payload, 'dev_execution_plan.ai_policy_override.allowed_models.claude_cli'));
        $this->assertSame(['claude_cli'], data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.enabled_providers'));
        $this->assertSame(['claude_cli'], data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.fallback_order'));
        $this->assertSame(['claude-opus-test'], data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.allowed_models.claude_cli'));
        $this->assertNull(data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.allowed_models.codex_cli'));
        $this->assertFalse(data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.providers.codex_cli.allow_manual'));
        $this->assertFalse(data_get($payload, 'dev_execution_plan.programming_session_plan.policy_profile.effective_policy.runtime_policy.providers.gemini_cli.allow_auto'));
        $this->assertSame('passed', data_get($payload, 'dev_execution_plan.quality_gate_policy.required_final_status'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.quality_gate_policy.deterministic_gate_required'));
        $this->assertFalse(data_get($payload, 'dev_execution_plan.quality_gate_policy.unverified_counts_as_passed'));
        $this->assertContains('--provider=claude_cli', $payload['chat_command']);
        $this->assertContains('--model=claude-opus-test', $payload['chat_command']);
        $this->assertStringContainsString('Atlas Fair Claude Mode', implode("\n", $payload['chat_command']));
        $this->assertStringContainsString('Provider is locked to claude_cli.', implode("\n", $payload['chat_command']));
        $this->assertStringContainsString('Forbidden providers: codex_cli, gemini_cli.', implode("\n", $payload['chat_command']));
        $this->assertStringContainsString('## Machine-Readable Contract', implode("\n", $payload['chat_command']));
        $this->assertSame('fair_claude_prompt_contract', data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.kind'));
        $this->assertSame('claude_cli', data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.provider_lock'));
        $this->assertSame('opus', data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.model_lock'));
        $this->assertSame(['codex_cli', 'gemini_cli'], data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.forbidden_providers'));
        $this->assertSame(
            ['fallback', 'council', 'atlas_decide', 'external_reviewer'],
            data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.forbidden_capabilities'),
        );
        $this->assertTrue((bool) data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.deterministic_gates.required'));
    }

    public function test_plan_only_explicit_fair_flags_accept_claude_opus(): void
    {
        config([
            'atlas.ai.providers.claude_cli.premium_model' => 'claude-opus-test',
            'atlas.ai.providers.claude_cli.premium_model_label' => 'Claude Opus Test',
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--provider' => 'claude_cli',
            '--model' => 'opus',
            '--single-provider' => true,
            '--no-decide' => true,
            '--fallback-disabled' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'workflow.selected_provider'));
        $this->assertSame('claude-opus-test', data_get($payload, 'workflow.selected_model.model'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.fair_mode.fair_mode'));
    }

    public function test_claude_only_rejects_codex_provider(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertSame('codex_cli', data_get($payload, 'details.provider'));
    }

    public function test_claude_only_rejects_gemini_provider(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--provider' => 'gemini_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertSame('gemini_cli', data_get($payload, 'details.provider'));
    }

    public function test_claude_only_rejects_codex_model_alias(): void
    {
        config([
            'atlas.ai.providers.codex_cli.premium_model' => 'gpt-5.5',
            'atlas.ai.providers.codex_cli.premium_model_label' => 'GPT-5.5',
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--model' => '5.5',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertSame('codex_cli', data_get($payload, 'details.model_provider'));
    }

    public function test_claude_only_respects_manual_provider_block(): void
    {
        config([
            'atlas.ai.providers.claude_cli.allow_manual' => false,
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('atlas_manual_provider_blocked', data_get($payload, 'error'));
        $this->assertSame('claude_cli', data_get($payload, 'provider'));
    }

    public function test_plan_only_automatic_provider_respects_codex_auto_block_even_when_codex_is_default(): void
    {
        config([
            'atlas.ai.default_provider' => 'codex_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
            'atlas.ai.providers.codex_cli.allow_manual' => true,
            'atlas.ai.providers.claude_cli.allow_auto' => true,
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'parser'],
            '--workspace' => $this->workspace,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'workflow.selected_provider'));
        $this->assertSame('claude_cli', data_get($payload, 'workflow.provider_strategy.recommended_provider'));
        $this->assertSame('codex_cli', data_get($payload, 'workflow.provider_strategy.policy.default_provider'));
    }

    public function test_plan_only_manual_codex_model_override_is_allowed_when_codex_auto_is_blocked(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
            'atlas.ai.providers.codex_cli.allow_manual' => true,
            'atlas.ai.providers.codex_cli.premium_model' => 'gpt-5.5',
            'atlas.ai.providers.codex_cli.premium_model_label' => 'GPT-5.5',
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['refatorar', 'servico'],
            '--workspace' => $this->workspace,
            '--model' => '5.5',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('codex_cli', data_get($payload, 'workflow.selected_provider'));
        $this->assertSame('gpt-5.5', data_get($payload, 'workflow.selected_model.model'));
        $this->assertSame('gpt-5.5', data_get($payload, 'dev_execution_plan.operator_options.model'));
        $this->assertContains('--model=gpt-5.5', $payload['chat_command']);
    }

    public function test_plan_only_manual_provider_fails_when_app_blocks_manual_use(): void
    {
        config([
            'atlas.ai.providers.gemini_cli.allow_manual' => false,
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['analisar', 'codigo'],
            '--workspace' => $this->workspace,
            '--provider' => 'gemini_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(false, data_get($payload, 'ok'));
        $this->assertSame('atlas_manual_provider_blocked', data_get($payload, 'error'));
        $this->assertSame('gemini_cli', data_get($payload, 'provider'));
    }

    public function test_global_operator_defaults_promote_dev_command_without_operator_flag(): void
    {
        config([
            'atlas.ai.tool_permissions.default_mode' => 'danger',
            'atlas.ai.tool_permissions.allow_danger' => true,
            'atlas.ai.tool_permissions.allow_unsandboxed_write' => true,
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['instalar', 'dependencias'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('--permission=danger', $payload['chat_command']);
        $this->assertContains('--dangerously-allow-all', $payload['chat_command']);
        $this->assertContains('--allow-unsandboxed', $payload['chat_command']);
    }

    public function test_interactive_dev_opens_cockpit_without_local_skill_prompt(): void
    {
        $command = $this->interactiveCommand([
            '--permission' => 'danger',
            '--provider' => 'codex_cli',
        ]);

        $this->assertSame('/opt/homebrew/bin/php', $command[0]);
        $this->assertContains('atlas:ai:chat', $command);
        $this->assertContains('--dev', $command);
        $this->assertContains('--new-thread', $command);
        $this->assertContains('--cockpit', $command);
        $this->assertContains('--no-skill-prompt', $command);
        $this->assertContains('--provider=codex_cli', $command);
        $this->assertContains('--permission=danger', $command);
        $this->assertContains('--dangerously-allow-all', $command);
    }

    public function test_interactive_dev_forwards_model_override(): void
    {
        $command = $this->interactiveCommand([
            '--permission' => 'write',
            '--provider' => 'codex_cli',
            '--model' => 'gpt-5.4-mini',
        ]);

        $this->assertContains('--model=gpt-5.4-mini', $command);
    }

    public function test_workspace_auto_detects_git_project_root(): void
    {
        $nested = $this->workspace.'/app/Console';
        File::ensureDirectoryExists($nested);

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['checar', 'workspace'],
            '--workspace' => $nested,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->workspace, data_get($payload, 'workflow.workspace'));
    }

    public function test_plan_only_fair_mode_propagates_engineering_contract_to_machine_readable_block(): void
    {
        config([
            'atlas.ai.providers.claude_cli.premium_model' => 'claude-opus-test',
            'atlas.ai.providers.claude_cli.premium_model_label' => 'Claude Opus Test',
            'atlas.ai.providers.claude_cli.allow_auto' => false,
            'atlas.ai.providers.claude_cli.allow_manual' => true,
        ]);

        $this->createMinimalTaskTable();

        $task = AtlasTask::query()->create([
            'title' => 'Fair Claude prompt contract acceptance matrix',
            'description' => 'Expor acceptance criteria, file scope e gates determinísticos como contrato machine-readable.',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Fair Claude prompt contract acceptance matrix.',
                    'acceptance_criteria' => [
                        'Prompt contract has explicit acceptance criteria and forbidden fallback/providers.',
                        'Contract fields are persisted in replay artifacts.',
                        'Plan-only output exposes the fair metadata without invoking another provider.',
                    ],
                    'likely_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                    'allowed_paths' => ['app/Services/Ai/Cli'],
                    'strict_file_scope' => true,
                    'test_coverage' => ['php artisan test --filter=AtlasCliDevWorkflowServiceTest'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            '--task-id' => $task->id,
            '--workspace' => $this->workspace,
            '--claude-only' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('fair_claude_prompt_contract', data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.kind'));
        $this->assertContains(
            'Prompt contract has explicit acceptance criteria and forbidden fallback/providers.',
            (array) data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.acceptance_criteria'),
        );
        $this->assertContains(
            'Plan-only output exposes the fair metadata without invoking another provider.',
            (array) data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.acceptance_criteria'),
        );
        $this->assertSame(
            ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.file_scope.likely_files'),
        );
        $this->assertSame(
            ['app/Services/Ai/Cli'],
            data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.file_scope.allowed_paths'),
        );
        $this->assertTrue(data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.file_scope.strict'));
        $this->assertSame(
            ['php artisan test --filter=AtlasCliDevWorkflowServiceTest'],
            data_get($payload, 'dev_execution_plan.fair_mode_prompt_contract.deterministic_gates.validation_steps'),
        );
        $this->assertStringContainsString(
            'Prompt contract has explicit acceptance criteria and forbidden fallback/providers.',
            implode("\n", $payload['chat_command']),
        );
        $this->assertContains('atlas:ai:chat', $payload['chat_command']);
    }

    public function test_plan_only_can_load_engineering_contract_from_task_id(): void
    {
        $this->createMinimalTaskTable();

        $task = AtlasTask::query()->create([
            'title' => 'Implementar contrato de engenharia',
            'description' => 'O dev workflow deve receber uma tarefa Atlas estruturada.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Gerar prompt contratado para o provider.',
                    'acceptance_criteria' => ['Plan-only retorna engineering_contract.'],
                    'likely_files' => ['app/Console/Commands/AtlasCliDevCommand.php'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('atlas:cli:dev', [
            '--task-id' => $task->id,
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame($task->id, data_get($payload, 'dev_execution_plan.atlas_task.task_id'));
        $this->assertSame('Gerar prompt contratado para o provider.', data_get($payload, 'dev_execution_plan.engineering_contract.goal'));
        $this->assertStringStartsWith('eng_', (string) data_get($payload, 'dev_execution_plan.engineering_blueprint.blueprint_id'));
        $this->assertSame('ac_1', data_get($payload, 'dev_execution_plan.engineering_blueprint.acceptance_matrix.0.id'));
        $this->assertContains('deep_code_review', collect(data_get($payload, 'dev_execution_plan.engineering_blueprint.review_gates', []))->pluck('id')->all());
        $this->assertContains('engineering-blueprint', $payload['activated_skills']);
        $this->assertSame($task->id, data_get($payload, 'dev_execution_plan.operator_options.task_id'));
        $this->assertStringContainsString('Atlas Engineering Task Contract', implode("\n", $payload['chat_command']));
        $this->assertStringContainsString('Blueprint phases', implode("\n", $payload['chat_command']));
        $this->assertStringContainsString('Plan-only retorna engineering_contract.', implode("\n", $payload['chat_command']));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    private function interactiveCommand(array $options): array
    {
        $command = app(AtlasCliDevCommand::class);
        $input = new ArrayInput($options + [
            '--workspace' => $this->workspace,
            '--allow-write' => false,
            '--operator' => false,
            '--allow-unsandboxed' => false,
            '--dangerously-allow-all' => false,
            '--skill' => [],
        ]);
        $input->bind($command->getDefinition());

        $this->setCommandProperty($command, 'input', $input);
        $this->setCommandProperty($command, 'output', new BufferedOutput);

        $method = new ReflectionMethod(AtlasCliDevCommand::class, 'interactiveChatCommand');
        $method->setAccessible(true);

        return $method->invoke($command, $this->workspace);
    }

    private function setCommandProperty(AtlasCliDevCommand $command, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(Command::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($command, $value);
    }

    private function createMinimalTaskTable(): void
    {
        Schema::dropIfExists('atlas_tasks');

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
