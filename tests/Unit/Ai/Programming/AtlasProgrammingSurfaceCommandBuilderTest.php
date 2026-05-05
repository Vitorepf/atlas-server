<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasProgrammingSurfaceCommandBuilder;
use Tests\TestCase;

class AtlasProgrammingSurfaceCommandBuilderTest extends TestCase
{
    public function test_repair_arguments_mark_dev_run_as_repair(): void
    {
        $arguments = app(AtlasProgrammingSurfaceCommandBuilder::class)->repairDevArguments(
            workspace: '/tmp/work',
            provider: 'codex_cli',
            description: 'teste quebrado',
            maxIterations: 12,
            autoTest: true,
            allowWrite: true,
            json: true,
        );

        $this->assertSame(['Corrija: teste quebrado'], $arguments['task']);
        $this->assertSame('/tmp/work', $arguments['--workspace']);
        $this->assertSame('codex_cli', $arguments['--provider']);
        $this->assertSame('write', $arguments['--permission']);
        $this->assertTrue($arguments['--repair']);
        $this->assertSame('10', $arguments['--max-iterations']);
        $this->assertTrue($arguments['--auto-test']);
        $this->assertTrue($arguments['--allow-write']);
        $this->assertTrue($arguments['--json']);
    }

    public function test_resume_command_preserves_programming_contract_flags(): void
    {
        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);

        $command = app(AtlasProgrammingSurfaceCommandBuilder::class)->resumeDevCommand([
            'task' => 'continuar tarefa',
            'workspace' => '/tmp/work',
            'plan_id' => 'plan-1',
            'programming_profile' => 'forge',
            'model' => 'gpt-5.5',
        ], [
            'provider' => 'codex_cli',
            'programming_intent' => 'repair',
            'max_iterations' => 4,
            'allow_write' => true,
            'auto_test' => true,
            'permission' => 'write',
            'skills' => ['dev-quality-gate'],
        ], [
            'mode' => 'required',
            'refresh' => true,
            'budget_chars' => 6000,
        ]);

        $this->assertSame('/opt/homebrew/bin/php', $command[0]);
        $this->assertContains('atlas:cli:dev', $command);
        $this->assertContains('continuar tarefa', $command);
        $this->assertContains('--workspace=/tmp/work', $command);
        $this->assertContains('--resume=plan-1', $command);
        $this->assertContains('--provider=codex_cli', $command);
        $this->assertContains('--model=gpt-5.5', $command);
        $this->assertContains('--forge', $command);
        $this->assertContains('--repair', $command);
        $this->assertContains('--require-open-brain', $command);
        $this->assertContains('--open-brain-refresh', $command);
        $this->assertContains('--open-brain-budget=6000', $command);
        $this->assertContains('--permission=write', $command);
        $this->assertContains('--skill=dev-quality-gate', $command);
    }
}
