<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OwnerFlowPlanSliceCycleExecutorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_plan_slice_executor_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_slice_provider_fit_overrides_non_explicit_runner_default(): void
    {
        config()->set('atlas_dev.provider.default_provider', 'minimax_m27_cli');
        config()->set('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M3');
        config()->set('atlas.ai.providers.cursor_cli.model', 'composer-2.5-fast');

        $selection = $this->providerSelection(
            [
                'provider_fit' => [
                    'preferred_provider' => 'cursor_cli',
                    'preferred_model_family' => 'composer-2.5-fast',
                    'source' => 'atlas_decide',
                ],
            ],
            [
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M3',
                'provider_explicit' => false,
                'model_explicit' => false,
            ],
        );

        $this->assertSame('cursor_cli', $selection['provider']);
        $this->assertSame('composer-2.5-fast', $selection['model']);
        $this->assertSame('slice_provider_fit', $selection['source']);
    }

    public function test_explicit_operator_provider_override_wins_over_slice_provider_fit(): void
    {
        config()->set('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M3');

        $selection = $this->providerSelection(
            [
                'provider_fit' => [
                    'preferred_provider' => 'cursor_cli',
                    'preferred_model_family' => 'composer-2.5-fast',
                ],
            ],
            [
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M3',
                'provider_explicit' => true,
                'model_explicit' => false,
            ],
        );

        $this->assertSame('minimax_m27_cli', $selection['provider']);
        $this->assertSame('MiniMax-M3', $selection['model']);
        $this->assertSame('operator_explicit_provider', $selection['source']);
    }

    public function test_uses_loop_runner_branch_as_sandbox_base_ref(): void
    {
        $repo = $this->repo();
        $this->runProcess(['git', 'checkout', '-b', 'atlas/loop-runner/agentic-engineering-os-dev-forge'], $repo);

        $this->assertSame(
            'atlas/loop-runner/agentic-engineering-os-dev-forge',
            $this->sandboxBaseRef(['repo_root' => $repo], $repo),
        );
    }

    public function test_explicit_sandbox_base_ref_wins_over_current_branch(): void
    {
        $repo = $this->repo();
        $this->runProcess(['git', 'checkout', '-b', 'atlas/loop-runner/agentic-engineering-os-dev-forge'], $repo);

        $this->assertSame(
            'atlas/integration/agentic_engineering_os/main',
            $this->sandboxBaseRef(['sandbox_base_ref' => 'atlas/integration/agentic_engineering_os/main'], $repo),
        );
    }

    public function test_does_not_infer_main_as_special_sandbox_base_ref(): void
    {
        $repo = $this->repo();

        $this->assertSame('', $this->sandboxBaseRef(['repo_root' => $repo], $repo));
    }

    public function test_s265_scope_sanitizer_drops_illustrative_paths_before_provider(): void
    {
        $production = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ChangeSurfaceBreadthScorer.php';
        $test = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ChangeSurfaceBreadthScorerTest.php';

        $realScope = [
            $production,
            'app/Services/Ai/X.php',
            'tests/Unit/Ai/XTest.php',
            'app/Services/Ai/A.php',
            'app/Console/B.php',
            'app/Models/C.php',
        ];

        $allowed = $this->sanitizedAllowedFiles(array_merge($realScope, $this->acceptanceRepoPaths([
            'Paired test at '.$test,
            'Breadth examples mention app/Services/Ai/A.php, app/Console/B.php, app/Models/C.php and tests/Unit/Ai/XTest.php.',
        ], $realScope)));

        $this->assertSame([$production, $test], $allowed);
        $this->assertSame([$test], $this->sanitizedTestsRequired(['tests/Unit/Ai/XTest.php', $test], $allowed));
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function providerSelection(array $slice, array $context): array
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'providerSelection');
        $method->setAccessible(true);

        return $method->invoke($executor, $slice, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function sandboxBaseRef(array $context, string $repoRoot): string
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'sandboxBaseRef');
        $method->setAccessible(true);

        return (string) $method->invoke($executor, $context, $repoRoot);
    }

    /**
     * @param  list<string>  $acceptance
     * @param  list<string>  $realScope
     * @return list<string>
     */
    private function acceptanceRepoPaths(array $acceptance, array $realScope): array
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'extractAcceptanceRepoPaths');
        $method->setAccessible(true);

        return $method->invoke($executor, $acceptance, $realScope);
    }

    /**
     * @param  array<int,mixed>  $paths
     * @return list<string>
     */
    private function sanitizedAllowedFiles(array $paths): array
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'sanitizeAllowedFiles');
        $method->setAccessible(true);

        return $method->invoke($executor, $paths);
    }

    /**
     * @param  array<int,mixed>  $tests
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function sanitizedTestsRequired(array $tests, array $allowedFiles): array
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'sanitizeTestsRequired');
        $method->setAccessible(true);

        return $method->invoke($executor, $tests, $allowedFiles);
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo);
        $this->runProcess(['git', 'init'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/README.md', "Atlas plan slice fixture\n");
        $this->runProcess(['git', 'add', 'README.md'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $repo);

        return $repo;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());
    }
}
