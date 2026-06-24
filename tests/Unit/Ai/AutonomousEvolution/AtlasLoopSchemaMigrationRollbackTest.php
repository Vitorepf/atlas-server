<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationRollback;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaMigrationRollbackTest extends TestCase
{
    public function test_rollback_restores_two_targets_to_original_bytes(): void
    {
        $originalA = "alpha\n";
        $originalB = "beta\n";
        $mutatedA = "alpha\nchanged\n";
        $mutatedB = "beta\nchanged\n";

        $fs = new FakeRollbackFilesystem([
            '/virtual/a.json' => $mutatedA,
            '/virtual/b.json' => $mutatedB,
        ]);
        $checkpoint = [
            'checkpoint_id' => 'cp-1',
            'targets' => [
                '/virtual/a.json' => [
                    'bytes' => $originalA,
                    'pre_sha256' => hash('sha256', $originalA),
                    'known_post_sha256s' => [hash('sha256', $mutatedA)],
                ],
                '/virtual/b.json' => [
                    'bytes' => $originalB,
                    'pre_sha256' => hash('sha256', $originalB),
                    'known_post_sha256s' => [hash('sha256', $mutatedB)],
                ],
            ],
        ];

        $rollback = new AtlasLoopSchemaMigrationRollback(
            checkpointResolver: static fn (string $checkpointId): ?array => $checkpointId === 'cp-1' ? $checkpoint : null,
            fs: $fs->ops(),
        );

        $receipt = $rollback->rollback('cp-1')->toArray();

        $this->assertCount(2, $receipt['restored_targets']);
        $this->assertSame([], $receipt['skipped_targets']);
        $this->assertNull($receipt['refused_reason']);
        $this->assertSame(hash('sha256', $originalA), hash('sha256', $fs->files['/virtual/a.json']));
        $this->assertSame(hash('sha256', $originalB), hash('sha256', $fs->files['/virtual/b.json']));
    }

    public function test_rollback_refuses_unknown_post_image_and_performs_no_writes(): void
    {
        $originalA = "alpha\n";
        $currentA = "alpha\nunknown\n";
        $fs = new FakeRollbackFilesystem([
            '/virtual/a.json' => $currentA,
        ]);
        $checkpoint = [
            'checkpoint_id' => 'cp-2',
            'targets' => [
                '/virtual/a.json' => [
                    'bytes' => $originalA,
                    'pre_sha256' => hash('sha256', $originalA),
                    'known_post_sha256s' => [hash('sha256', "alpha\nchanged\n")],
                ],
            ],
        ];

        $rollback = new AtlasLoopSchemaMigrationRollback(
            checkpointResolver: static fn (string $checkpointId): ?array => $checkpointId === 'cp-2' ? $checkpoint : null,
            fs: $fs->ops(),
        );

        $receipt = $rollback->rollback('cp-2')->toArray();

        $this->assertSame([], $receipt['restored_targets']);
        $this->assertSame([], $receipt['skipped_targets']);
        $this->assertSame('unknown_post_image', $receipt['refused_reason']);
        $this->assertSame($currentA, $fs->files['/virtual/a.json']);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => in_array($op['op'], ['write', 'rename'], true)));
    }
}

final class FakeRollbackFilesystem
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
     *   rename: callable(string,string):bool,
     *   unlink: callable(string):void
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
            'rename' => function (string $from, string $to): bool {
                $this->operations[] = ['op' => 'rename', 'path' => $from, 'target' => $to];
                $this->files[$to] = $this->files[$from];
                unset($this->files[$from]);

                return true;
            },
            'unlink' => function (string $path): void {
                $this->operations[] = ['op' => 'unlink', 'path' => $path];
                unset($this->files[$path]);
            },
        ];
    }
}
