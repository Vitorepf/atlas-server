<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointWriter;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AtlasLoopCycleCheckpointWriterTest extends TestCase
{
    public function test_record_phase_publishes_via_rename_and_pre_rename_failure_leaves_visible_file_untouched(): void
    {
        $fs = new FakeCheckpointFilesystem;
        $writer = new AtlasLoopCycleCheckpointWriter(
            '/virtual/checkpoints',
            ['selected', 'planned', 'executed'],
            $fs->ops(),
        );

        $first = $writer->recordPhase('cycle-1', 'selected', $this->facts('base-a', null, ['r-1'], ['claim-1']));
        $path = '/virtual/checkpoints/cycle-1.json';

        $this->assertArrayHasKey($path, $fs->files);
        $this->assertSame('rename', $fs->operations[array_key_last($fs->operations)]['op']);
        $this->assertSame(['selected'], array_column($this->recordsAt($fs, $path), 'phase'));
        $this->assertSame(
            hash('sha256', CanonicalJson::encodeWithout($first, 'content_hash')),
            $first['content_hash'],
        );

        $visibleBeforeFailure = $fs->files[$path];
        $fs->failRename = true;

        try {
            $writer->recordPhase('cycle-1', 'planned', $this->facts('base-b', 'merge-b', ['r-2'], ['claim-2']));
            $this->fail('Expected rename failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to publish checkpoint file', $e->getMessage());
        }

        $this->assertSame($visibleBeforeFailure, $fs->files[$path], 'visible file must remain old content until rename succeeds');
        $this->assertSame(['selected'], array_column($this->recordsAt($fs, $path), 'phase'));
        $this->assertNotEmpty($fs->tempWrites);
        foreach (array_keys($fs->tempWrites) as $tempPath) {
            $this->assertStringContainsString('.tmp.', $tempPath);
            $this->assertArrayNotHasKey($tempPath, $fs->files, 'temp file must not stay visible after failed publish');
        }
    }

    public function test_record_phase_rejects_out_of_order_phase_without_partial_write(): void
    {
        $fs = new FakeCheckpointFilesystem;
        $writer = new AtlasLoopCycleCheckpointWriter(
            '/virtual/checkpoints',
            ['selected', 'planned', 'executed'],
            $fs->ops(),
        );

        $writer->recordPhase('cycle-2', 'selected', $this->facts('base-a', null, ['r-1'], ['claim-1']));
        $path = '/virtual/checkpoints/cycle-2.json';
        $visibleBefore = $fs->files[$path];
        $operationCount = count($fs->operations);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Out-of-order phase');

        try {
            $writer->recordPhase('cycle-2', 'executed', $this->facts('base-c', 'merge-c', ['r-3'], ['claim-3']));
        } finally {
            $this->assertSame($visibleBefore, $fs->files[$path], 'rejected phase must not alter visible file');
            $newOperations = array_slice($fs->operations, $operationCount);
            $this->assertSame(
                ['ensure_directory', 'is_file', 'read'],
                array_column($newOperations, 'op'),
                'rejected phase may inspect current state but must not attempt temp write or rename',
            );
            $this->assertSame([1], array_column($this->recordsAt($fs, $path), 'seq'));
        }
    }

    public function test_record_phase_includes_required_fields_with_canonical_content_hash_and_monotonic_seq(): void
    {
        $dir = sys_get_temp_dir().'/atlas-reentry-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);

        try {
            $writer = new AtlasLoopCycleCheckpointWriter($dir, ['selected', 'planned', 'executed']);

            $first = $writer->recordPhase('cycle-3', 'selected', $this->facts('base-a', null, ['r-1', 'r-2'], ['claim-1']));
            $second = $writer->recordPhase('cycle-3', 'planned', $this->facts('base-b', 'merge-b', ['r-3'], ['claim-2', 'claim-3']));

            $this->assertSame(1, $first['seq']);
            $this->assertSame(2, $second['seq']);
            $this->assertSame('cycle-3', $second['cycle_id']);
            $this->assertSame('planned', $second['phase']);
            $this->assertSame('base-b', $second['commit_sha_base']);
            $this->assertSame('merge-b', $second['merged_sha']);
            $this->assertSame(['r-3'], $second['emitted_receipt_ids']);
            $this->assertSame(['claim-2', 'claim-3'], $second['held_task_claim_ids']);
            $this->assertSame(
                hash('sha256', CanonicalJson::encodeWithout($second, 'content_hash')),
                $second['content_hash'],
            );

            $path = $dir.'/cycle-3.json';
            $stored = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                CanonicalJson::canonicalize([$first, $second]),
                CanonicalJson::canonicalize($stored['records']),
            );
        } finally {
            foreach ((array) glob($dir.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
    }

    /**
     * @return array{commit_sha_base:string,merged_sha:?string,emitted_receipt_ids:list<string>,held_task_claim_ids:list<string>}
     */
    private function facts(
        string $commitShaBase,
        ?string $mergedSha,
        array $emittedReceiptIds,
        array $heldTaskClaimIds,
    ): array {
        return [
            'commit_sha_base' => $commitShaBase,
            'merged_sha' => $mergedSha,
            'emitted_receipt_ids' => $emittedReceiptIds,
            'held_task_claim_ids' => $heldTaskClaimIds,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordsAt(FakeCheckpointFilesystem $fs, string $path): array
    {
        $decoded = json_decode($fs->files[$path] ?? '[]', true);

        return is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
    }
}

final class FakeCheckpointFilesystem
{
    /**
     * @var array<string, string>
     */
    public array $files = [];

    /**
     * @var array<int, array<string, string>>
     */
    public array $operations = [];

    /**
     * @var array<string, string>
     */
    public array $tempWrites = [];

    public bool $failRename = false;

    /**
     * @return array{
     *     ensure_directory: callable(string):void,
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     sync: callable(string):void,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
     * }
     */
    public function ops(): array
    {
        return [
            'ensure_directory' => function (string $dir): void {
                $this->operations[] = ['op' => 'ensure_directory', 'path' => $dir];
            },
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
                $this->tempWrites[$path] = $contents;

                return strlen($contents);
            },
            'sync' => function (string $path): void {
                $this->operations[] = ['op' => 'sync', 'path' => $path];
            },
            'rename' => function (string $from, string $to): bool {
                $this->operations[] = ['op' => 'rename', 'path' => $from, 'target' => $to];
                if ($this->failRename) {
                    return false;
                }

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
