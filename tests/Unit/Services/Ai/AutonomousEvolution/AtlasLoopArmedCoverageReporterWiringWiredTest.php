<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopArmedCoverageReporter;
use Closure;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-dc79d8075f7d — prove AtlasLoopArmedCoverageReporter is wired into the live
 * `atlas:aael armed-coverage` CLI action (previously an orphan with zero production callers).
 */
class AtlasLoopArmedCoverageReporterWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.loop.armed_coverage_reporter_enabled', true);

        // The CLI's handle() resolves AtlasAaelLoopExecutionBridge eagerly; provide a stub so
        // the action='armed-coverage' branch can be reached without bootstrapping the runner.
        app()->instance(AtlasAaelLoopExecutionBridge::class, new AtlasAaelLoopExecutionBridge(new \stdClass));

        // Stub the reporter with a deterministic primitive set so the CLI exercise is hermetic.
        app()->instance(
            AtlasLoopArmedCoverageReporter::class,
            new AtlasLoopArmedCoverageReporter(
                registry: null,
                primitivesResolver: Closure::fromCallable(static fn (): array => [
                    [
                        'primitive_id' => 'demo-primitive',
                        'file_path' => 'app/Services/Demo/Foo.php',
                        'intended_consumer_paths' => ['app/Services/Demo/Bar.php'],
                    ],
                ]),
                repoRoot: sys_get_temp_dir(),
            ),
        );
    }

    public function test_cli_armed_coverage_action_invokes_the_reporter(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:aael', [
            'action' => 'armed-coverage',
            '--json' => true,
        ], $buf);

        self::assertSame(0, $exit);
        $output = trim($buf->fetch());
        // The CLI prints a header + JSON payload; locate the JSON document.
        $jsonStart = strpos($output, '{');
        self::assertNotFalse($jsonStart);
        $payload = json_decode(substr($output, (int) $jsonStart), true);
        self::assertIsArray($payload);
        self::assertSame('atlas.loop.armed_coverage_report.v1', $payload['schema_version']);
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('coverage', $payload);
        self::assertArrayHasKey('demo-primitive', $payload['coverage']);
    }

    public function test_reporter_returns_no_op_when_master_flag_disabled(): void
    {
        config()->set('atlas.loop.armed_coverage_reporter_enabled', false);
        $reporter = app(AtlasLoopArmedCoverageReporter::class);
        self::assertSame([], $reporter->report());
    }

    public function test_command_source_imports_and_invokes_the_reporter(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasAaelCommand.php')
        );
        self::assertStringContainsString(AtlasLoopArmedCoverageReporter::class, $source);
        self::assertStringContainsString('$reporter->report()', $source);
        self::assertStringContainsString("'armed-coverage'", $source);
    }
}
