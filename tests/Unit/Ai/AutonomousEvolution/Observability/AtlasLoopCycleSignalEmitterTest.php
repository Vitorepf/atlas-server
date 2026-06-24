<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopCycleSignalEmitterTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = app()->storagePath();
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);

        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_emit_appends_two_canonical_jsonl_lines_with_fixed_schema_order(): void
    {
        $storage = $this->storageRoot();
        app()->useStoragePath($storage);

        $emitter = new AtlasLoopCycleSignalEmitter;
        $emitter->emit(AtlasLoopCycleSignalEmitter::STAGE_DECISION, 'camp-1', 'cycle-1', ['z' => 2, 'a' => 1]);
        $emitter->emit(AtlasLoopCycleSignalEmitter::STAGE_LEARNING, 'camp-1', 'cycle-1', ['x' => 'ok']);

        $path = $this->signalPath($storage);
        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);

        $decodedFirst = json_decode($lines[0], true);
        $decodedSecond = json_decode($lines[1], true);

        $this->assertIsArray($decodedFirst);
        $this->assertIsArray($decodedSecond);
        $this->assertSame(
            ['schema_version', 'emitted_at', 'stage', 'campaign_id', 'cycle_id', 'payload'],
            array_keys($decodedFirst)
        );
        $this->assertSame(AtlasLoopCycleSignalEmitter::SCHEMA_VERSION, $decodedFirst['schema_version']);
        $this->assertSame(['a' => 1, 'z' => 2], $decodedFirst['payload']);
        $this->assertSame('learning', $decodedSecond['stage']);
    }

    public function test_emit_filters_non_scalar_payload_values_and_keeps_dropped_key_manifest(): void
    {
        $storage = $this->storageRoot();
        app()->useStoragePath($storage);

        $emitter = new AtlasLoopCycleSignalEmitter;
        $emitter->emit(AtlasLoopCycleSignalEmitter::STAGE_PROJECTION, 'camp-2', 'cycle-9', [
            'a' => 1,
            'b' => new \stdClass,
            'nested' => ['z' => 9, 'skip' => tmpfile()],
        ]);

        $lines = file($this->signalPath($storage), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $payload = json_decode((string) $lines[0], true)['payload'] ?? null;

        $this->assertSame(
            [
                'a' => 1,
                'nested' => ['z' => 9, '_dropped_non_scalar_keys' => ['skip']],
                '_dropped_non_scalar_keys' => ['b'],
            ],
            $payload
        );
    }

    public function test_emit_is_fail_open_when_storage_sink_is_unavailable(): void
    {
        app()->useStoragePath('/dev/null');

        $emitter = new AtlasLoopCycleSignalEmitter;
        $emitter->emit(AtlasLoopCycleSignalEmitter::STAGE_MERGE, 'camp-x', 'cycle-x', ['safe' => true]);

        $this->addToAssertionCount(1);
    }

    public function test_two_subprocesses_can_append_without_corrupting_the_jsonl_file(): void
    {
        $storage = $this->storageRoot();
        $path = $this->signalPath($storage);

        $script = sprintf(
            <<<'PHP'
require %s;
$app = require %s;
$app->useStoragePath(%s);
(new \App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter())->emit(%s, %s, %s, ['worker' => %s]);
PHP,
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
            var_export($storage, true),
            var_export(AtlasLoopCycleSignalEmitter::STAGE_CERTIFICATION, true),
            var_export('camp-concurrent', true),
            var_export('cycle-concurrent', true),
            '%s'
        );

        $p1 = new Process([PHP_BINARY, '-r', sprintf($script, var_export('one', true))], base_path());
        $p2 = new Process([PHP_BINARY, '-r', sprintf($script, var_export('two', true))], base_path());
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $this->assertTrue($p1->isSuccessful(), $p1->getErrorOutput());
        $this->assertTrue($p2->isSuccessful(), $p2->getErrorOutput());

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertSame('cycle-concurrent', $decoded['cycle_id'] ?? null);
            $this->assertSame('certification', $decoded['stage'] ?? null);
            $this->assertContains($decoded['payload']['worker'] ?? null, ['one', 'two']);
        }
    }

    private function storageRoot(): string
    {
        $root = sys_get_temp_dir().'/atlas-loop-cycle-signal-'.bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        $this->paths[] = $root;

        return $root;
    }

    private function signalPath(string $storage): string
    {
        return $storage.'/app/atlas-loop/signals/'.gmdate('Y-m-d').'.jsonl';
    }
}
