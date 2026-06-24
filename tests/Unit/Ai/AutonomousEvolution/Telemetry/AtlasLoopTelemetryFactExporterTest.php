<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactExporter;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopTelemetryFactExporterTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_append_is_append_only_and_preserves_prior_bytes_and_ordering(): void
    {
        $path = $this->tmpPath('append-only');
        $exporter = new AtlasLoopTelemetryFactExporter;

        $this->assertTrue($exporter->append($path, $this->fact('cycle-1', 'claim')));
        $before = (string) file_get_contents($path);

        $this->assertTrue($exporter->append($path, $this->fact('cycle-1', 'lease')));
        $after = (string) file_get_contents($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith($before, $after, 'append() never rewrites prior bytes');
        $this->assertSame('claim', json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['kind']);
        $this->assertSame('lease', json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['kind']);
    }

    public function test_forbidden_key_is_rejected_and_file_stays_unchanged(): void
    {
        $path = $this->tmpPath('forbidden');
        $exporter = new AtlasLoopTelemetryFactExporter;

        $this->assertTrue($exporter->append($path, $this->fact('cycle-1', 'claim')));
        $before = (string) file_get_contents($path);
        $sizeBefore = filesize($path);

        $rejected = $exporter->append($path, [
            'cycle_id' => 'cycle-2',
            'kind' => 'report',
            'occurred_at_iso' => '2026-06-24T00:00:02+00:00',
            'scope' => 'loop',
            'schema_version' => 'atlas.loop.telemetry.fact.v1',
            'payload' => ['score' => 99],
        ]);

        $this->assertFalse($rejected);
        $this->assertSame($before, (string) file_get_contents($path));
        $this->assertSame($sizeBefore, filesize($path));
    }

    public function test_existing_file_is_not_truncated_and_open_mode_is_append_binary(): void
    {
        $path = $this->tmpPath('existing');
        file_put_contents($path, "{\"seed\":true}\n");
        $before = (string) file_get_contents($path);
        $exporter = new AtlasLoopTelemetryFactExporter;

        $this->assertTrue($exporter->append($path, $this->fact('cycle-9', 'merge')));

        $after = (string) file_get_contents($path);
        $this->assertStringStartsWith($before, $after);

        $source = (string) file_get_contents((new ReflectionClass(AtlasLoopTelemetryFactExporter::class))->getFileName());
        $this->assertStringContainsString("fopen(\$path, 'ab')", $source);
    }

    public function test_parallel_appends_produce_complete_jsonl_lines_without_byte_interleaving(): void
    {
        $path = $this->tmpPath('parallel');
        $classFile = realpath(base_path('app/Services/Ai/AutonomousEvolution/Telemetry/AtlasLoopTelemetryFactExporter.php'));
        $factA = base64_encode(json_encode($this->fact('cycle-a', 'serve'), JSON_THROW_ON_ERROR));
        $factB = base64_encode(json_encode($this->fact('cycle-b', 'report'), JSON_THROW_ON_ERROR));

        $script = <<<'PHP'
require 'vendor/autoload.php';
require $argv[1];
$fact = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
$exporter = new App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactExporter;
exit($exporter->append($argv[2], $fact) ? 0 : 1);
PHP;

        $a = new Process(['/opt/homebrew/bin/php', '-r', $script, $classFile, $path, $factA], base_path());
        $b = new Process(['/opt/homebrew/bin/php', '-r', $script, $classFile, $path, $factB], base_path());
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();

        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput().$a->getOutput());
        $this->assertTrue($b->isSuccessful(), $b->getErrorOutput().$b->getOutput());

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $lines);
        $decoded = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines
        );
        $this->assertSame(['cycle-a', 'cycle-b'], array_values(array_map(static fn (array $row): string => $row['cycle_id'], $decoded)));
    }

    /**
     * @return array<string, mixed>
     */
    private function fact(string $cycleId, string $kind): array
    {
        return [
            'cycle_id' => $cycleId,
            'kind' => $kind,
            'occurred_at_iso' => '2026-06-24T00:00:01+00:00',
            'scope' => 'loop',
            'schema_version' => 'atlas.loop.telemetry.fact.v1',
            'payload' => ['scope' => 'loop', 'kind' => $kind],
        ];
    }

    private function tmpPath(string $slug): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-telemetry-exporter-'.$slug.'-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->paths[] = $path;

        return $path;
    }
}
