<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutableRunner;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaMigrationExecutableRunnerTest extends TestCase
{
    private string $envPath;
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-loop-master-'.bin2hex(random_bytes(6)).'.env';
        $this->lockPath = sys_get_temp_dir().'/atlas-loop-schema-runner-'.bin2hex(random_bytes(6)).'.lock';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        if (is_file($this->lockPath)) {
            @unlink($this->lockPath);
        }
        parent::tearDown();
    }

    public function test_runner_executes_fake_step_and_returns_facts_only_structured_outcome(): void
    {
        $fs = new FakeExecutableRunnerFilesystem([
            '/virtual/schema.json' => '{"version":1}',
        ]);
        $busEvents = [];
        $runner = new AtlasLoopSchemaMigrationExecutableRunner(
            fs: $fs->ops(),
            loopBus: static function (array $event) use (&$busEvents): void {
                $busEvents[] = $event;
            },
            lockPath: $this->lockPath,
        );
        $step = new FakeExecutableMigrationStep('step-1', ['/virtual/schema.json']);

        $outcome = $runner->run($step);

        $this->assertSame('applied', $outcome['status']);
        $this->assertSame('step-1', $outcome['step_id']);
        $this->assertStringStartsWith('checkpoint-', $outcome['checkpoint_id']);
        $this->assertNotSame('', $outcome['applied_at']);
        $this->assertIsArray($outcome['facts']);
        $this->assertSame(1, $outcome['facts']['target_count']);
        $this->assertSame(['/virtual/schema.json'], $outcome['facts']['checkpoint_targets']);
        $this->assertSame('applied', $outcome['facts']['effect']);
        $this->assertSame(1, $step->applyCalls);
        $this->assertSame('{"version":1}', $step->receivedCheckpoint['targets']['/virtual/schema.json']['bytes']);
        $this->assertCount(1, $busEvents);
        $this->assertSame('loop_schema_migration_executed', $busEvents[0]['event']);

        foreach ($this->collectKeys($outcome) as $key) {
            $this->assertNotSame('score', $key);
        }
    }

    public function test_runner_fails_closed_when_master_switch_is_off_without_invoking_apply(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $fs = new FakeExecutableRunnerFilesystem([
            '/virtual/schema.json' => '{"version":1}',
        ]);
        $runner = new AtlasLoopSchemaMigrationExecutableRunner(
            fs: $fs->ops(),
            loopBus: null,
            lockPath: $this->lockPath,
        );
        $step = new FakeExecutableMigrationStep('step-master-off', ['/virtual/schema.json']);

        $outcome = $runner->run($step);

        $this->assertSame('refused_master_off', $outcome['status']);
        $this->assertSame('', $outcome['checkpoint_id']);
        $this->assertSame('step-master-off', $outcome['step_id']);
        $this->assertSame('', $outcome['applied_at']);
        $this->assertSame(false, $outcome['facts']['apply_called']);
        $this->assertSame(0, $step->applyCalls);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => in_array($op['op'], ['read', 'write'], true)));
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     * @return list<string>
     */
    private function collectKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                array_push($keys, ...$this->collectKeys($value));
            }
        }

        return $keys;
    }
}

final class FakeExecutableMigrationStep
{
    public int $applyCalls = 0;

    /**
     * @var array<string,mixed>
     */
    public array $receivedCheckpoint = [];

    /**
     * @param  list<string>  $targets
     */
    public function __construct(
        private readonly string $stepId,
        private readonly array $targets,
    ) {}

    public function stepId(): string
    {
        return $this->stepId;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     * @return array<string,mixed>
     */
    public function apply(array $checkpoint): array
    {
        $this->applyCalls++;
        $this->receivedCheckpoint = $checkpoint;

        return [
            'status' => 'applied',
            'applied_at' => '2026-06-24T06:00:00Z',
            'facts' => [
                'effect' => 'applied',
                'checkpoint_byte_images_captured' => true,
            ],
        ];
    }
}

final class FakeExecutableRunnerFilesystem
{
    /**
     * @param  array<string,string>  $files
     */
    public function __construct(
        public array $files = [],
    ) {}

    /**
     * @var list<array<string,string>>
     */
    public array $operations = [];

    /**
     * @return array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false,
     *   write: callable(string,string):int|false,
     *   mkdir: callable(string):bool
     * }
     */
    public function ops(): array
    {
        return [
            'is_file' => function (string $path): bool {
                $this->operations[] = ['op' => 'is_file', 'path' => $path];

                return array_key_exists($path, $this->files);
            },
            'read' => function (string $path): string|false {
                $this->operations[] = ['op' => 'read', 'path' => $path];

                return $this->files[$path] ?? false;
            },
            'write' => function (string $path, string $contents): int|false {
                $this->operations[] = ['op' => 'write', 'path' => $path];
                $this->files[$path] = $contents;

                return strlen($contents);
            },
            'mkdir' => function (string $path): bool {
                $this->operations[] = ['op' => 'mkdir', 'path' => $path];

                return true;
            },
        ];
    }
}
