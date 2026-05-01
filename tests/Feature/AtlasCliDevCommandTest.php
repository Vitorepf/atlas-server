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
        $this->assertContains('dev-quality-gate', $payload['activated_skills']);
        $this->assertContains('--skill=dev-quality-gate', $payload['chat_command']);
        $this->assertSame('passed', data_get($payload, 'dev_execution_plan.quality_gate_policy.required_final_status'));
        $this->assertSame('plan_validate_execute', data_get($payload, 'dev_execution_plan.quality_gate_policy.procedure'));
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

        $this->assertContains('atlas:ai:chat', $command);
        $this->assertContains('--dev', $command);
        $this->assertContains('--cockpit', $command);
        $this->assertContains('--no-skill-prompt', $command);
        $this->assertContains('--provider=codex_cli', $command);
        $this->assertContains('--permission=danger', $command);
        $this->assertContains('--dangerously-allow-all', $command);
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
