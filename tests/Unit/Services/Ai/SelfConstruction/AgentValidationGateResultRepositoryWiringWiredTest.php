<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateResultRepository;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-efd56265d2e5 — prove AgentValidationGateResultRepository is wired into the live
 * `atlas:task:maestro-projection` flow (previously zero production callers).
 */
class AgentValidationGateResultRepositoryWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Singleton binding so the command-resolved instance is the SAME instance the test queries.
        app()->singleton(AgentValidationGateResultRepository::class);
        config()->set('atlas.loop.master_enabled', true);
        \App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch::$envPathOverride = null;
        $envFile = sys_get_temp_dir().'/atlas-anj-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        \App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch::$envPathOverride = $envFile;
    }

    protected function tearDown(): void
    {
        \App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_cli_projection_rate_stores_a_validation_gate_result(): void
    {
        $repo = app(AgentValidationGateResultRepository::class);
        self::assertSame(0, $repo->count(), 'fresh repository starts empty');

        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro-projection', [
            'verb' => 'rate',
            '--json' => true,
        ], $buf);
        self::assertSame(0, $exit);

        self::assertSame(1, $repo->count(), 'a rate call must store one validation result');
        $entries = $repo->all();
        self::assertNotEmpty($entries);
        $row = array_values($entries)[0];
        self::assertSame('rate', $row['verb']);
    }

    public function test_cli_projection_empty_stores_another_validation_gate_result(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('atlas:task:maestro-projection', ['verb' => 'rate', '--json' => true], new BufferedOutput());
        $kernel->call('atlas:task:maestro-projection', ['verb' => 'empty', '--json' => true], new BufferedOutput());

        $repo = app(AgentValidationGateResultRepository::class);
        self::assertGreaterThanOrEqual(2, $repo->count());
        $verbs = array_map(static fn (array $r): string => (string) ($r['verb'] ?? ''), $repo->all());
        self::assertContains('rate', $verbs);
        self::assertContains('empty', $verbs);
    }

    public function test_validation_gate_repository_public_api_is_exercised_by_the_new_call_path(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('atlas:task:maestro-projection', ['verb' => 'rate', '--json' => true], new BufferedOutput());

        $repo = app(AgentValidationGateResultRepository::class);
        // all/count/has/find/failed/digest from the API surface — all touched by the new path.
        self::assertGreaterThanOrEqual(1, $repo->count());
        $ids = $repo->ids();
        self::assertNotEmpty($ids);
        $first = (string) $ids[0];
        self::assertTrue($repo->has($first));
        self::assertNotNull($repo->find($first));
        self::assertIsArray($repo->failed());
        self::assertIsArray($repo->digest());
    }

    public function test_command_source_imports_and_invokes_the_repository(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasTaskMaestroProjectionCommand.php')
        );
        self::assertStringContainsString(AgentValidationGateResultRepository::class, $source);
        self::assertStringContainsString('$repo->store(', $source);
    }
}
