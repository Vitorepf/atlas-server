<?php

namespace Tests\Feature\Ai\Skills;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DevQualityGateSkillTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-dev-quality-gate-skill-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/app');
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/app/Foo.php', "<?php\n\nclass Foo {}\n");
        File::put($this->workspace.'/composer.json', json_encode(['scripts' => ['test' => 'php -r "exit(0);"']], JSON_PRETTY_PRINT));
        (new Process(['git', 'init'], $this->workspace))->run();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_validate_plan_script_returns_validation_and_quality_json(): void
    {
        $planPath = $this->workspace.'/plan.json';
        File::put($planPath, json_encode([
            'intent' => 'validar fluxo plan validate execute',
            'files_to_modify' => ['app/Foo.php'],
            'tests' => ['composer test'],
            'side_effects' => [],
            'pre_existing_changes' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process([
            base_path('skills/dev-quality-gate/scripts/validate-plan.sh'),
            $planPath,
        ], $this->workspace, [
            'ATLAS_SERVER_ROOT' => base_path(),
            'ATLAS_WORKSPACE' => $this->workspace,
            'ATLAS_AI_TOOL_ALLOWED_ROOTS' => $this->workspace,
        ]);
        $process->setTimeout(30);
        $process->run();

        $payload = json_decode($process->getOutput(), true);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['validation']['ok']);
        $this->assertSame($this->workspace, $payload['validation']['workspace']);
        $this->assertSame('needs_review', $payload['quality']['status']);
    }

    public function test_validate_plan_script_rejects_path_outside_workspace(): void
    {
        $planPath = $this->workspace.'/plan-outside.json';
        File::put($planPath, json_encode([
            'intent' => 'tentar escapar workspace',
            'files_to_modify' => ['/etc/hosts'],
            'tests' => ['composer test'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process([
            base_path('skills/dev-quality-gate/scripts/validate-plan.sh'),
            $planPath,
        ], $this->workspace, [
            'ATLAS_SERVER_ROOT' => base_path(),
            'ATLAS_WORKSPACE' => $this->workspace,
            'ATLAS_AI_TOOL_ALLOWED_ROOTS' => $this->workspace,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('path_outside_workspace', $process->getErrorOutput());
    }
}
