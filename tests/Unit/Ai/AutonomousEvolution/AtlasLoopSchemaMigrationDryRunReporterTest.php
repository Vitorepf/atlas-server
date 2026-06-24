<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationDryRunReporter;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaMigrationDryRunReporterTest extends TestCase
{
    public function test_reporter_computes_real_pre_and_post_hashes_for_two_targets(): void
    {
        $fs = new FakeDryRunFilesystem([
            '/virtual/a.json' => "alpha\nbeta\n",
            '/virtual/b.json' => "one\ntwo\n",
        ]);
        $reporter = new AtlasLoopSchemaMigrationDryRunReporter($fs->ops());
        $step = new FakeDryRunStep(
            'dry-step-1',
            ['/virtual/b.json', '/virtual/a.json'],
            [
                '/virtual/a.json' => "alpha\nbeta\nchanged\n",
                '/virtual/b.json' => "one\ntwo\n",
            ],
        );

        $report = $reporter->dryRun($step)->toArray();

        $this->assertSame('dry-step-1', $report['step_id']);
        $this->assertStringStartsWith('checkpoint-', $report['checkpoint_id']);
        $this->assertCount(2, $report['targets']);
        $this->assertSame(hash('sha256', "alpha\nbeta\n"), $report['targets'][0]['pre_sha256']);
        $this->assertSame(hash('sha256', "alpha\nbeta\nchanged\n"), $report['targets'][0]['post_sha256']);
        $this->assertNotSame($report['targets'][0]['pre_sha256'], $report['targets'][0]['post_sha256']);
        $this->assertSame(hash('sha256', "one\ntwo\n"), $report['targets'][1]['pre_sha256']);
        $this->assertSame(hash('sha256', "one\ntwo\n"), $report['targets'][1]['post_sha256']);
        $this->assertStringContainsString('--- /virtual/a.json', $report['targets'][0]['unified_diff']);

        foreach ($this->collectKeys($report) as $key) {
            $this->assertNotSame('score', $key);
        }
    }

    public function test_reporter_never_mutates_targets_and_reports_non_negative_byte_deltas(): void
    {
        $initialA = "before\n";
        $initialB = "same\n";
        $fs = new FakeDryRunFilesystem([
            '/virtual/a.json' => $initialA,
            '/virtual/b.json' => $initialB,
        ]);
        $beforeHashes = [
            '/virtual/a.json' => hash('sha256', $initialA),
            '/virtual/b.json' => hash('sha256', $initialB),
        ];
        $reporter = new AtlasLoopSchemaMigrationDryRunReporter($fs->ops());
        $step = new FakeDryRunStep(
            'dry-step-2',
            ['/virtual/a.json', '/virtual/b.json'],
            [
                '/virtual/a.json' => "before\nafter\n",
                '/virtual/b.json' => $initialB,
            ],
        );

        $report = $reporter->dryRun($step)->toArray();
        $afterHashes = [
            '/virtual/a.json' => hash('sha256', $fs->files['/virtual/a.json']),
            '/virtual/b.json' => hash('sha256', $fs->files['/virtual/b.json']),
        ];

        $this->assertSame($beforeHashes, $afterHashes);
        $this->assertIsInt($report['bytes_added']);
        $this->assertIsInt($report['bytes_removed']);
        $this->assertGreaterThanOrEqual(0, $report['bytes_added']);
        $this->assertGreaterThanOrEqual(0, $report['bytes_removed']);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => $op['op'] === 'write'));
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

final class FakeDryRunStep
{
    /**
     * @param  list<string>  $targets
     * @param  array<string,string>  $previewByPath
     */
    public function __construct(
        private readonly string $stepId,
        private readonly array $targets,
        private readonly array $previewByPath,
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
     * @return array<string,string>
     */
    public function renderPreview(array $checkpoint): array
    {
        return $this->previewByPath;
    }
}

final class FakeDryRunFilesystem
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
     *   read: callable(string):string|false
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
        ];
    }
}
