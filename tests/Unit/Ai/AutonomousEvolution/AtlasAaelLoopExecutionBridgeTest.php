<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasAaelLoopExecutionBridgeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-bridge-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    public function test_executes_metric_shaped_opportunities_and_defers_the_rest(): void
    {
        $fix = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge(
            new AtlasEvolutionLoopRunner(new AtlasEvolutionScenarioExplorer($fix, new AtlasEvolutionFrozenJudge)),
        );

        $opportunities = [
            [
                'objective' => 'fix greet',
                'opportunity_id' => 'op-1',
                'task' => [
                    'objective' => 'Make greet() return hello.',
                    'base_workspace' => $this->base,
                    'provider' => 'test_provider',
                    'allowed_files' => ['src/Subject.php'],
                    'validation_commands' => ['php tests/subject_test.php'],
                    'acceptance' => [
                        'commands' => ['php tests/subject_test.php'],
                        'allowed_globs' => ['src/**'],
                        'frozen_globs' => ['tests/**'],
                        'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
                    ],
                ],
            ],
            // a vague AAEL opportunity with NO metric-shaped task -> must be deferred
            ['objective' => 'improve Atlas somehow', 'opportunity_id' => 'op-2'],
        ];

        $result = $bridge->execute($opportunities, ['scenarios_per_task' => 1, 'propose_only' => true]);

        $this->assertSame(2, $result['opportunities_total']);
        $this->assertSame(1, $result['executed_tasks']);
        $this->assertSame(1, $result['deferred_count']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertSame(1, $result['loop_run']['proposals_certified_for_review']);
        $this->assertFalse($result['loop_run']['merged_to_main']);
        $this->assertStringContainsString('needs decomposition', $result['deferred'][0]['reason']);
        $this->assertSame('op-2', $result['deferred'][0]['opportunity_id']);
    }
}
